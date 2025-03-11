<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
const { storeOrderItems, branches, timePeriods, filters } = defineProps({
    storeOrderItems: {
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
        route("account-payable.index"),
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
        route("account-payable.index"),
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
            route("account-payable.index"),
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
    <Layout heading="Account Payable">
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
                    <TH>Item Code</TH>
                    <TH>Quantity</TH>
                    <TH>Price</TH>
                    <TH>Total</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in storeOrderItems.data">
                        <TD>{{ item.product_inventory.name }}</TD>
                        <TD>{{ item.product_inventory.inventory_code }}</TD>
                        <TD>
                            {{ item.quantity_received }}
                        </TD>
                        <TD>{{ item.product_inventory.cost }}</TD>
                        <TD>
                            {{
                                item.quantity_received *
                                item.product_inventory.cost
                            }}
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="storeOrderItems" />
        </TableContainer>
    </Layout>
</template>
