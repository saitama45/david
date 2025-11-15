<script setup>
import { useForm } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import Select from "primevue/select";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { ref, computed } from 'vue';

const toast = useToast();
const confirm = useConfirm();

const props = defineProps({
    item: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    existingIngredients: { type: Array, default: () => [] },
});

const isLoading = ref(false);

// Main form (FG Details)
const item = props.item;
const form = useForm({
    POSCode: item.POSCode ?? null, // Changed from ItemCode
    POSDescription: item.POSDescription ?? null, // Changed from ItemDescription
    POSName: item.POSName ?? null, // New POSName field
    Category: item.Category ?? null,
    SubCategory: item.SubCategory ?? null,
    SRP: item.SRP ?? 0,
    DeliveryPrice: item.DeliveryPrice ?? 0,
    TableVibePrice: item.TableVibePrice ?? 0,
    is_active: item.is_active !== null ? Number(item.is_active) : null,
});

const ingredientsDisplay = ref([]);
props.existingIngredients.forEach((ingredient) => {
    ingredientsDisplay.value.push({
        id: ingredient.id,
        sap_masterfile_id: ingredient.sap_masterfile_id ?? null,
        inventory_code: ingredient.inventory_code,
        name: ingredient.name,
        quantity: Number(ingredient.quantity),
        uom: ingredient.uom,
        assembly: ingredient.assembly ?? null,
        unit_cost: ingredient.unit_cost ?? null,
        total_cost: ingredient.total_cost ?? null,
    });
});

const { options: productsOption } = useSelectOptions(props.products);
const { options: categoriesOption } = useSelectOptions(props.categories);

const activeStatuses = ref([
    { label: "Active", value: 1 },
    { label: "Inactive", value: 0 },
]);

// Sorting state & logic (for Ingredients table)
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

        // numeric compare if both parse as numbers
        const numA = parseFloat(valA);
        const numB = parseFloat(valB);
        const bothNumbers = !Number.isNaN(numA) && !Number.isNaN(numB);

        if (bothNumbers) {
            return sortDirection.value === 'asc' ? numA - numB : numB - numA;
        }

        // fallback string compare
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

// Update handler for FG Details
const handleUpdate = () => {
    confirm.require({
        message: "Are you sure you want to update this product's FG Details?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "success",
        },
        accept: () => {
            form.put(route("POSMasterfile.update", item.id), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "FG Details Successfully Updated",
                        life: 3000,
                    });
                },
                onError: (e) => {
                    console.error(e);
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "An error occurred while updating the FG details.",
                        life: 3000,
                    });
                },
            });
        },
    });
};
</script>

<template>
    <Layout heading="Edit BOM Details">
        <template #header-actions>
            <BackButton />
        </template>

        <div class="flex flex-col gap-5 p-5">
            <!-- FG Details Card -->
            <Card class="w-full">
                <CardHeader>
                    <CardTitle>FG Details</CardTitle>
                    <CardDescription>Input all the important fields</CardDescription>
                </CardHeader>

                <CardContent class="grid sm:grid-cols-2 gap-5">
                    <InputContainer>
                        <Label>POS Code</Label> <!-- Changed from ItemCode -->
                        <Input v-model="form.POSCode" /> <!-- Changed from form.ItemCode -->
                        <FormError v-if="form.errors.POSCode">{{ form.errors.POSCode }}</FormError> <!-- Changed from form.errors.ItemCode -->
                    </InputContainer>

                    <InputContainer>
                        <Label>POS Desc</Label> <!-- Changed from Item Desc -->
                        <Input v-model="form.POSDescription" /> <!-- Changed from form.ItemDescription -->
                        <FormError v-if="form.errors.POSDescription">{{ form.errors.POSDescription }}</FormError> <!-- Changed from form.errors.ItemDescription -->
                    </InputContainer>

                    <InputContainer>
                        <Label>POS Name</Label> <!-- New POS Name field -->
                        <Input v-model="form.POSName" />
                        <FormError v-if="form.errors.POSName">{{ form.errors.POSName }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <Label>Category</Label>
                        <Select
                            v-model="form.Category"
                            :options="categoriesOption"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select a Category"
                        />
                        <FormError v-if="form.errors.Category">{{ form.errors.Category }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <Label>Sub Category</Label>
                        <Input v-model="form.SubCategory" />
                        <FormError v-if="form.errors.SubCategory">{{ form.errors.SubCategory }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <Label>SRP</Label>
                        <Input v-model="form.SRP" type="number" />
                        <FormError v-if="form.errors.SRP">{{ form.errors.SRP }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <Label>Delivery Price</Label>
                        <Input v-model="form.DeliveryPrice" type="number" />
                        <FormError v-if="form.errors.DeliveryPrice">{{ form.errors.DeliveryPrice }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <Label>Table Vibe Price</Label>
                        <Input v-model="form.TableVibePrice" type="number" />
                        <FormError v-if="form.errors.TableVibePrice">{{ form.errors.TableVibePrice }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Active Status</LabelXS>
                        <Select
                            v-model="form.is_active"
                            :options="activeStatuses"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select a Status"
                        />
                        <FormError v-if="form.errors.is_active">{{ form.errors.is_active }}</FormError>
                    </InputContainer>
                </CardContent>

                <CardFooter class="justify-end">
                    <Button @click="handleUpdate" :loading="isLoading">Update FG Details</Button>
                </CardFooter>
            </Card>

            <!-- Ingredients Table (fixed layout + sorting + caret + active highlight) -->
            <div class="bg-white border rounded-md shadow-sm w-full">
                <div class="px-4 py-3 border-b">
                    <span class="font-semibold text-gray-700">Ingredients</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full table-fixed" style="table-layout: fixed;">
                        <colgroup>
                            <col style="width:16.6666%" />
                            <col style="width:33.3333%" />
                            <col style="width:16.6666%" />
                            <col style="width:16.6666%" />
                            <col style="width:16.6666%" />
                        </colgroup>

                        <thead class="bg-white">
                            <tr class="text-sm text-gray-600">
                                <th
                                    class="px-4 py-3 text-left cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'inventory_code'}"
                                    @click="sortIngredients('inventory_code')"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="truncate">Item Code</span>
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
                                    class="px-4 py-3 text-left cursor-pointer select-none"
                                    :class="{'bg-gray-50': sortColumn === 'quantity'}"
                                    @click="sortIngredients('quantity')"
                                >
                                    <div class="flex items-center justify-between">
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
                    </div>
                </div>

                <div class="px-4 py-3 text-sm text-gray-700">
                    <p>Total Items: <span class="font-bold">{{ ingredientsDisplay.length }}</span></p>
                </div>
            </div>
        </div>
    </Layout>
</template>

