<script setup>
const props = defineProps({
    orders: {
        type: Object,
    },
    orderDetails: {
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
                    <TH>Item</TH>
                    <TH>Code</TH>
                    <TH>Unit</TH>
                    <TH>Quantity</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders" :key="order.id">
                        <TD>{{ order.InventoryName }}</TD>
                        <TD>{{ order.ItemCode }}</TD>
                        <TD>{{ order.UOM_Desc }}</TD>
                        <TD>{{ order.PO_QTY }}</TD>
                        <TD class="w-[200px]">
                            <DivFlexCenter class="gap-5">
                                <button class="text-blue-500">
                                    <Pencil />
                                </button>
                                <button class="text-red-500">
                                    <Trash2 />
                                </button>
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
