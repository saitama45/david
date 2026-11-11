<script setup>
import { computed, reactive, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";
import AutoComplete from "primevue/autocomplete";
import { MIN_SEARCH_CHARS, SEARCH_DELAY_MS } from "@/lib/searchRules";

// BOM Change History: who changed which BOM line or POS item, when, each field's old and
// new value, and whether it came from a form or an Excel import (and which file).
const props = defineProps({
    changes: { type: Object, required: true },
    filters: { type: Object, required: true },
    changedBy: { type: Object, default: null },
    options: { type: Object, required: true },
});

const form = reactive({
    pos_code: props.filters.pos_code ?? "",
    item_code: props.filters.item_code ?? "",
    date_from: props.filters.date_from ?? "",
    date_to: props.filters.date_to ?? "",
    source: props.filters.source || null,
    action: props.filters.action || null,
    subject: props.filters.subject || null,
});
const user = ref(props.changedBy);

const params = computed(() => {
    const values = { ...form, changed_by: user.value?.id ?? "" };
    return Object.fromEntries(Object.entries(values).filter(([, value]) => value !== null && value !== ""));
});

const apply = () => {
    router.get(route("pos-bom.history"), params.value, { preserveState: true, preserveScroll: true, replace: true });
};

// Typed filters wait for a pause in typing; the pickers and dates apply at once.
let typingTimer = null;
watch(() => [form.pos_code, form.item_code], () => {
    clearTimeout(typingTimer);
    typingTimer = setTimeout(apply, SEARCH_DELAY_MS);
});
watch(() => [form.date_from, form.date_to, form.source, form.action, form.subject], apply);

const exportRoute = computed(() => route("pos-bom.history.export", params.value));

const clearFilters = () => {
    Object.assign(form, { pos_code: "", item_code: "", date_from: "", date_to: "", source: null, action: null, subject: null });
    user.value = null;
    apply();
};

// Changed By: the users are in the database, so it searches the server from MIN_SEARCH_CHARS characters.
const userSuggestions = ref([]);
const typedUser = ref("");
const searchingUsers = ref(false);
let latestUserRequest = 0;

const searchUsers = async ({ query }) => {
    typedUser.value = query ?? "";
    const term = typedUser.value.trim();
    const request = ++latestUserRequest;

    if (!term) {
        userSuggestions.value = [];
        return;
    }

    searchingUsers.value = true;
    try {
        const { data } = await axios.get(route("pos-bom.history.users"), { params: { q: term } });
        // A slower answer to an earlier keystroke must not replace a newer one.
        if (request === latestUserRequest) userSuggestions.value = data;
    } catch (error) {
        if (request === latestUserRequest) userSuggestions.value = [];
    } finally {
        if (request === latestUserRequest) searchingUsers.value = false;
    }
};

const userEmptyMessage = computed(() => (typedUser.value.trim().length < MIN_SEARCH_CHARS
    ? `Type at least ${MIN_SEARCH_CHARS} characters to see matches.`
    : "Nobody by that name has changed a BOM line or POS item."));

const chooseUser = (value) => {
    if (value && typeof value === "object") {
        user.value = value;
        apply();
    } else if (!value && user.value) {
        user.value = null;
        apply();
    }
};

const actionClass = (key) => ({
    created: "bg-green-100 text-green-800",
    updated: "bg-blue-100 text-blue-800",
    deleted: "bg-red-100 text-red-800",
}[key] ?? "bg-gray-100 text-gray-800");

const show = (value) => (value === null || value === undefined || value === "" ? "(blank)" : value);
</script>

<template>
    <Layout heading="BOM Change History" :hasExcelDownload="true" :exportRoute="exportRoute">
        <template #header-actions>
            <Button variant="outline" @click="router.get(route('pos-bom.index'))">Back to BOM List</Button>
        </template>

        <p class="mb-4 text-sm text-gray-600">
            Every change to a BOM line or a POS item: who made it, when, what each field was before and after,
            and whether it came from a form or an Excel import. Past sales and wastage keep the recipe they used,
            so a change here never alters their figures.
        </p>

        <div class="mb-4 grid grid-cols-1 gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <Label>POS Code</Label>
                <Input v-model="form.pos_code" placeholder="Part of the POS code" />
            </div>
            <div>
                <Label>Item Code</Label>
                <Input v-model="form.item_code" placeholder="Part of the item code" />
            </div>
            <div>
                <Label>From</Label>
                <Input v-model="form.date_from" type="date" />
            </div>
            <div>
                <Label>To</Label>
                <Input v-model="form.date_to" type="date" />
            </div>
            <div>
                <Label>Changed By</Label>
                <AutoComplete
                    :modelValue="user"
                    :suggestions="userSuggestions"
                    optionLabel="name"
                    :minLength="1"
                    :delay="SEARCH_DELAY_MS"
                    :loading="searchingUsers"
                    forceSelection
                    showEmptyMessage
                    :emptySearchMessage="userEmptyMessage"
                    :placeholder="`Anyone - type ${MIN_SEARCH_CHARS}+ characters of a name`"
                    fluid
                    @complete="searchUsers"
                    @update:modelValue="chooseUser"
                    @clear="typedUser = ''"
                >
                    <template #option="{ option }">
                        <div class="flex flex-col">
                            <span class="font-medium">{{ option.name }}</span>
                            <span class="text-xs text-gray-500">{{ option.email }}</span>
                        </div>
                    </template>
                </AutoComplete>
            </div>
            <div>
                <Label>Type</Label>
                <Select v-model="form.subject" :options="options.subjects" optionLabel="label" optionValue="value" placeholder="BOM lines and POS items" showClear fluid />
            </div>
            <div>
                <Label>Action</Label>
                <Select v-model="form.action" :options="options.actions" optionLabel="label" optionValue="value" placeholder="Any action" showClear fluid />
            </div>
            <div>
                <Label>Source</Label>
                <Select v-model="form.source" :options="options.sources" optionLabel="label" optionValue="value" placeholder="Any source" showClear fluid />
            </div>
            <div class="sm:col-span-2 lg:col-span-4 flex justify-end">
                <Button variant="outline" size="sm" @click="clearFilters">Clear filters</Button>
            </div>
        </div>

        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Date Changed</TH>
                    <TH>Changed By</TH>
                    <TH>Type</TH>
                    <TH>POS Code</TH>
                    <TH>Item / BOM UOM</TH>
                    <TH>Action</TH>
                    <TH>What Changed (before → after)</TH>
                    <TH>Source</TH>
                </TableHead>
                <TableBody>
                    <tr v-if="changes.data.length === 0">
                        <TD colspan="8" class="text-center text-gray-500">No changes match these filters.</TD>
                    </tr>
                    <tr v-for="change in changes.data" :key="change.id" class="align-top">
                        <TD class="whitespace-nowrap">{{ change.changed_at }}</TD>
                        <TD>{{ change.changed_by }}</TD>
                        <TD>{{ change.subject }}</TD>
                        <TD>{{ change.pos_code }}</TD>
                        <TD>
                            <span v-if="change.item_code">{{ change.item_code }} · {{ change.bom_uom }}</span>
                            <span v-if="change.assembly" class="block text-xs text-gray-500">Assembly: {{ change.assembly }}</span>
                        </TD>
                        <TD>
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="actionClass(change.action_key)">{{ change.action }}</span>
                        </TD>
                        <TD>
                            <ul class="space-y-0.5 text-sm">
                                <li v-for="field in change.changes" :key="field.field">
                                    <!-- The space stays inside the label: Vue drops a space between tags on new lines. -->
                                    <span class="font-medium">{{ field.field }}: </span>
                                    <template v-if="change.action_key === 'updated'">
                                        <span class="text-red-700 line-through">{{ show(field.old) }}</span> → <span class="text-green-700">{{ show(field.new) }}</span>
                                    </template>
                                    <template v-else>{{ show(change.action_key === 'deleted' ? field.old : field.new) }}</template>
                                </li>
                            </ul>
                        </TD>
                        <TD>
                            {{ change.source }}
                            <span v-if="change.source_file" class="block text-xs text-gray-500">{{ change.source_file }}</span>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <p v-if="changes.data.length === 0" class="p-4 text-center text-sm text-gray-500">No changes match these filters.</p>
                <MobileTableRow v-for="change in changes.data" :key="change.id">
                    <MobileTableHeading :title="`${change.action} ${change.subject}: ${change.pos_code ?? ''}`" />
                    <LabelXS>{{ change.changed_at }} by {{ change.changed_by }}</LabelXS>
                    <LabelXS v-if="change.item_code">Item: {{ change.item_code }} · {{ change.bom_uom }}</LabelXS>
                    <LabelXS v-for="field in change.changes" :key="field.field">
                        {{ field.field }}: {{ change.action_key === 'updated'
                            ? `${show(field.old)} → ${show(field.new)}`
                            : show(change.action_key === 'deleted' ? field.old : field.new) }}
                    </LabelXS>
                    <LabelXS>Source: {{ change.source }}<template v-if="change.source_file"> ({{ change.source_file }})</template></LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="changes" />
        </TableContainer>
    </Layout>
</template>
