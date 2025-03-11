<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
const { transactions, branches, timePeriods, filters } = defineProps({
    transactions: {
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
        route("sales-report.index"),
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
        route("sales-report.index"),
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
            route("sales-report.index"),
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
    <Layout heading="Sales Report">
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
                    <TH>Id</TH>
                    <TH>Store Branch</TH>
                    <TH>Receipt No.</TH>
                    <TH>Item Count</TH>
                    <TH>Overall Net Total</TH>
                    <TH>Date</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="transaction in transactions.data">
                        <TD>{{ transaction.id }}</TD>
                        <TD>{{ transaction.store_branch }}</TD>
                        <TD>{{ transaction.receipt_number }}</TD>
                        <TD>{{ transaction.item_count }}</TD>
                        <TD>{{ transaction.net_total }}</TD>
                        <TD>{{ transaction.order_date }}</TD>
                        <TD class="flex items-center">
                            <ShowButton
                                :isLink="true"
                                :href="
                                    route(
                                        'store-transactions.show',
                                        transaction.id
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="transactions" />
        </TableContainer>
        <BackButton />
    </Layout>
</template>
