<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage, router } from "@inertiajs/vue3";

import { throttle } from "lodash";
const { products, branches } = defineProps({
    products: {
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
    router.get(
        route("stock-management.index"),
        { branchId: newValue, search: search.value },
        {
            preserveState: true,
            replace: true,
        }
    );
});

watch(
    search,
    throttle(function (value) {
        router.get(
            route("stock-management.index"),
            { search: value, branchId: branchId.value },
            {
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
            <DivFlexCenter class="justify-between">
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar>
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
            <Table>
                <TableHead>
                    <TH>Name</TH>
                    <TH>Inventory Code</TH>
                    <TH>UOM</TH>
                    <TH>Stock On Hand</TH>
                    <TH>Sytem Estimated Used</TH>
                    <TH>Recorded Used</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="product in products.data">
                        <TD>{{ product.name }}</TD>
                        <TD>{{ product.inventory_code }}</TD>
                        <TD>{{ product.uom }}</TD>
                        <TD>{{ product.stock_on_hand }}</TD>
                        <TD>{{ product.estimated_used }}</TD>
                        <TD>{{ product.recorded_used }}</TD>
                        <TD>
                            <ShowButton
                                :isLink="true"
                                :href="
                                    route(
                                        'stock-management.show',
                                        product.inventory_code
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="products" />
        </TableContainer>
    </Layout>
</template>
