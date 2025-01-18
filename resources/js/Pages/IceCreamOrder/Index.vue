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
});

const getDefaultSelectedDate = () => {
    if (!datesOption || !Array.isArray(datesOption) || datesOption.length < 2) {
        return null;
    }
    return datesOption[1]?.code || null;
};

const defaultSelectedDate = getDefaultSelectedDate();
const selectedDate = ref(filters.start_date_filter || defaultSelectedDate);

const days = [
    { name: "Monday", orders: mondayOrders },
    { name: "Tuesday", orders: tuesdayOrders },
    { name: "Wednesday", orders: wednesdayOrders },
    { name: "Thursday", orders: thursdayOrders },
    { name: "Friday", orders: fridayOrders },
    { name: "Saturday", orders: saturdayOrders },
];

watch(selectedDate, function (value) {
    router.get(
        route("ice-cream-orders.index"),
        { start_date_filter: value },
        {
            preserveState: false,
            replace: true,
        }
    );
});

const exportToExcel = () => {
    const data = {
        data: {
            start_date_filter: selectedDate.value,
        },
        preserveState: true,
        preserveScroll: true,
        replace: true,
    };
    window.open(route("ice-cream-orders.excel", data.data), "_blank");
};
</script>

<template>
    <Layout
        heading="Ice Cream Orders"
        :hasButton="true"
        buttonName="Export to Excel"
        :handleClick="exportToExcel"
    >
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

                <MobileTableContainer
                    v-for="data in day.orders"
                    :key="data.item_code"
                >
                    <MobileTableRow
                        v-for="item in data.branches"
                        :key="item.display_name"
                    >
                        <MobileTableHeading :title="item.display_name">
                        </MobileTableHeading>
                        <LabelXS
                            >Quantity Ordered:
                            {{ item.quantity_ordered }}</LabelXS
                        >
                    </MobileTableRow>
                </MobileTableContainer>
            </DivFlexCol>
        </TableContainer>
    </Layout>
</template>
