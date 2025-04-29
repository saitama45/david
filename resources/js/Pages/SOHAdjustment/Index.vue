<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage, router, useForm } from "@inertiajs/vue3";

import { throttle, update } from "lodash";
import { ref } from "vue";
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
    console.log(usePage());
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
</script>
<template>
    <Layout heading="Stock Management">
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
                </DivFlexCenter>
            </DivFlexCenter>
            <Table>
                <TableHead>
                    <TH>Name</TH>
                    <TH>Code</TH>
                    <TH>SOH Quantity</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.product.name }}</TD>
                        <TD>{{ item.product.inventory_code }}</TD>
                        <TD>{{ item.quantity }}</TD>
                        <TD></TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
