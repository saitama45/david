<script setup>
import { computed } from 'vue'; // Import computed if not already

const { branches, history } = defineProps({
    branches: {
        type: Object,
        required: true,
    },
    history: {
        type: Object, // history is paginated, so it's an object with 'data'
        required: true,
    },
});

// For debugging: Log the history data received by the component
console.log('StockManagement Show Vue - History Data:', history);

// Helper function to format action names
const formatAction = (action) => {
    if (action === 'add' || action === 'add_quantity') { // Handle both 'add' and old 'add_quantity'
        return 'IN';
    } else if (action === 'out' || action === 'deduct' || action === 'log_usage') { // Handle 'out', 'deduct', and old 'log_usage'
        return 'OUT';
    } else if (action === 'initial_balance') {
        return 'INITIAL BALANCE';
    }
    return action.replace(/_/g, " ").toUpperCase();
};

// New helper function to format numbers for display
const formatDisplayNumber = (value, minDecimals = 2, maxDecimals = 10) => {
    if (value === null || value === undefined || isNaN(value)) {
        return 'N/a';
    }
    const num = parseFloat(value);
    if (isNaN(num)) {
        return 'N/a';
    }

    // Use toFixed with maxDecimals to ensure full precision is captured initially
    let formatted = num.toFixed(maxDecimals);

    // Remove trailing zeros and the decimal point if it becomes .0
    formatted = formatted.replace(/\.?0+$/, '');

    // If after trimming, it's an integer or has fewer than minDecimals,
    // re-format to ensure minDecimals are present.
    const parts = formatted.split('.');
    if (!parts[1] || parts[1].length < minDecimals) {
        return num.toFixed(minDecimals);
    }

    return formatted;
};

</script>
<template>
    <Layout heading="Stock Details">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Ref No.</TH>
                    <TH>Quantity Change</TH>
                    <TH>Action</TH>
                    <TH>Cost Center</TH>
                    <TH>Unit Cost</TH>
                    <TH>Total Cost</TH>
                    <TH>Transaction Date</TH>
                    <TH>Running SOH</TH>
                    <TH>Remarks</TH> <!-- Added Remarks column -->
                </TableHead>
                <TableBody>
                    <tr v-for="(data, index) in history.data" :key="data.id">
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
                        <TD :class="{'text-red-500': formatAction(data.action) === 'OUT', 'text-green-500': formatAction(data.action) === 'IN'}">
                            {{ formatAction(data.action) === 'OUT' ? '-' : '+' }}{{ formatDisplayNumber(data.quantity, 2, 10) }}
                        </TD>
                        <TD>{{ formatAction(data.action) }}</TD>
                        <TD>{{ data.cost_center?.name ?? "N/a" }}</TD>
                        <TD>{{ formatDisplayNumber(data.unit_cost, 2, 4) }}</TD>
                        <TD>{{ formatDisplayNumber(data.total_cost, 2, 4) }}</TD>
                        <TD>{{ data.transaction_date }}</TD>
                        <TD :class="{'font-bold text-green-600': index === 0}">{{ formatDisplayNumber(data.running_soh, 2, 10) }}</TD>
                        <TD>{{ data.remarks ?? "None" }}</TD> <!-- Display remarks -->
                    </tr>
                </TableBody>
            </Table>
            <MobileTableContainer>
                <MobileTableRow v-for="(data, index) in history.data" :key="data.id">
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
                    <LabelXS>Quantity Change:
                        <span :class="{'text-red-500': formatAction(data.action) === 'OUT', 'text-green-500': formatAction(data.action) === 'IN'}">
                            {{ formatAction(data.action) === 'OUT' ? '-' : '+' }}{{ formatDisplayNumber(data.quantity, 2, 10) }}
                        </span>
                    </LabelXS>
                    <LabelXS :class="{'font-bold text-green-600': index === 0}">Running SOH: {{ formatDisplayNumber(data.running_soh, 2, 10) }}</LabelXS>
                    <LabelXS>Cost Center: {{ data.cost_center?.name ?? "N/a" }}</LabelXS>
                    <LabelXS>Unit Cost: {{ formatDisplayNumber(data.unit_cost, 2, 4) }}</LabelXS>
                    <LabelXS>Total Cost: {{ formatDisplayNumber(data.total_cost, 2, 4) }}</LabelXS>
                    <LabelXS>Transaction Date: {{ data.transaction_date }}</LabelXS>
                    <LabelXS>Remarks: {{ data.remarks ?? "None" }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="history" />
        </TableContainer>

        <BackButton routeName="stock-management.index" />
    </Layout>
</template>
