<script setup>
import CardContent from "@/Components/ui/card/CardContent.vue";
import { useBackButton } from "@/Composables/useBackButton";
import { ref, computed } from 'vue';

const { backButton } = useBackButton(route("POSMasterfile.index"));

const props = defineProps({
    item: { type: Object, required: true },
    existingIngredients: { type: Array, default: () => [] },
});

// Initialize reactive list
const ingredientsDisplay = ref([]);
props.existingIngredients.forEach((ingredient) => {
    ingredientsDisplay.value.push({
        id: ingredient.id,
        inventory_code: ingredient.inventory_code,
        name: ingredient.name,
        quantity: Number(ingredient.quantity),
        uom: ingredient.uom,
        assembly: ingredient.assembly ?? null,
    });
});

// Sorting state
const sortColumn = ref(null);
const sortDirection = ref('asc');

const sortedIngredients = computed(() => {
    if (!sortColumn.value) return ingredientsDisplay.value;
    const sorted = [...ingredientsDisplay.value].sort((a, b) => {
        let valA = a[sortColumn.value];
        let valB = b[sortColumn.value];

        // normalize null/undefined
        if (valA == null) valA = (typeof valB === 'number' ? 0 : '');
        if (valB == null) valB = (typeof valA === 'number' ? 0 : '');

        // if both values are numeric (or parseable to number), sort numerically
        const numA = parseFloat(valA);
        const numB = parseFloat(valB);
        const bothNumbers = !Number.isNaN(numA) && !Number.isNaN(numB);

        if (bothNumbers) {
            return sortDirection.value === 'asc' ? numA - numB : numB - numA;
        }

        // fallback to string comparison
        const A = String(valA);
        const B = String(valB);
        return sortDirection.value === 'asc' ? A.localeCompare(B) : B.localeCompare(A);
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
</script>

<template>
    <Layout heading="POSMasterfile Details">
        <template #header-actions>
            <BackButton />
        </template>

        <div class="flex flex-col gap-5 p-5">
            <!-- FG Details Section (FULL as requested) -->
            <Card class="w-full">
                <CardHeader>
                    <CardTitle class="text-xl">
                        {{ item.POSDescription ?? "N/a" }} <!-- Changed from item.ItemDescription -->
                    </CardTitle>
                </CardHeader>
                <CardContent class="grid sm:grid-cols-2 gap-3">
                    <Label>POS Code</Label> <!-- Changed from Item Code -->
                    <Label class="font-bold">{{ item.POSCode ?? "N/a" }}</Label> <!-- Changed from item.ItemCode -->

                    <Label>POS Desc</Label> <!-- Changed from Item Desc -->
                    <Label class="font-bold">{{ item.POSDescription ?? "N/a" }}</Label> <!-- Changed from item.ItemDescription -->

                    <Label>Category</Label>
                    <Label class="font-bold">{{ item.Category ?? "N/a" }}</Label>

                    <Label>SubCategory</Label>
                    <Label class="font-bold">{{ item.SubCategory ?? "N/a" }}</Label>

                    <Label>SRP</Label>
                    <Label class="font-bold">{{ item.SRP ?? "N/a" }}</Label>

                    <Label>Is Active</Label>
                    <Label class="font-bold">{{ item.is_active == 1 ? "Yes" : "No" }}</Label>
                </CardContent>
            </Card>

            <!-- Ingredients Table: using native table + colgroup for locked widths -->
            <div class="bg-white border rounded-md shadow-sm">
                <div class="px-4 py-3 border-b">
                    <span class="font-semibold text-gray-700">Ingredients</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full table-fixed" style="table-layout: fixed;">
                        <!-- Important: colgroup fixes widths for header and body -->
                        <colgroup>
                            <col style="width:16.6666%" />
                            <col style="width:33.3333%" />
                            <col style="width:16.6666%" />
                            <col style="width:16.6666%" />
                            <col style="width:16.6666%" />
                        </colgroup>

                        <thead class="bg-white">
                            <tr class="text-sm text-gray-600">
                                <!-- Item Code -->
                                <th
                                    class="px-4 py-3 text-left cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'inventory_code'}"
                                    @click="sortIngredients('inventory_code')"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="truncate">Item Code</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'inventory_code' || sortColumn === null">
                                            <!-- show caret only for active; if you want caret always, remove condition -->
                                            <svg v-if="sortColumn === 'inventory_code' && sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else-if="sortColumn === 'inventory_code' && sortDirection === 'desc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>

                                <!-- Name -->
                                <th
                                    class="px-4 py-3 text-left cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'name'}"
                                    @click="sortIngredients('name')"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="truncate">Name</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'name'">
                                            <svg v-if="sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>

                                <!-- Assembly -->
                                <th
                                    class="px-4 py-3 text-left cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'assembly'}"
                                    @click="sortIngredients('assembly')"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="truncate">Assembly</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'assembly'">
                                            <svg v-if="sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>

                                <!-- Quantity -->
                                <th
                                    class="px-4 py-3 text-left cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'quantity'}"
                                    @click="sortIngredients('quantity')"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="truncate">Quantity</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'quantity'">
                                            <svg v-if="sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>

                                <!-- UOM -->
                                <th
                                    class="px-4 py-3 text-left cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'uom'}"
                                    @click="sortIngredients('uom')"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="truncate">UOM</span>
                                        <span class="ml-2 flex-shrink-0" v-if="sortColumn === 'uom'">
                                            <svg v-if="sortDirection === 'asc'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 15l6-6 6 6"></path>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </span>
                                    </div>
                                </th>
                            </tr>
                        </thead>

                        <tbody class="text-sm text-gray-700">
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
                                    class="px-4 py-3 align-top whitespace-nowrap"
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
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- mobile condensed view -->
                <div class="md:hidden p-4 space-y-3">
                    <div v-for="ingredient in sortedIngredients" :key="'m-' + ingredient.id" class="border rounded p-3">
                        <div class="font-semibold truncate">{{ ingredient.name }} <span class="text-sm font-normal">({{ ingredient.inventory_code }})</span></div>
                        <div class="text-sm text-gray-600">Assembly: {{ ingredient.assembly ?? '-' }}</div>
                        <div class="text-sm text-gray-600">UOM: {{ ingredient.uom }}</div>
                        <div class="text-sm text-gray-600">Quantity: {{ ingredient.quantity }}</div>
                    </div>
                </div>

                <div class="px-4 py-3 text-sm text-gray-700">
                    <p>Total Items: <span class="font-bold">{{ ingredientsDisplay.length }}</span></p>
                </div>
            </div>
        </div>
    </Layout>
</template>

