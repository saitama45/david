<script setup>
const {
    mondayOrders,
    tuesdayOrders,
    wednesdayOrders,
    thursdayOrders,
    fridayOrders,
    saturdayOrders,
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
});

const dates = ref([
    { name: "December 23, 2024 - December 27, 2024", code: "NY" },
]);

const selectedDate = ref("NY");

const days = [
    { name: "Monday", orders: mondayOrders },
    { name: "Tuesday", orders: tuesdayOrders },
    { name: "Wednesday", orders: wednesdayOrders },
    { name: "Thursday", orders: thursdayOrders },
    { name: "Friday", orders: fridayOrders },
    { name: "Saturday", orders: saturdayOrders },
];
</script>

<template>
    <Layout heading="Ice Cream Orders">
        <TableContainer>
            <TableHeader>
                <Select
                    v-model="selectedDate"
                    :options="dates"
                    class="w-fit"
                    optionLabel="name"
                    optionValue="code"
                />
            </TableHeader>

            <DivFlexCol v-for="day in days" :key="day.name" class="gap-2">
                <SpanBold>{{ day.name }}</SpanBold>
                <TableConatiner
                    v-for="data in day.orders"
                    :key="data.item_code"
                >
                    <Label>{{ data.item }} ({{ data.item_code }})</Label>
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
                </TableConatiner>
            </DivFlexCol>
        </TableContainer>
    </Layout>
</template>
