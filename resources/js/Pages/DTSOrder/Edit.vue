<script setup>
import { router, useForm } from "@inertiajs/vue3";
import { ref, watch, computed, onMounted, nextTick } from 'vue';
import { MinusCircle, PlusCircle, Trash2, Calendar as CalendarIcon, Loader2 } from 'lucide-vue-next';
import { useToast } from "@/components/ui/toast/";
import ConfirmationModal from '@/components/ConfirmationModal.vue';
import axios from 'axios';

const props = defineProps({
    order: { type: Object, required: true },
    branches: { type: Array, required: true },
    dtsSupplier: { type: Object, required: true },
    variants: { type: Array, required: true },
    deliverySchedules: { type: Object, required: true },
});

const { toast } = useToast();

const form = useForm({
    supplier_id: String(props.dtsSupplier.value),
    items: props.order.store_order_items.map(item => ({
        store_branch_id: props.order.store_branch_id, // From main order
        variant: props.order.variant, // From main order
        order_date: props.order.order_date, // From main order
        initial_item_code: item.item_code, // Store the item_code temporarily
        item_id: null, // Initialize as null, will be populated in onMounted
        quantity: item.quantity_ordered,
        cost: item.cost_per_quantity,
        uom: item.uom,
    })),
});

// --- Global State ---
const isVariantLocked = ref(false);
const lockedVariant = ref(null);
const specialVariants = ['ICE CREAM', 'SALMON', 'FRUITS AND VEGETABLES'];
const isAddingNewItem = ref(false); // Flag to prevent watcher race condition

// --- Per-Row State Management ---
const rowItems = ref({});
const loadingItems = ref({});
const rowSchedules = ref({});
const loadingSchedules = ref({});
const branchSearchQueries = ref([]);
const itemSearchQueries = ref([]);
const isBranchSelectOpen = ref([]);
const isItemSelectOpen = ref([]);
const showCalendar = ref([]);
const currentCalendarDate = ref([]);

const showConfirmationModal = ref(false); // Add this line

const dayMap = { 'SUNDAY': 0, 'MONDAY': 1, 'TUESDAY': 2, 'WEDNESDAY': 3, 'THURSDAY': 4, 'FRIDAY': 5, 'SATURDAY': 6 };

// --- Date & Calendar Logic ---
const getAvailableDays = (index) => {
    const schedule = rowSchedules.value[index] || [];
    return schedule.map(dayName => dayMap[dayName.toUpperCase()]).filter(day => day !== undefined);
};

const getDisabledDates = (index) => {
    const availableDaysArray = getAvailableDays(index);
    if (availableDaysArray.length === 0 && (form.items[index].store_branch_id && form.items[index].variant)) return [0, 1, 2, 3, 4, 5, 6];
    const allDays = [0, 1, 2, 3, 4, 5, 6];
    return allDays.filter(day => !availableDaysArray.includes(day));
};

const getCalendarDays = (index) => {
    const days = [];
    const dateRef = currentCalendarDate.value[index] || new Date();
    const year = dateRef.getFullYear();
    const month = dateRef.getMonth();
    const firstDayOfMonth = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const disabledWeekdays = getDisabledDates(index);

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    // Calculate the end of the current week (Saturday)
    const endOfWeek = new Date(today);
    endOfWeek.setDate(today.getDate() + (6 - today.getDay()));

    for (let i = 0; i < firstDayOfMonth; i++) days.push(null);

    for (let i = 1; i <= daysInMonth; i++) {
        const date = new Date(year, month, i);
        const dayOfWeek = date.getDay();

        // A date is disabled if it's a non-delivery day, or if it falls within the current week.
        const isDisabled = disabledWeekdays.includes(dayOfWeek) || date <= endOfWeek;
        
        days.push({ day: i, date, isDisabled });
    }
    return days;
};

const goToPrevMonth = (index) => currentCalendarDate.value[index] = new Date(currentCalendarDate.value[index].getFullYear(), currentCalendarDate.value[index].getMonth() - 1, 1);
const goToNextMonth = (index) => currentCalendarDate.value[index] = new Date(currentCalendarDate.value[index].getFullYear(), currentCalendarDate.value[index].getMonth() + 1, 1);

const selectDate = (index, day) => {
    if (day && !day.isDisabled) {
        const d = day.date;
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const dayOfMonth = String(d.getDate()).padStart(2, '0');
        form.items[index].order_date = `${year}-${month}-${dayOfMonth}`;
        showCalendar.value[index] = false;
    }
};

const selectedItemLabel = (index) => {
    const itemId = form.items[index].item_id;
    if (!itemId) return "Select Item..."; // Default placeholder if no item is selected

    const selected = (rowItems.value[index] || []).find(item => String(item.value) === String(itemId));
    return selected ? selected.label : "Select Item..."; // Return label or default
};

// --- Data Fetching Logic ---
const fetchSchedule = async (index) => {
    const item = form.items[index];
    if (!item.store_branch_id || !item.variant) {
        rowSchedules.value[index] = [];
        return;
    }
    loadingSchedules.value[index] = true;
    try {
        const response = await axios.get(route('dts-orders.get-schedule'), {
            params: { store_branch_id: item.store_branch_id, variant: item.variant }
        });
        rowSchedules.value[index] = response.data;
    } catch (error) {
        toast({ title: 'Error', description: 'Could not fetch delivery schedule.', variant: 'destructive' });
        rowSchedules.value[index] = [];
    } finally {
        loadingSchedules.value[index] = false;
    }
};

const fetchItemsByVariant = async (index, variant) => {
    if (!variant) {
        rowItems.value[index] = [];
        return;
    }
    loadingItems.value[index] = true;
    try {
        const response = await axios.get(route('dts-orders.get-items-by-variant'), { params: { variant } });
        rowItems.value[index] = response.data;
    } catch (error) {
        toast({ title: 'Error', description: 'Failed to load items.', variant: 'destructive' });
    } finally {
        loadingItems.value[index] = false;
    }
};

// --- Watchers for Reactivity ---
watch(() => form.items.map(item => ({ branch: item.store_branch_id, variant: item.variant })), (newPairs, oldPairs) => {
    if (isAddingNewItem.value) return;

    newPairs.forEach((newPair, index) => {
        const oldPair = oldPairs[index] || {};
        if (newPair.branch !== oldPair.branch || newPair.variant !== oldPair.variant) {
            form.items[index].order_date = null;
            form.items[index].item_id = null;
            form.items[index].uom = null;
            itemSearchQueries.value[index] = '';
            fetchSchedule(index);
            if (newPair.variant) {
                if (specialVariants.includes(newPair.variant)) {
                    isVariantLocked.value = true;
                    lockedVariant.value = newPair.variant;
                }
                fetchItemsByVariant(index, newPair.variant);
            } else {
                if (!form.items.some(item => specialVariants.includes(item.variant))) {
                    isVariantLocked.value = false;
                    lockedVariant.value = null;
                }
            }
        }
    });
}, { deep: true });

// --- General Functions ---
const filteredBranches = (index) => {
    const query = branchSearchQueries.value[index] || '';
    return props.branches.filter(branch => branch.label.toLowerCase().includes(query.toLowerCase()));
};

const filteredItems = (index) => {
    const query = itemSearchQueries.value[index] || '';
    const items = rowItems.value[index] || [];
    return items.filter(item => item.label.toLowerCase().includes(query.toLowerCase()));
};

const handleItemSelection = (index, itemId) => {
    const selectedItem = (rowItems.value[index] || []).find(item => item.value === Number(itemId));
    if (selectedItem) {
        form.items[index].item_id = String(itemId);
        form.items[index].uom = selectedItem.alt_uom;
    }
    itemSearchQueries.value[index] = '';
    isItemSelectOpen.value[index] = false;
};

const addItem = async () => {
    isAddingNewItem.value = true;

    const lastItem = form.items[form.items.length - 1];
    const newItem = {
        store_branch_id: lastItem?.store_branch_id || null,
        variant: isVariantLocked.value ? lockedVariant.value : (lastItem?.variant || null),
        order_date: lastItem?.order_date || null,
        item_id: null, // New item should not have an item_id pre-filled
        quantity: 0,
        cost: 0,
        uom: null,
    };

    if (form.items.length > 0) {
        const prevIndex = form.items.length - 1;
        const newIndex = form.items.length;
        if (rowItems.value[prevIndex]) {
            rowItems.value[newIndex] = rowItems.value[prevIndex];
        }
        if (rowSchedules.value[prevIndex]) {
            rowSchedules.value[newIndex] = rowSchedules.value[prevIndex];
        }
    }
    
    form.items.push(newItem);
    currentCalendarDate.value.push(new Date());

    await nextTick();
    isAddingNewItem.value = false;
};

const removeItem = (index) => {
    form.items.splice(index, 1);
    if (!form.items.some(item => specialVariants.includes(item.variant))) {
        isVariantLocked.value = false;
        lockedVariant.value = null;
    }
    const stateArrays = [rowItems, loadingItems, rowSchedules, loadingSchedules, branchSearchQueries, itemSearchQueries, isBranchSelectOpen, isItemSelectOpen, showCalendar, currentCalendarDate];
    stateArrays.forEach(stateRef => {
        if (Array.isArray(stateRef.value)) {
            stateRef.value.splice(index, 1);
        } else {
            delete stateRef.value[index];
        }
    });
};

const submit = () => {
    showConfirmationModal.value = true;
};

const proceedWithSubmit = () => {
    const submissionData = {
        items: form.items.map(item => ({
            store_branch_id: item.store_branch_id,
            variant: item.variant,
            order_date: item.order_date,
            item_id: item.item_id,
            quantity: item.quantity,
            cost: item.cost,
        }))
    };

    form.put(route('dts-orders.update', props.order.order_number), {
        data: submissionData,
        onSuccess: () => {
            showConfirmationModal.value = false; // Close modal on success
            toast({
                title: 'Success!',
                description: 'DTS order updated successfully.',
                variant: 'success',
            });
        },
        onError: (errors) => {
            showConfirmationModal.value = false; // Close modal on error
            console.error('Form errors:', errors);
            const errorMessages = Object.entries(errors).map(([, value]) => `<li>${value}</li>`).join('');
            toast({ title: 'Error Updating Order', description: `<ul class="list-disc pl-5">${errorMessages}</ul>`, variant: 'destructive', duration: 9000, isRaw: true });
        },
    });
};

const formatCurrency = (value) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);
const totalCostPerItem = (index) => (form.items[index].quantity || 0) * (form.items[index].cost || 0);
const overallTotal = computed(() => form.items.reduce((sum, item) => sum + ((item.quantity || 0) * (item.cost || 0)), 0));

onMounted(async () => {
    // Initialize per-item state for existing items
    for (let index = 0; index < form.items.length; index++) {
        const item = form.items[index];
        currentCalendarDate.value[index] = new Date(item.order_date);
        branchSearchQueries.value[index] = '';
        itemSearchQueries.value[index] = '';
        isBranchSelectOpen.value[index] = false;
        isItemSelectOpen.value[index] = false;
        showCalendar.value[index] = false;

        // Pre-fetch items and schedules for existing data
        await fetchSchedule(index);
        await fetchItemsByVariant(index, item.variant); // rowItems.value[index] is now populated

        // Find the item_id based on initial_item_code
        const foundItem = (rowItems.value[index] || []).find(
            (ri) => ri.item_code === item.initial_item_code
        );

        if (foundItem) {
            form.items[index].item_id = String(foundItem.value); // Set the actual item_id (SAPMasterfile ID)
            form.items[index].uom = foundItem.alt_uom; // Also set UOM
        }

        // Check for variant locking on mount
        if (specialVariants.includes(item.variant)) {
            isVariantLocked.value = true;
            lockedVariant.value = item.variant;
        }
    }
});
</script>

<template>
    <Layout heading="Edit DTS Order">
        <form @submit.prevent="submit" class="space-y-6">
            <Card>
                <CardHeader><CardTitle>DTS Order Details</CardTitle></CardHeader>
                <CardContent>
                    <InputContainer>
                        <Label>Supplier</Label>
                        <SelectShad :model-value="String(dtsSupplier.value)" disabled>
                            <SelectTrigger><SelectValue :placeholder="dtsSupplier.label" /></SelectTrigger>
                            <SelectContent><SelectItem :value="String(dtsSupplier.value)">{{ dtsSupplier.label }}</SelectItem></SelectContent>
                        </SelectShad>
                    </InputContainer>
                </CardContent>
            </Card>

            <!-- Debugging Section -->
            <div class="p-4 border rounded-lg bg-yellow-100 text-sm">
                <h3 class="font-bold mb-2">Debugging Info:</h3>
                <div v-for="(item, index) in form.items" :key="index" class="mb-2">
                    <p><strong>Item {{ index }}:</strong></p>
                    <p>  - form.items[{{ index }}].item_id: {{ item.item_id }} (Type: {{ typeof item.item_id }})</p>
                    <p>  - rowItems.value[{{ index }}] (count): {{ rowItems[index] ? rowItems[index].length : 0 }}</p>
                    <p>  - selectedItemLabel({{ index }}): {{ selectedItemLabel(index) }}</p>
                    <p>  - All rowItems[{{ index }}] values: {{ rowItems[index] ? rowItems[index].map(i => i.value + ' (' + typeof i.value + ')').join(', ') : 'N/A' }}</p>
                    <p>  - Raw props.order.store_order_items[{{ index }}] object: {{ props.order.store_order_items[index] }}</p>
                </div>
            </div>
            <!-- End Debugging Section -->

            <Card>
                <CardHeader><CardTitle>Order Items</CardTitle></CardHeader>
                <CardContent>
                    <div class="space-y-6">
                        <div v-for="(item, index) in form.items" :key="index" class="p-4 border rounded-lg shadow-sm bg-gray-50 relative">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
                                <!-- Row 1: Branch, Variant, Item -->
                                <InputContainer>
                                    <Label :for="`branch_${index}`">Store Branch</Label>
                                    <SelectShad v-model="item.store_branch_id" v-model:open="isBranchSelectOpen[index]">
                                        <SelectTrigger :id="`branch_${index}`"><SelectValue placeholder="Select Branch..." /></SelectTrigger>
                                        <SelectContent>
                                            <div class="p-2 sticky top-0 bg-white z-10 border-b">
                                                <input type="text" v-model="branchSearchQueries[index]" placeholder="Search..." class="w-full px-3 py-1.5 border rounded-md" @click.stop @keydown.stop/>
                                            </div>
                                            <SelectGroup>
                                                <SelectLabel>Branches</SelectLabel>
                                                <SelectItem v-for="branch in filteredBranches(index)" :key="branch.value" :value="String(branch.value)">{{ branch.label }}</SelectItem>
                                            </SelectGroup>
                                        </SelectContent>
                                    </SelectShad>
                                    <FormError :message="form.errors[`items.${index}.store_branch_id`]" />
                                </InputContainer>

                                <InputContainer>
                                    <Label :for="`variant_${index}`">Variant</Label>
                                    <SelectShad v-model="item.variant" :disabled="!item.store_branch_id || (isVariantLocked && item.variant !== lockedVariant) || (isVariantLocked && index > 0)">
                                        <SelectTrigger :id="`variant_${index}`"><SelectValue placeholder="Select Variant..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                <SelectLabel>Variants</SelectLabel>
                                                <SelectItem v-for="variant in variants" :key="variant" :value="variant">{{ variant }}</SelectItem>
                                            </SelectGroup>
                                        </SelectContent>
                                    </SelectShad>
                                    <FormError :message="form.errors[`items.${index}.variant`]" />
                                </InputContainer>

                                <InputContainer>
                                    <Label :for="`item_${index}`">Item</Label>
                                    <SelectShad v-model="item.item_id" v-model:open="isItemSelectOpen[index]" @update:model-value="handleItemSelection(index, $event)" :disabled="loadingItems[index] || !item.variant">
                                        <SelectTrigger :id="`item_${index}`"><SelectValue :placeholder="selectedItemLabel(index)" /></SelectTrigger>
                                        <SelectContent>
                                            <div class="p-2 sticky top-0 bg-white z-10 border-b"><input type="text" v-model="itemSearchQueries[index]" placeholder="Search..." class="w-full px-3 py-1.5 border rounded-md" @click.stop @keydown.stop/></div>
                                            <SelectGroup>
                                                <SelectLabel>Items</SelectLabel>
                                                <div v-if="loadingItems[index]" class="text-center py-4">Loading...</div>
                                                <template v-else-if="filteredItems(index).length > 0">
                                                    <SelectItem v-for="filteredItem in filteredItems(index)" :key="filteredItem.value" :value="String(filteredItem.value)">{{ filteredItem.label }}</SelectItem>
                                                </template>
                                                <div v-else class="px-2 py-4 text-center text-gray-500">No items found</div>
                                            </SelectGroup>
                                        </SelectContent>
                                    </SelectShad>
                                    <FormError :message="form.errors[`items.${index}.item_id`]" />
                                </InputContainer>

                                <!-- Row 2: Date, UOM, Quantity, Cost -->
                                <div class="relative">
                                    <Label :for="`date_${index}`">Order Date</Label>
                                    <div class="relative">
                                        <input :id="`date_${index}`" type="text" readonly :value="item.order_date" @click="showCalendar[index] = !showCalendar[index]" :disabled="!item.store_branch_id || !item.variant || loadingSchedules[index]" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer" placeholder="Select date" />
                                        <Loader2 v-if="loadingSchedules[index]" class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-gray-500 animate-spin" />
                                        <CalendarIcon v-else class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-gray-500 pointer-events-none" />
                                    </div>
                                    <FormError :message="form.errors[`items.${index}.order_date`]" />
                                    <div v-show="showCalendar[index]" class="absolute z-50 mt-2 bg-white border border-gray-200 rounded-lg shadow-lg p-4 w-full min-w-[280px]">
                                        <div class="flex justify-between items-center mb-4">
                                            <button type="button" @click.stop="goToPrevMonth(index)" class="p-2 rounded-full hover:bg-gray-200">&lt;</button>
                                            <h2 class="text-lg font-semibold">{{ (currentCalendarDate[index] || new Date()).toLocaleString('default', { month: 'long', year: 'numeric' }) }}</h2>
                                            <button type="button" @click.stop="goToNextMonth(index)" class="p-2 rounded-full hover:bg-gray-200">&gt;</button>
                                        </div>
                                        <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-gray-500 mb-2"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
                                        <div class="grid grid-cols-7 gap-1">
                                            <template v-for="(day, d_idx) in getCalendarDays(index)" :key="d_idx">
                                                <div class="text-center py-1.5 rounded-full text-sm" :class="{ 'bg-gray-200 text-gray-400 cursor-not-allowed': day && day.isDisabled, 'bg-blue-500 text-white': day && !day.isDisabled && item.order_date && day.date.toDateString() === new Date(item.order_date + 'T00:00:00').toDateString(), 'hover:bg-gray-200 cursor-pointer': day && !day.isDisabled }" @click="selectDate(index, day)">{{ day ? day.day : '' }}</div>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <InputContainer>
                                    <Label :for="`uom_${index}`">UOM</Label>
                                    <Input :id="`uom_${index}`" :value="item.uom" disabled placeholder="UOM" />
                                </InputContainer>

                                <InputContainer>
                                    <Label :for="`quantity_${index}`">Quantity</Label>
                                    <Input :id="`quantity_${index}`" v-model.number="item.quantity" type="number" step="0.01" min="0" />
                                    <FormError :message="form.errors[`items.${index}.quantity`]" />
                                </InputContainer>
                                
                                <InputContainer>
                                    <Label :for="`cost_${index}`">Cost</Label>
                                    <Input :id="`cost_${index}`" v-model.number="item.cost" type="number" step="0.01" min="0" />
                                    <FormError :message="form.errors[`items.${index}.cost`]" />
                                </InputContainer>

                                <div class="md:col-span-3 flex items-end justify-end">
                                    <div class="text-sm font-semibold pt-2">Item Total: {{ formatCurrency(totalCostPerItem(index)) }}</div>
                                </div>
                            </div>
                            
                            <button type="button" @click="removeItem(index)" class="absolute top-2 right-2 text-red-500 hover:text-red-700 transition-colors">
                                <Trash2 class="size-5" />
                            </button>
                        </div>
                    </div>
                    
                    <div class="mt-4 flex justify-end">
                        <Button type="button" @click="addItem" variant="ghost" class="text-blue-500 hover:bg-blue-100"><PlusCircle class="size-4 mr-2" /> Add one more item</Button>
                    </div>
                </CardContent>
                <CardFooter class="flex justify-between items-center mt-6 p-6 border-t">
                    <div class="text-lg font-bold">Overall Total: {{ formatCurrency(overallTotal) }}</div>
                    <Button type="submit" :disabled="form.processing">Update Order</Button>
                </CardFooter>
            </Card>
        </form>

        <ConfirmationModal 
            :show="showConfirmationModal" 
            title="Update Order" 
            message="Are you sure you want to update this order? This action cannot be undone."
            @confirm="proceedWithSubmit"
            @cancel="showConfirmationModal = false"
        />
    </Layout>
</template>