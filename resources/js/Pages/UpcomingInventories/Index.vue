<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
const { inventories, branches, timePeriods, filters } = defineProps({
    inventories: {
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
        route("upcoming-inventories.index"),
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
        route("upcoming-inventories.index"),
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
            route("upcoming-inventories.index"),
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
    <Layout heading="Upcoming Inventories">
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
                    <tr v-for="inventory in inventories.data">
                        <TD>{{ inventory.product_inventory.name }}</TD>
                        <TD>{{
                            inventory.product_inventory.inventory_code
                        }}</TD>
                        <TD>
                            {{ inventory.quantity_commited }}
                        </TD>
                        <TD>{{ inventory.product_inventory.cost }}</TD>
                        <TD>
                            {{
                                inventory.quantity_commited *
                                inventory.product_inventory.cost
                            }}
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="inventories" />
        </TableContainer>
    </Layout>
</template>
