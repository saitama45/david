<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
const { items, branches, filters } = defineProps({
    items: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
});

const { options: branchesOptions } = useSelectOptions(branches);
let search = ref(filters.search);
const branchId = ref(filters.branchId || branchesOptions.value[0].value);
watch(branchId, (value) => {
    router.get(
        route("days-inventory-outstanding.index"),
        {
            search: search.value,
            branchId: value,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

watch(
    search,
    throttle(function (value) {
        router.get(
            route("days-inventory-outstanding.index"),
            {
                search: value,
                branchId: branchId.value,
            },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);
</script>

<template>
    <Layout heading="Days Inventory Outstanding">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar>

                <DivFlexCenter class="gap-5">
                    <Select
                        filter
                        placeholder="Select a Branch"
                        v-model="branchId"
                        :options="branchesOptions"
                        optionLabel="label"
                        optionValue="value"
                    >
                    </Select>
                </DivFlexCenter>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Item</TH>
                    <TH>Inventory Code</TH>
                    <TH>Quantity</TH>
                    <TH>Unit Cost</TH>
                    <TH>Total Cost</TH>
                    <TH>Transaction Date</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.product.name }}</TD>
                        <TD>{{ item.product.inventory_code }}</TD>
                        <TD>{{ item.quantity }}</TD>
                        <TD>{{ item.unit_cost }}</TD>
                        <TD>{{ item.total_cost }}</TD>
                        <TD>{{ item.transaction_date }}</TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
