<script setup>
import CardContent from "@/Components/ui/card/CardContent.vue";
import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("items.index"));

const { item } = defineProps({
    item: {
        type: Object,
        required: true,
    },
    orders: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout heading="Item Details">
        <section class="grid grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="text-xl">
                        {{ item.name }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="grid grid-cols-2 gap-3">
                    <Label>Inventory Code</Label>
                    <Label class="font-bold">{{ item.inventory_code }}</Label>

                    <Label>Inventory Category</Label>
                    <Label class="font-bold">{{
                        item.inventory_category.name
                    }}</Label>

                    <Label>Brand</Label>
                    <Label class="font-bold">{{ item.brand }}</Label>

                    <Label>Conversion</Label>
                    <Label class="font-bold">{{ item.conversion }}</Label>

                    <Label>Unit Of Measurement</Label>
                    <Label class="font-bold">{{
                        item.unit_of_measurement.name
                    }}</Label>

                    <Label>Cost</Label>
                    <Label class="font-bold">{{ item.cost }}</Label>

                    <Label>Is Active</Label>
                    <Label class="font-bold">{{
                        item.is_active ? "Yes" : "No"
                    }}</Label>
                </CardContent>
            </Card>
        </section>

        <Card class="p-5">
            <CardHeader>
                <CardTitle>Orders History</CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHead>
                        <TH>Order Number</TH>
                        <TH>Supplier</TH>
                        <TH>Branch</TH>
                        <TH>Quantity Received</TH>
                        <TH>Received Date</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="order in orders" :key="order.id">
                            <TD>
                                <a
                                    class="p-0 text-blue-500"
                                    target="_blank"
                                    :href="`/store-orders/show/${order.store_order_item.store_order.order_number}`"
                                >
                                    {{
                                        order.store_order_item.store_order
                                            .order_number
                                    }}
                                </a>
                            </TD>
                            <TD>{{
                                order.store_order_item.store_order.supplier.name
                            }}</TD>
                            <TD>{{
                                order.store_order_item.store_order.store_branch
                                    .name
                            }}</TD>
                            <TD>{{ order.quantity_received }}</TD>
                            <TD>{{ order.received_date }}</TD>
                        </tr>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>
