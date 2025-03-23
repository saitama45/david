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
        route("top-10-inventories.index"),
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
            route("top-10-inventories.index"),
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
    <Layout heading="Top 10 Inventories">
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
                    <TH>Name</TH>
                    <TH>Inventory Code</TH>
                    <TH>Quantity</TH>
                    <TH>Current Cost Per Unit</TH>
                    <TH>Total Cost</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items">
                        <TD>{{ item.name }}</TD>
                        <TD>{{ item.inventory_code }}</TD>
                        <TD>{{ item.quantity }}</TD>
                        <TD>{{ item.current_cost }}</TD>
                        <TD>{{ item.total_cost }}</TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
