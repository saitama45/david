<script setup>

const { branches, history } = defineProps({
    branches: {
        type: Object,
        required: true,
    },
    history: {
        type: Object,
        required: true,
    },
});

// For debugging: Log the history data received by the component
console.log('StockManagement Show Vue - History Data:', history);

// Helper function to format action names
const formatAction = (action) => {
    if (action === 'add_quantity') {
        return 'IN';
    } else if (action === 'initial_balance') {
        return 'INITIAL BALANCE';
    }
    return action.replace(/_/g, " ").toUpperCase();
};

</script>
<template>
    <Layout heading="Stock Details">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Ref No.</TH> <!-- New column for Reference Number -->
                    <TH>Quantity Change</TH>
                    <TH>Action</TH>
                    <TH>Cost Center</TH>
                    <TH>Unit Cost</TH>
                    <TH>Total Cost</TH>
                    <TH>Transaction Date</TH>
                    <TH>Running SOH</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="data in history.data" :key="data.id">
                        <TD>{{ data.id }}</TD>
                        <TD>
                            <a
                                v-if="data.purchase_item_batch?.store_order_item?.store_order?.order_number"
                                :href="route('store-orders.show', data.purchase_item_batch.store_order_item.store_order.order_number)"
                                target="_blank"
                                class="text-blue-600 hover:underline"
                            >
                                {{ data.purchase_item_batch.store_order_item.store_order.order_number }}
                            </a>
                            <span v-else>N/a</span>
                        </TD>
                        <TD>{{ parseFloat(data.quantity).toFixed(2) }}</TD>
                        <TD>{{ formatAction(data.action) }}</TD>
                        <TD>{{ data.cost_center?.name ?? "N/a" }}</TD>
                        <TD>{{ parseFloat(data.unit_cost).toFixed(2) }}</TD>
                        <TD>{{ parseFloat(data.total_cost).toFixed(2) }}</TD>
                        <TD>{{ data.transaction_date }}</TD>
                        <TD>{{ parseFloat(data.running_soh).toFixed(2) }}</TD>
                    </tr>
                </TableBody>
            </Table>
            <MobileTableContainer>
                <MobileTableRow v-for="data in history.data" :key="data.id">
                    <MobileTableHeading
                        :title="`${formatAction(data.action)}`"
                    ></MobileTableHeading>
                    <LabelXS>Ref No.:
                        <a
                            v-if="data.purchase_item_batch?.store_order_item?.store_order?.order_number"
                            :href="route('store-orders.show', data.purchase_item_batch.store_order_item.store_order.order_number)"
                            target="_blank"
                            class="text-blue-600 hover:underline"
                        >
                            {{ data.purchase_item_batch.store_order_item.store_order.order_number }}
                        </a>
                        <span v-else>N/a</span>
                    </LabelXS>
                    <LabelXS>Quantity Change: {{ parseFloat(data.quantity).toFixed(2) }}</LabelXS>
                    <LabelXS>Running SOH: {{ parseFloat(data.running_soh).toFixed(2) }}</LabelXS>
                    <LabelXS>Cost Center: {{ data.cost_center?.name ?? "N/a" }}</LabelXS>
                    <LabelXS>Unit Cost: {{ parseFloat(data.unit_cost).toFixed(2) }}</LabelXS>
                    <LabelXS>Total Cost: {{ parseFloat(data.total_cost).toFixed(2) }}</LabelXS>
                    <LabelXS>Transaction Date: {{ data.transaction_date }}</LabelXS>
                    <LabelXS>Remarks: {{ data.remarks ?? "None" }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="history" />
        </TableContainer>

        <BackButton routeName="stock-management.index" />
    </Layout>
</template>
