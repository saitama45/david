<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import Checkbox from "primevue/checkbox";
const { backButton } = useBackButton(route("receiving-approvals.index"));
const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
    items: {
        type: Object,
        required: true,
    },
});

const selectedItems = ref([]);

const bulkApprove = () => {
    console.log(selectedItems.value);
};

console.log(props.order);
</script>

<template>
    <Layout :heading="`Order Number ${order.order_number}`">
        <TableContainer>
            <TableHeader class="justify-between">
                <Button
                    v-if="selectedItems.length > 0"
                    @click="bulkApprove"
                    variant="outline"
                    >Approve Selected Items</Button
                >
                <Button class="bg-green-500">Approve All</Button>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH> </TH>
                    <TH>Item</TH>
                    <TH>Inventory Code</TH>
                    <TH>Received Date</TH>
                    <TH>Quantity Received</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items" :key="item.id">
                        <TD>
                            <Checkbox
                                v-model="selectedItems"
                                :value="item.id"
                                :inputId="`item-${item.id}`"
                            />
                        </TD>
                        <TD>{{
                            item.store_order_item.product_inventory.name
                        }}</TD>
                        <TD>{{
                            item.store_order_item.product_inventory
                                .inventory_code
                        }}</TD>
                        <TD>{{ item.received_date }}</TD>
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
