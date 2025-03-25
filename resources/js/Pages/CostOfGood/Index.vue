<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
const { costOfGoods, branches, timePeriods, filters } = defineProps({
    costOfGoods: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
    timePeriods: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
});

const { options: branchesOptions } = useSelectOptions(branches);
let search = ref(usePage().props.filters.search);
const branchId = ref(filters.branchId || branchesOptions.value[0].value);

const { options: timePeriodOptions } = useSelectOptions(timePeriods);
const time_period = ref(
    filters.time_period || timePeriodOptions.value[0].value
);

watch(branchId, (value) => {
    router.get(
        route("cost-of-goods.index"),
        {
            search: search.value,
            branchId: value,
            time_period: time_period.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

watch(time_period, (value) => {
    router.get(
        route("cost-of-goods.index"),
        {
            search: search.value,
            time_period: value,
            branchId: branchId.value,
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
            route("cost-of-goods.index"),
            {
                search: value,
                branchId: branchId.value,
                time_period: time_period.value,
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
    <Layout heading="Cost of Goods">
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
                        placeholder="Select a Supplier"
                        v-model="branchId"
                        :options="branchesOptions"
                        optionLabel="label"
                        optionValue="value"
                    >
                    </Select>
                    <InputContainer>
                        <Select
                            v-model="time_period"
                            filter
                            placeholder="Time Periods"
                            :options="timePeriodOptions"
                            optionLabel="label"
                            optionValue="value"
                        ></Select>
                    </InputContainer>
                </DivFlexCenter>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Item</TH>
                    <TH>Inventory Code</TH>
                    <TH>Quantity</TH>
                    <TH>Cost Center</TH>
                    <TH>Unit Cost</TH>
                    <TH>Total Cost</TH>
                    <TH>Transaction Date</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="costOfGood in costOfGoods.data">
                        <TD>{{ costOfGood.product.name }}</TD>
                        <TD>{{ costOfGood.product.inventory_code }}</TD>
                        <TD>{{ costOfGood.quantity }}</TD>
                        <TD>{{ costOfGood.cost_center?.name ?? "N/a" }}</TD>
                        <TD>{{ costOfGood.unit_cost }}</TD>
                        <TD>{{ costOfGood.total_cost }}</TD>
                        <TD>{{ costOfGood.transaction_date }}</TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="costOfGoods" />
        </TableContainer>
    </Layout>
</template>
