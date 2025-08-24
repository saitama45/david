<script setup>
import { computed } from 'vue';

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
    if (action === 'add' || action === 'add_quantity') {
        return 'IN';
    } else if (action === 'out' || action === 'deduct' || action === 'log_usage') {
        return 'OUT';
    } else if (action === 'initial_balance' || action === 'BEG BAL') { // CRITICAL FIX: Handle both for robustness
        return 'BEG BAL';
    }
    return action.replace(/_/g, " ").toUpperCase();
};

// Helper function to format numbers for display
const formatDisplayNumber = (value, minDecimals = 2, maxDecimals = 10) => {
    if (value === null || value === undefined || isNaN(value)) {
        return 'N/a';
    }
    const num = parseFloat(value);
    if (isNaN(num)) {
        return 'N/a';
    }

    let formatted = num.toFixed(maxDecimals);
    formatted = formatted.replace(/\.?0+$/, '');

    const parts = formatted.split('.');
    if (!parts[1] || parts[1].length < minDecimals) {
        return num.toFixed(minDecimals);
    }

    return formatted;
};

// New helper function to format dates for better readability
const formatDisplayDate = (dateString) => {
    if (!dateString) {
        return 'N/a';
    }
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    } catch (e) {
        console.error("Error formatting date:", dateString, e);
        return dateString; // Fallback to original string if formatting fails
    }
};

// Computed property for the dynamic heading
const dynamicHeading = computed(() => {
    if (history.data && history.data.length > 0 && history.data[0].sap_masterfile) {
        const itemDescription = history.data[0].sap_masterfile.ItemDescription;
        const altUOM = history.data[0].sap_masterfile.AltUOM;
        return `${itemDescription} with AltUOM: ${altUOM}`;
    }
    return 'Stock Details'; // Default title if data is not available
});

</script>
<template>
    <Layout :heading="dynamicHeading">
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
                    <TH>Remarks</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="(data, index) in history.data" :key="data.id">
                        <TD>{{ data.id }}</TD>
                        <TD>
                            <!-- CRITICAL FIX: Conditional display for Ref No. -->
                            <a
                                v-if="data.is_link_ref && data.display_ref_no !== 'N/a'"
                                :href="route('store-orders.show', data.display_ref_no)"
                                target="_blank"
                                class="text-blue-600 hover:underline"
                            >
                                {{ data.display_ref_no }}
                            </a>
                            <span v-else>{{ data.display_ref_no ?? 'N/a' }}</span>
                        </TD>
                        <TD :class="{'text-red-500': formatAction(data.action) === 'OUT', 'text-green-500': formatAction(data.action) === 'IN'}">
                            {{ formatAction(data.action) === 'OUT' ? '-' : '+' }}{{ formatDisplayNumber(data.quantity, 2, 10) }}
                        </TD>
                        <TD>{{ formatAction(data.action) }}</TD>
                        <TD>{{ data.cost_center?.name ?? "N/a" }}</TD>
                        <TD>{{ formatDisplayNumber(data.unit_cost, 2, 4) }}</TD>
                        <TD>{{ formatDisplayNumber(data.total_cost, 2, 4) }}</TD>
                        <TD>{{ formatDisplayDate(data.transaction_date) }}</TD>
                        <TD :class="{'font-bold text-green-600': index === 0}">{{ formatDisplayNumber(data.running_soh, 2, 10) }}</TD>
                        <TD>{{ data.remarks ?? "None" }}</TD>
                    </tr>
                </TableBody>
            </Table>
            <MobileTableContainer>
                <MobileTableRow v-for="(data, index) in history.data" :key="data.id">
                    <MobileTableHeading
                        :title="`${formatAction(data.action)}`"
                    ></MobileTableHeading>
                    <LabelXS>Ref No.:
                        <!-- CRITICAL FIX: Conditional display for Ref No. -->
                        <a
                            v-if="data.is_link_ref && data.display_ref_no !== 'N/a'"
                            :href="route('store-orders.show', data.display_ref_no)"
                            target="_blank"
                            class="text-blue-600 hover:underline"
                        >
                            {{ data.display_ref_no }}
                        </a>
                        <span v-else>{{ data.display_ref_no ?? 'N/a' }}</span>
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
                    <LabelXS>Transaction Date: {{ formatDisplayDate(data.transaction_date) }}</LabelXS>
                    <LabelXS>Remarks: {{ data.remarks ?? "None" }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="history" />
        </TableContainer>

        <BackButton routeName="stock-management.index" />
    </Layout>
</template>
