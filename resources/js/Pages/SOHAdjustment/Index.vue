<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage, router, useForm } from "@inertiajs/vue3";

import { throttle, update } from "lodash";
import { ref, computed } from "vue";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";

const confirm = useConfirm();
const { toast } = useToast();
const { items, branches } = defineProps({
    items: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
});

const { options: branchesOptions } = useSelectOptions(branches);

const branchId = ref(
    usePage().props.filters.branchId || branchesOptions.value[0].value
);

let search = ref(usePage().props.filters.search);

watch(branchId, (newValue) => {
    selectedItems.value = [];
    router.get(
        route("soh-adjustment.index"),
        {
            branchId: newValue,
            search: search.value,
            page: 1,
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        }
    );
});

const approveSelectedItems = () => {
    const form = useForm({
        selectedItems: selectedItems.value,
        branchId: branchId.value,
    });

    form.post(route("soh-adjustment.approve-selected-items"), {
        onSuccess: (response) => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Approved Successfully.",
                life: 5000,
            });
            selectedItems.value = [];
        },
        onError: (error) => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occured while trying to approve.",
                life: 5000,
            });
        },
    });
};

watch(
    search,
    throttle(function (value) {
        router.get(
            route("soh-adjustment.index"),
            {
                search: value,
                branchId: branchId.value,
                page: 1,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

const selectedItems = ref([]);

// Get all item IDs from the current items list
const allItemIds = computed(() => {
    return items.data.map((item) => item.id);
});

// Check if all items are selected
const allSelected = computed({
    get: () => {
        return (
            allItemIds.value.length > 0 &&
            selectedItems.value.length === allItemIds.value.length
        );
    },
    set: (value) => {
        selectedItems.value = value ? [...allItemIds.value] : [];
    },
});
</script>
<template>
    <Layout heading="SOH Adjustments">
        <TableContainer>
            <DivFlexCenter class="justify-between sm:flex-row flex-col gap-3">
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar>
                <DivFlexCenter class="gap-3">
                    <Select
                        filter
                        class="min-w-72"
                        placeholder="Select a Supplier"
                        :options="branchesOptions"
                        optionLabel="label"
                        optionValue="value"
                        v-model="branchId"
                    >
                    </Select>
                    <Button
                        @click="approveSelectedItems"
                        v-if="selectedItems.length > 0"
                        >Approve All Selected Items</Button
                    >
                </DivFlexCenter>
            </DivFlexCenter>
            <Table>
                <TableHead>
                    <TH>
                        <div class="cursor-pointer">
                            <Checkbox v-model="allSelected" :binary="true" />
                        </div>
                    </TH>
                    <TH>Name</TH>
                    <TH>Code</TH>
                    <TH>SOH Quantity</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>
                            <Checkbox
                                v-model="selectedItems"
                                :value="item.id"
                                :inputId="`item-${item.id}`"
                            />
                        </TD>
                        <TD>{{ item.product.name }}</TD>
                        <TD>{{ item.product.inventory_code }}</TD>
                        <TD>{{ item.quantity }}</TD>
                        <TD>
                            <Button variant="link" class="text-green-500 p-0"
                                >Approve</Button
                            >
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
