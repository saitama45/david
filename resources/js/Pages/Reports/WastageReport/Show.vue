<script setup>
import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ChevronLeft, Eye, Download, FileText } from 'lucide-vue-next';

const props = defineProps({
    wastage: {
        type: Object,
        required: true,
    },
    items: {
        type: Array,
        required: true,
    },
    permissions: {
        type: Object,
        required: true,
    }
});

const { hasAccess } = useAuth();

// Format currency
const formatCurrency = (amount) => {
    if (!amount) return '₱0.00';
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    }).format(amount);
};

// Format number
const formatNumber = (num) => {
    if (!num) return '0';
    return new Intl.NumberFormat('en-PH').format(num);
};

// Status styling
const getStatusClass = (status) => {
    const statusStyles = {
        'pending': {
            bg: 'bg-gray-50 border-gray-200',
            text: 'text-gray-700',
            dot: 'bg-gray-400',
            label: 'Pending'
        },
        'approved_lvl1': {
            bg: 'bg-blue-50 border-blue-200',
            text: 'text-blue-700',
            dot: 'bg-blue-400',
            label: 'Approved Level 1'
        },
        'approved_lvl2': {
            bg: 'bg-green-50 border-green-200',
            text: 'text-green-700',
            dot: 'bg-green-400',
            label: 'Approved Level 2'
        },
        'cancelled': {
            bg: 'bg-red-50 border-red-200',
            text: 'text-red-700',
            dot: 'bg-red-400',
            label: 'Cancelled'
        }
    };
    return statusStyles[status] || statusStyles['pending'];
};

// Calculate totals
const totals = computed(() => {
    return {
        qty: props.items.reduce((sum, item) => sum + (item.wastage_qty || 0), 0),
        cost: props.items.reduce((sum, item) => sum + ((item.wastage_qty || 0) * (item.cost || 0)), 0),
        items: props.items.length
    };
});
</script>

<template>
    <Layout>
        <!-- Header -->
        <div class="mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <button
                        @click="router.back()"
                        class="flex items-center gap-2 text-gray-600 hover:text-gray-900 transition-colors"
                    >
                        <ChevronLeft class="w-5 h-5" />
                        Back to Report
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Wastage Report Details</h1>
                        <p class="text-gray-600">Wastage #{{ wastage.wastage_no }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        v-if="hasAccess('export wastage report')"
                        @click="window.open(route('reports.wastage-report.export'))"
                        class="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
                    >
                        <Download class="w-4 h-4" />
                        Export
                    </button>
                </div>
            </div>
        </div>

        <!-- Wastage Summary Card -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Summary</h2>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full border text-xs font-medium" :class="getStatusClass(wastage.status).bg">
                    <div :class="['w-2 h-2 rounded-full', getStatusClass(wastage.status).dot]"></div>
                    <span :class="getStatusClass(wastage.status).text">
                        {{ getStatusClass(wastage.status).label }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-sm text-gray-600 mb-1">Store</p>
                    <p class="text-lg font-semibold text-gray-900">{{ wastage.store || 'N/A' }}</p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-sm text-gray-600 mb-1">Total Quantity</p>
                    <p class="text-lg font-semibold text-gray-900">{{ formatNumber(totals.qty) }}</p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-sm text-gray-600 mb-1">Number of Items</p>
                    <p class="text-lg font-semibold text-gray-900">{{ totals.items }}</p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-sm text-gray-600 mb-1">Total Cost</p>
                    <p class="text-lg font-semibold text-gray-900">{{ formatCurrency(totals.cost) }}</p>
                </div>
            </div>

            <div class="mt-4 text-sm text-gray-600">
                <p>Created: {{ wastage.formatted_date || 'N/A' }}</p>
            </div>
        </div>

        <!-- Items Table -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Items</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr class="text-xs text-gray-500 uppercase tracking-wider">
                            <th class="px-6 py-4 text-left font-medium">Item Code</th>
                            <th class="px-6 py-4 text-left font-medium">Description</th>
                            <th class="px-6 py-4 text-center font-medium">Quantity</th>
                            <th class="px-6 py-4 text-right font-medium">Unit Cost</th>
                            <th class="px-6 py-4 text-right font-medium">Total Cost</th>
                            <th class="px-6 py-4 text-left font-medium">Reason</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <tr v-if="items.length === 0" class="hover:bg-gray-50">
                            <td colspan="6" class="text-center py-12 text-gray-500">
                                <div class="flex flex-col items-center">
                                    <FileText class="w-12 h-12 text-gray-300 mb-3" />
                                    <span class="text-lg font-medium">No items found</span>
                                    <span class="text-sm text-gray-400 mt-1">This wastage record has no items</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="(item, index) in items" :key="index" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 text-sm font-mono text-gray-900">
                                {{ item.sap_masterfile?.ItemCode || 'N/A' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900 max-w-xs truncate" :title="item.sap_masterfile?.ItemDescription">
                                {{ item.sap_masterfile?.ItemDescription || 'N/A' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-center font-medium text-gray-900">
                                {{ formatNumber(item.wastage_qty || 0) }}
                                <span class="text-xs text-gray-500 ml-1">
                                    {{ item.sap_masterfile?.BaseUOM || 'N/A' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-right font-medium text-gray-900">
                                {{ formatCurrency(item.cost || 0) }}
                            </td>
                            <td class="px-6 py-4 text-sm text-right font-semibold text-gray-900">
                                {{ formatCurrency((item.wastage_qty || 0) * (item.cost || 0)) }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate" :title="item.reason">
                                {{ item.reason || 'N/A' }}
                            </td>
                        </tr>
                    </tbody>

                    <!-- Footer with totals -->
                    <tfoot v-if="items.length > 0" class="bg-gray-50 border-t-2 border-gray-200">
                        <tr>
                            <th class="px-6 py-4 text-left font-semibold text-gray-900">TOTAL</th>
                            <th colspan="2" class="px-6 py-4 text-center font-semibold text-gray-900">
                                {{ formatNumber(totals.qty) }}
                            </th>
                            <th class="px-6 py-4 text-right font-semibold text-gray-900">-</th>
                            <th class="px-6 py-4 text-right font-semibold text-gray-900">
                                {{ formatCurrency(totals.cost) }}
                            </th>
                            <th class="px-6 py-4 text-left"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </Layout>
</template>