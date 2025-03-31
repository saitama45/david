<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage, router, useForm } from "@inertiajs/vue3";
import { throttle } from "lodash";
const { items, filters, branches } = defineProps({
    items: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
});

const { options: branchesOptions } = useSelectOptions(branches);
const branch = ref(filters.branch || branchesOptions.value[0].value);

let search = ref(filters.search);

watch(branch, (newValue) => {
    router.get(
        route("low-on-stocks.index"),
        {
            branch: newValue,
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
            route("low-on-stocks.index"),
            {
                search: value,
                branchId: branch.value,
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
    <Layout heading="Low on stock items">
        <TableHeader>
            <SearchBar>
                <Input class="pl-10" placeholder="Search..." v-model="search" />
            </SearchBar>
            <DivFlexCenter class="gap-3">
                <Select
                    filter
                    class="min-w-72"
                    placeholder="Select a Supplier"
                    :options="branchesOptions"
                    optionLabel="label"
                    optionValue="value"
                    v-model="branch"
                >
                </Select>
            </DivFlexCenter>
        </TableHeader>
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>ID</TH>
                    <TH>Name</TH>
                    <TH>Inventory Code</TH>
                    <TH>Stock On Hand</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.id }}</TD>
                        <TD>{{ item.product.name }}</TD>
                        <TD>{{ item.product.inventory_code }}</TD>
                        <TD>{{ item.available_stock }}</TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
