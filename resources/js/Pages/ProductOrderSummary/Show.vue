<script setup>
import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("product-orders-summary.index"));
import { usePage } from "@inertiajs/vue3";
const { item, orders } = defineProps({
    item: {
        type: Object,
        required: true,
    },
    orders: {
        type: Object,
        required: true,
    },
});

const heading = `Orders For Item ${item.name} (${item.inventory_code})`;
</script>

<template>
    <Layout :heading="heading">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Supplier</TH>
                    <TH>Store Branch</TH>
                    <TH>Quantity Approved</TH>
                    <TH>Quantity Delivered</TH>
                    <TH>Order Date</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders" :key="order">
                        <TD>{{ order.store_order.supplier.name }}</TD>
                        <TD>{{ order.store_order.store_branch.name }}</TD>
                        <TD>{{ order.quantity_approved }}</TD>
                        <TD>{{ order.quantity_received }}</TD>
                        <TD>{{ order.store_order.order_date }}</TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="order in orders" :key="order">
                    <MobileTableHeading
                        :title="order.store_order.store_branch.name"
                    >
                    </MobileTableHeading>
                    <LabelXS
                        >Supplier:
                        {{ order.store_order.supplier.name }}</LabelXS
                    >
                    <LabelXS
                        >Order Date: {{ order.store_order.order_date }}</LabelXS
                    >
                    <LabelXS>Ordered: {{ order.quantity_approved }}</LabelXS>
                    <LabelXS>Delivered: {{ order.quantity_received }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
        </TableContainer>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>
