<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { ref, watch } from "vue";
import { throttle } from "lodash";

const props = defineProps({
    types: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const toast = useToast();
const search = ref(props.filters.search || "");

watch(
    search,
    throttle((value) => {
        router.get(route("sap-item-types.index"), { search: value }, { preserveState: true, replace: true });
    }, 500)
);

// One dialog for both: editingId null means "create".
const isDialogVisible = ref(false);
const editingId = ref(null);
const form = useForm({ name: "", is_active: 1 });

const statuses = [
    { label: "Active", value: 1 },
    { label: "Inactive", value: 0 },
];

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    isDialogVisible.value = true;
};

const openEdit = (type) => {
    editingId.value = type.id;
    form.clearErrors();
    form.name = type.name;
    form.is_active = type.is_active ? 1 : 0;
    isDialogVisible.value = true;
};

const save = () => {
    const done = {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({ severity: "success", summary: "Saved", detail: "Item type saved.", life: 3000 });
            isDialogVisible.value = false;
        },
    };
    editingId.value
        ? form.put(route("sap-item-types.update", editingId.value), done)
        : form.post(route("sap-item-types.store"), done);
};
</script>

<template>
    <Layout
        heading="SAP Item Types"
        :hasButton="true"
        buttonName="Create New Type"
        :handleClick="openCreate"
    >
        <p class="mb-4 text-sm text-gray-600">
            The types an SAP item code can be classified as. A type in use cannot be deleted - deactivate it
            instead, and items keep it until someone changes them.
            <a :href="route('sapitems.index')" class="font-semibold underline">Back to SAP Masterlist</a>
        </p>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input class="pl-10" v-model="search" placeholder="Search..." />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Name</TH>
                    <TH>Item Codes</TH>
                    <TH>Status</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="type in types.data" :key="type.id">
                        <TD>{{ type.name }}</TD>
                        <TD>{{ type.item_count }}</TD>
                        <TD>
                            <Badge :class="type.is_active ? 'bg-green-500 text-white' : 'bg-gray-400 text-white'">
                                {{ type.is_active ? "Active" : "Inactive" }}
                            </Badge>
                        </TD>
                        <TD>
                            <EditButton @click="openEdit(type)" />
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="type in types.data" :key="type.id">
                    <MobileTableHeading :title="type.name">
                        <EditButton @click="openEdit(type)" />
                    </MobileTableHeading>
                    <LabelXS>Item codes: {{ type.item_count }}</LabelXS>
                    <LabelXS>Status: {{ type.is_active ? "Active" : "Inactive" }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="types" />
        </TableContainer>

        <Dialog v-model:open="isDialogVisible">
            <DialogContent class="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{{ editingId ? "Edit Item Type" : "Create Item Type" }}</DialogTitle>
                    <DialogDescription>Names are saved in capitals, e.g. OPERATING SUPPLIES.</DialogDescription>
                </DialogHeader>
                <div class="space-y-5">
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs">Name</Label>
                        <Input v-model="form.name" />
                        <FormError>{{ form.errors.name }}</FormError>
                    </div>
                    <div v-if="editingId" class="flex flex-col space-y-1">
                        <Label class="text-xs">Status</Label>
                        <Select v-model="form.is_active" :options="statuses" optionLabel="label" optionValue="value" />
                        <span class="text-xs text-gray-500">
                            An inactive type is hidden from new assignments; items already carrying it keep it.
                        </span>
                        <FormError>{{ form.errors.is_active }}</FormError>
                    </div>
                    <div class="flex justify-end">
                        <Button @click="save" :disabled="form.processing">Save</Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
