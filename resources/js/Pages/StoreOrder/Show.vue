<script setup>
import { ref } from "vue";
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

const props = defineProps({
    orders: {
        type: Object,
    },
    orderDetails: {
        type: Object,
    },
});

const { SONumber, SODate, STATUS, SOApproved } = props.orderDetails[0];
</script>

<template>
    <Layout heading="Order Details">
        <TableContainer>
            <DivFlexCenter class="justify-between">
                <DivFlexCenter class="gap-5">
                    <span class="text-gray-700 text-sm">
                        Order Number:
                        <span class="font-bold"> {{ SONumber }}</span>
                    </span>
                    <span class="text-gray-700 text-sm">
                        Order Date: <span class="font-bold"> {{ SODate }}</span>
                    </span>
                    <span class="text-gray-700 text-sm">
                        Status: 
                        <Badge :class="statusBadgeColor(STATUS)">
                            {{ STATUS }}
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
                <thead>
                    <tr>
                        <TH>Item</TH>
                        <TH>Code</TH>
                        <TH>Unit</TH>
                        <TH>Quantity</TH>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="order in orders" :key="order.id">
                        <TD>{{ order.InventoryName }}</TD>
                        <TD>{{ order.ItemCode }}</TD>
                        <TD>{{ order.UOM_Desc }}</TD>
                        <TD>{{ order.PO_QTY }}</TD>
                    </tr>
                </tbody>
            </Table>
        </TableContainer>
    </Layout>
</template>
