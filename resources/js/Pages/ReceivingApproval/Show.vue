<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import Checkbox from "primevue/checkbox";
const { backButton } = useBackButton(route("receiving-approvals.index"));
const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
});

const selectedItems = ref([]);

const bulkApprove = () => {
    console.log(selectedItems.value);
};
</script>

<template>
    <Layout :heading="`Order Number ${order.order_number}`">
        <TableContainer>
            <TableHeader>
                <Button
                    v-if="selectedItems.length > 0"
                    @click="bulkApprove"
                    variant="outline"
                    >Approve Selected Items</Button
                >
            </TableHeader>
            <Table>
                <TableHead>
                    <TH> </TH>
                    <TH>Item</TH>
                    <TH>Inventory Code</TH>
                    <TH>Order Date</TH>
                    <TH>Quantity Ordered</TH>
                    <TH>Quantity Received</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in order.store_order_items" :key="item.id">
                        <TD>
                            <Checkbox
                                v-model="selectedItems"
                                :value="item.id"
                                :inputId="`item-${item.id}`"
                            />
                        </TD>
                        <TD>{{ item.product_inventory.name }}</TD>
                        <TD>{{ item.product_inventory.inventory_code }}</TD>
                        <TD>{{ order.order_date }}</TD>
                        <TD>{{ item.quantity_ordered }}</TD>
                        <TD>{{ item.quantity_received }}</TD>
                        <TD>
                            <Button variant="link" class="text-green-500 p-0">
                                Approve
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
