<script setup>
const { orders } = defineProps({
    orders: {
        type: Object,
        required: true,
    },
    dateOptionsFilter: {
        type: Object,
        default: {},
    },
});
const daysOfWeek = [
    { label: "Monday", items: orders.Monday },
    { label: "Tuesday", items: orders.Tuesday },
    { label: "Wednesday", items: orders.Wednesday },
    { label: "Thursday", items: orders.Thursday },
    { label: "Friday", items: orders.Friday },
    { label: "Saturday", items: orders.Saturday },
];

const dates = ref([
    { name: "December 23, 2024 - December 27, 2024", code: "NY" },
]);

const selectedDate = ref("NY");
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
            <div
                v-for="{ label, items } in daysOfWeek"
                :key="label"
                class="gap-2"
            >
                <SpanBold>{{ label }}</SpanBold>
                <Card v-for="order in items" :key="order.id">
                    <CardHeader>
                        <CardTitle>
                            {{ order.ordered_item }}
                        </CardTitle>
                        <CardDescription>
                            Total Order: {{ order.total_quantity }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHead>
                                <TH
                                    v-for="branch in order.branches"
                                    :key="branch.store"
                                >
                                    {{ branch.store }}
                                </TH>
                            </TableHead>
                            <TableBody>
                                <tr>
                                    <TD
                                        v-for="branch in order.branches"
                                        :key="branch.store"
                                    >
                                        {{ branch.quantity }}
                                    </TD>
                                </tr>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </TableContainer>
    </Layout>
</template>
