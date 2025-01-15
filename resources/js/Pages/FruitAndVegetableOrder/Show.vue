<script setup>
import { router } from "@inertiajs/vue3";
const {
    mondayOrders,
    tuesdayOrders,
    wednesdayOrders,
    thursdayOrders,
    fridayOrders,
    saturdayOrders,
    datesOption,
    filters,
    inventory_code,
    currentFilter,
} = defineProps({
    mondayOrders: {
        type: Object,
        required: true,
    },
    tuesdayOrders: {
        type: Object,
        required: true,
    },
    wednesdayOrders: {
        type: Object,
        required: true,
    },
    thursdayOrders: {
        type: Object,
        required: true,
    },
    fridayOrders: {
        type: Object,
        required: true,
    },
    saturdayOrders: {
        type: Object,
        required: true,
    },
    datesOption: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    inventory_code: {
        type: String,
        required: true,
    },
    currentFilter: {
        type: String,
        required: false,
    },
});

const getDefaultSelectedDate = () => {
    if (!datesOption || !Array.isArray(datesOption) || datesOption.length < 2) {
        return null;
    }
    return datesOption[1]?.code || null;
};

const defaultSelectedDate = getDefaultSelectedDate();
const selectedDate = ref(filters.start_date_filter || defaultSelectedDate);
const branchId = ref(filters.branchId || defaultSelectedDate);
console.log(filters);

const days = [
    { name: "Monday", orders: mondayOrders },
    { name: "Tuesday", orders: tuesdayOrders },
    { name: "Wednesday", orders: wednesdayOrders },
    { name: "Thursday", orders: thursdayOrders },
    { name: "Friday", orders: fridayOrders },
    { name: "Saturday", orders: saturdayOrders },
];

watch(selectedDate, function (value) {
    console.log(value);
    router.get(
        route("fruits-and-vegetables.show", inventory_code),
        { start_date_filter: value },
        {
            preserveState: false,
            replace: true,
        }
    );
});
</script>

<template>
    <Layout :heading="`Orders Summary`">
        <TableContainer>
            <TableHeader>
                <Select
                    placeholder="Select Date"
                    v-model="selectedDate"
                    :options="datesOption"
                    class="w-fit min-w-72"
                    optionLabel="name"
                    optionValue="code"
                />
            </TableHeader>

            <DivFlexCol v-for="day in days" :key="day.name" class="gap-2">
                <SpanBold>{{ day.name }}</SpanBold>
                <h1 v-if="day.orders.length < 1">No orders to show</h1>
                <TableContainer
                    v-for="data in day.orders"
                    :key="data.item_code"
                >
                    <DivFlexCol>
                        <Label>{{ data.item }} ({{ data.item_code }})</Label>
                        <SpanBold class="text-xs"
                            >Total Orders: {{ data.total_quantity }}</SpanBold
                        >
                    </DivFlexCol>
                    <Table>
                        <TableHead>
                            <TH
                                v-for="item in data.branches"
                                :key="item.display_name"
                            >
                                {{ item.display_name }}
                            </TH>
                        </TableHead>
                        <TableBody>
                            <tr>
                                <TD
                                    v-for="item in data.branches"
                                    :key="item.display_name"
                                >
                                    {{ item.quantity_ordered }}
                                </TD>
                            </tr>
                        </TableBody>
                    </Table>
                </TableContainer>
            </DivFlexCol>
        </TableContainer>
        <BackButton />
    </Layout>
</template>
