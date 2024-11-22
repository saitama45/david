<script setup>
import { useBackButton } from "@/Composables/useBackButton";

const { backButton } = useBackButton(route("orders-approval.index"));
const props = defineProps({
    order: {
        type: Object,
    },
    orderedItems: {
        type: Object,
    },
});
import { ref } from "vue";
const search = ref(null);
const statusBadgeColor = (status) => {
    switch (status) {
        case "RECEIVED":
            return "bg-green-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        case "INCOMPLETE":
            return "bg-orange-500 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};
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

                <DivFlexCenter class="gap-5">
                    <Button variant="secondary"> Update Details </Button>
                    <Button class="bg-blue-500 hover:bg-blue-300">
                        Copy Order And Create
                    </Button>
                    <Button variant="destructive"> Decline Order </Button>
                    <Button class="bg-green-500 hover:bg-green-300">
                        Approve Order
                    </Button>
                </DivFlexCenter>
            </DivFlexCenter>

            <TableHeader>
                <SearchBar />
            </TableHeader>

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

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>
