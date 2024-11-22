<script setup>
import { ref } from "vue";
const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "APPROVED":
            return "bg-green-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        case "REJECTED":
            return "bg-red-400 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};

const props = defineProps({
    order: {
        type: Object,
    },
    orderedItems: {
        type: Object,
    },
});

console.log(props.orderedItems);
</script>

<template>
    <Layout heading="Order Details">
        <TableContainer>
            <DivFlexCenter class="justify-between">
                <DivFlexCenter class="gap-5">
                    <span class="text-gray-700 text-sm">
                        Order Number:
                        <span class="font-bold"> {{ order.order_number }}</span>
                    </span>
                    <span class="text-gray-700 text-sm">
                        Order Date:
                        <span class="font-bold"> {{ order.order_date }}</span>
                    </span>
                    <span class="text-gray-700 text-sm">
                        Status:
                        <Badge
                            :class="
                                statusBadgeColor(order.order_request_status)
                            "
                        >
                            {{ order.order_request_status.toUpperCase() }}
                        </Badge>
                    </span>
                </DivFlexCenter>

                <Button class="bg-blue-500 hover:bg-blue-300">
                    Copy Order and Create
                </Button>
            </DivFlexCenter>
            <DivFlexCenter class="justify-between">
                <SearchBar />
            </DivFlexCenter>

            <Table>
                <TableHead>
                    <TH> Item Code </TH>
                    <TH> Name </TH>
                    <TH> Unit </TH>
                    <TH> Quantity </TH>
                    <TH> Cost </TH>
                    <TH> Total Cost </TH>
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orderedItems" :key="order.id">
                        <TD>{{ order.product_inventory.inventory_code }}</TD>
                        <TD>{{ order.product_inventory.name }}</TD>
                        <TD>{{
                            order.product_inventory.unit_of_measurement.name
                        }}</TD>
                        <TD>{{ order.quantity_ordered }}</TD>
                        <TD>{{ order.product_inventory.cost }}</TD>
                        <TD>{{ order.total_cost }}</TD>
                        <TD>
                            <Button class="text-red-500" variant="outline">
                                <Trash2 />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
