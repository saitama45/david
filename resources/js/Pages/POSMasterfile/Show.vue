<script setup>
import { ref, computed } from 'vue'; // Added computed
import { router } from '@inertiajs/vue3';
import { Edit, Package } from 'lucide-vue-next'; // Using Lucide icons for better visuals

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    existingIngredients: {
        type: Array,
        default: () => [],
    },
});

const handleEditClick = () => {
    router.get(route('POSMasterfile.edit', props.item.id));
};

// Helper to format currency (example, adjust as needed)
const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return parseFloat(value).toLocaleString('en-US', {
        style: 'currency',
        currency: 'PHP', // Assuming Philippine Peso based on location
    });
};

// Helper to check if a value is active
const isActiveText = (value) => {
    return Number(value) ? 'Yes' : 'No';
};

// --- Start of BOM Ingredients Sorting Logic (Copied from Edit.vue) ---
const sortColumn = ref(null);
const sortDirection = ref('asc');

const sortedIngredients = computed(() => {
    if (!sortColumn.value) return props.existingIngredients;

    const sorted = [...props.existingIngredients].sort((a, b) => {
        let valA = a[sortColumn.value];
        let valB = b[sortColumn.value];

        // Normalize null/undefined to allow consistent sorting
        if (valA == null) valA = (typeof valB === 'number' ? 0 : '');
        if (valB == null) valB = (typeof valA === 'number' ? 0 : '');

        // Numeric compare if both parse as numbers
        const numA = parseFloat(valA);
        const numB = parseFloat(valB);
        const bothNumbers = !Number.isNaN(numA) && !Number.isNaN(numB);

        if (bothNumbers) {
            return sortDirection.value === 'asc' ? numA - numB : numB - numA;
        }

        // Fallback string compare
        const A = String(valA).toLowerCase();
        const B = String(valB).toLowerCase();
        if (A < B) return sortDirection.value === 'asc' ? -1 : 1;
        if (A > B) return sortDirection.value === 'asc' ? 1 : -1;
        return 0;
    });

    return sorted;
});

const sortIngredients = (column) => {
    if (sortColumn.value === column) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortColumn.value = column;
        sortDirection.value = 'asc';
    }
};
// --- End of BOM Ingredients Sorting Logic ---
</script>

<template>
    <Layout :heading="`POS Masterfile Details: ${item.POSDescription}`">
        <div class="p-6 bg-white rounded-lg shadow-md space-y-6">

            <!-- Item Details Section -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 border-t pt-4">
                <div class="detail-item">
                    <span class="font-semibold text-gray-600">POS Code:</span>
                    <span class="text-gray-900">{{ item.POSCode }}</span>
                </div>
                <div class="detail-item">
                    <span class="font-semibold text-gray-600">POS Description:</span>
                    <span class="text-gray-900">{{ item.POSDescription }}</span>
                </div>
                <div class="detail-item">
                    <span class="font-semibold text-gray-600">Category:</span>
                    <span class="text-gray-900">{{ item.Category || 'N/A' }}</span>
                </div>
                <div class="detail-item">
                    <span class="font-semibold text-gray-600">SubCategory:</span>
                    <span class="text-gray-900">{{ item.SubCategory || 'N/A' }}</span>
                </div>
                <div class="detail-item">
                    <span class="font-semibold text-gray-600">SRP:</span>
                    <span class="text-gray-900">{{ formatCurrency(item.SRP) }}</span>
                </div>
                <div class="detail-item">
                    <span class="font-semibold text-gray-600">Delivery Price:</span>
                    <span class="text-gray-900">{{ formatCurrency(item.DeliveryPrice) }}</span>
                </div>
                <div class="detail-item">
                    <span class="font-semibold text-gray-600">Table Vibe Price:</span>
                    <span class="text-gray-900">{{ formatCurrency(item.TableVibePrice) }}</span>
                </div>
                <div class="detail-item">
                    <span class="font-semibold text-gray-600">Active:</span>
                    <span class="text-gray-900">{{ isActiveText(item.is_active) }}</span>
                </div>
            </div>

            <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2 mt-8 border-t pt-6">
                <Package class="w-5 h-5 text-gray-600" />
                Bill of Materials (BOM) Ingredients
            </h3>

            <!-- Ingredients Table (adapted from Edit.vue) -->
            <div class="bg-white border rounded-md shadow-sm w-full">
                <div class="px-4 py-3 border-b">
                    <span class="font-semibold text-gray-700">Ingredients Overview</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full table-fixed" style="table-layout: fixed;">
                        <colgroup>
                            <!-- Adjusted colgroup for 7 columns -->
                            <col style="width:14.28%" /> <!-- Inventory Code -->
                            <col style="width:25.72%" /> <!-- Name -->
                            <col style="width:10%" /> <!-- Assembly -->
                            <col style="width:10%" /> <!-- Quantity -->
                            <col style="width:10%" /> <!-- UOM -->
                            <col style="width:15%" /> <!-- Unit Cost -->
                            <col style="width:15%" /> <!-- Total Cost -->
                        </colgroup>

                        <thead class="bg-white">
                            <tr class="text-sm text-gray-600">
                                <th
                                    class="px-4 py-3 text-left cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'inventory_code'}"
                                    @click="sortIngredients('inventory_code')"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="truncate">Inventory Code</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'inventory_code'">
                                            <svg v-if="sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>

                                <th
                                    class="px-4 py-3 text-left cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'name'}"
                                    @click="sortIngredients('name')"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="truncate">Name</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'name'">
                                            <svg v-if="sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>

                                <th
                                    class="px-4 py-3 text-left cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'assembly'}"
                                    @click="sortIngredients('assembly')"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="truncate">Assembly</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'assembly'">
                                            <svg v-if="sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>

                                <th
                                    class="px-4 py-3 text-right cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'quantity'}"
                                    @click="sortIngredients('quantity')"
                                >
                                    <div class="flex items-center justify-end">
                                        <span class="truncate">Quantity</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'quantity'">
                                            <svg v-if="sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>

                                <th
                                    class="px-4 py-3 text-left cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'uom'}"
                                    @click="sortIngredients('uom')"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="truncate">UOM</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'uom'">
                                            <svg v-if="sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>

                                <th
                                    class="px-4 py-3 text-right cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'unit_cost'}"
                                    @click="sortIngredients('unit_cost')"
                                >
                                    <div class="flex items-center justify-end">
                                        <span class="truncate">Unit Cost</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'unit_cost'">
                                            <svg v-if="sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>

                                <th
                                    class="px-4 py-3 text-right cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'total_cost'}"
                                    @click="sortIngredients('total_cost')"
                                >
                                    <div class="flex items-center justify-end">
                                        <span class="truncate">Total Cost</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'total_cost'">
                                            <svg v-if="sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>
                            </tr>
                        </thead>

                        <tbody class="text-sm text-gray-700">
                            <tr v-if="sortedIngredients.length === 0">
                                <td colspan="7" class="text-center p-4 text-gray-500">No BOM ingredients defined for this POS Masterfile item.</td>
                            </tr>
                            <tr v-for="ingredient in sortedIngredients" :key="ingredient.id" class="border-t">
                                <td
                                    class="px-4 py-3 align-top whitespace-nowrap overflow-hidden text-ellipsis"
                                    :class="{'bg-gray-50': sortColumn === 'inventory_code'}"
                                >
                                    {{ ingredient.inventory_code }}
                                </td>

                                <td
                                    class="px-4 py-3 align-top"
                                    :class="{'bg-gray-50': sortColumn === 'name'}"
                                >
                                    <div class="truncate" :title="ingredient.name">{{ ingredient.name }}</div>
                                </td>

                                <td
                                    class="px-4 py-3 align-top whitespace-nowrap overflow-hidden text-ellipsis"
                                    :class="{'bg-gray-50': sortColumn === 'assembly'}"
                                >
                                    {{ ingredient.assembly ?? '-' }}
                                </td>

                                <td
                                    class="px-4 py-3 align-top whitespace-nowrap text-right"
                                    :class="{'bg-gray-50': sortColumn === 'quantity'}"
                                >
                                    {{ ingredient.quantity }}
                                </td>

                                <td
                                    class="px-4 py-3 align-top whitespace-nowrap"
                                    :class="{'bg-gray-50': sortColumn === 'uom'}"
                                >
                                    {{ ingredient.uom }}
                                </td>

                                <td
                                    class="px-4 py-3 align-top whitespace-nowrap text-right"
                                    :class="{'bg-gray-50': sortColumn === 'unit_cost'}"
                                >
                                    {{ formatCurrency(ingredient.unit_cost) }}
                                </td>

                                <td
                                    class="px-4 py-3 align-top whitespace-nowrap text-right"
                                    :class="{'bg-gray-50': sortColumn === 'total_cost'}"
                                >
                                    {{ formatCurrency(ingredient.total_cost) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile condensed view -->
                <div class="md:hidden p-4 space-y-3">
                    <div v-for="ingredient in sortedIngredients" :key="'m-' + ingredient.id" class="border rounded p-3">
                        <div class="font-semibold truncate">
                            {{ ingredient.name }}
                            <span class="text-sm font-normal">({{ ingredient.inventory_code }})</span>
                        </div>
                        <div class="text-sm text-gray-600">Assembly: {{ ingredient.assembly ?? '-' }}</div>
                        <div class="text-sm text-gray-600">UOM: {{ ingredient.uom }}</div>
                        <div class="text-sm text-gray-600">Quantity: {{ ingredient.quantity }}</div>
                        <div class="text-sm text-gray-600">Unit Cost: {{ formatCurrency(ingredient.unit_cost) }}</div>
                        <div class="text-sm text-gray-600">Total Cost: {{ formatCurrency(ingredient.total_cost) }}</div>
                    </div>
                </div>

                <div class="px-4 py-3 text-sm text-gray-700 border-t">
                    <p>Total Ingredients: <span class="font-bold">{{ existingIngredients.length }}</span></p>
                </div>
            </div>
        </div>
    </Layout>
</template>

<style scoped>
.detail-item {
    @apply flex flex-col p-2 bg-gray-50 rounded-md;
}
.detail-item span:first-child {
    @apply text-sm uppercase;
}
.detail-item span:last-child {
    @apply text-lg font-medium;
}
</style>
