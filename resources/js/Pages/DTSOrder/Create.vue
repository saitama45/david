<script setup>
import { useForm } from "@inertiajs/vue3";
import { ref, watch, computed, onMounted, nextTick, onBeforeUpdate } from 'vue';
import { MinusCircle, PlusCircle, Trash2, Calendar as CalendarIcon, Loader2 } from 'lucide-vue-next';
import { useToast } from "@/components/ui/toast/";
import ConfirmationModal from '@/components/ConfirmationModal.vue';
import axios from 'axios';

const props = defineProps({
    branches: { type: Array, required: true },
    dtsSupplier: { type: Object, required: true },
    variants: { type: Array, required: true },
});

const { toast } = useToast();

const form = useForm({
    supplier_id: String(props.dtsSupplier.value),
    order_date: null,
    variant: null, // Variant is now global
    items: [{
        store_branch_id: null,
        item_id: null,
        quantity: 0,
        cost: 0,
        uom: null,
    }],
});

// --- State ---
const showConfirmationModal = ref(false);
const isAddingNewItem = ref(false);
const globalItems = ref([]);
const loadingGlobalItems = ref(false);
const rowSchedules = ref([]);
const loadingSchedules = ref(false);
const branchSearchQueries = ref([]);
const itemSearchQueries = ref([]);
const isBranchSelectOpen = ref([]);
const isItemSelectOpen = ref([]);
const quantityInputs = ref([]); // For focus management
const showCalendar = ref(false);
const currentCalendarDate = ref(new Date());
const dayMap = { 'SUNDAY': 0, 'MONDAY': 1, 'TUESDAY': 2, 'WEDNESDAY': 3, 'THURSDAY': 4, 'FRIDAY': 5, 'SATURDAY': 6 };

// Lifecycle hook to reset refs before update
onBeforeUpdate(() => {
    quantityInputs.value = [];
});

// --- Calendar Logic ---
const getAvailableDays = () => {
    const schedule = rowSchedules.value || [];
    return schedule.map(dayName => dayMap[dayName.toUpperCase()]).filter(day => day !== undefined);
};
const getDisabledDates = () => {
    const availableDaysArray = getAvailableDays();
    if (availableDaysArray.length === 0 && (form.items[0]?.store_branch_id && form.variant)) return [0, 1, 2, 3, 4, 5, 6];
    const allDays = [0, 1, 2, 3, 4, 5, 6];
    return allDays.filter(day => !availableDaysArray.includes(day));
};
const getCalendarDays = () => {
    const days = [];
    const dateRef = currentCalendarDate.value || new Date();
    const year = dateRef.getFullYear();
    const month = dateRef.getMonth();
    const firstDayOfMonth = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const disabledWeekdays = getDisabledDates();
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const endOfWeek = new Date(today);
    endOfWeek.setDate(today.getDate() + (6 - today.getDay()));
    for (let i = 0; i < firstDayOfMonth; i++) days.push(null);
    for (let i = 1; i <= daysInMonth; i++) {
        const date = new Date(year, month, i);
        const dayOfWeek = date.getDay();
        const isDisabled = disabledWeekdays.includes(dayOfWeek) || date <= endOfWeek;
        days.push({ day: i, date, isDisabled });
    }
    return days;
};
const goToPrevMonth = () => currentCalendarDate.value = new Date(currentCalendarDate.value.getFullYear(), currentCalendarDate.value.getMonth() - 1, 1);
const goToNextMonth = () => currentCalendarDate.value = new Date(currentCalendarDate.value.getFullYear(), currentCalendarDate.value.getMonth() + 1, 1);
const selectDate = (day) => {
    if (day && !day.isDisabled) {
        const d = day.date;
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const dayOfMonth = String(d.getDate()).padStart(2, '0');
        form.order_date = `${year}-${month}-${dayOfMonth}`;
        showCalendar.value = false;
    }
};

// --- Data Fetching ---
const fetchSchedule = async (store_branch_id, variant) => {
    if (!store_branch_id || !variant) {
        rowSchedules.value = [];
        return;
    }
    loadingSchedules.value = true;
    try {
        const response = await axios.get(route('dts-orders.get-schedule'), { params: { store_branch_id, variant } });
        rowSchedules.value = response.data;
    } catch (error) {
        toast({ title: 'Error', description: 'Could not fetch delivery schedule.', variant: 'destructive' });
        rowSchedules.value = [];
    } finally {
        loadingSchedules.value = false;
    }
};
const fetchItemsByVariant = async (variant) => {
    if (!variant || !form.supplier_id) {
        globalItems.value = [];
        return;
    }
    loadingGlobalItems.value = true;
    try {
        const response = await axios.get(route('dts-orders.get-items-by-variant'), { params: { variant, supplier_id: form.supplier_id } });
        globalItems.value = response.data;
    } catch (error) {
        toast({ title: 'Error', description: 'Failed to load items.', variant: 'destructive' });
    } finally {
        loadingGlobalItems.value = false;
    }
};

// --- Watchers ---
watch(() => ({ branch: form.items[0]?.store_branch_id, variant: form.variant }), (newData, oldData) => {
    if (isAddingNewItem.value) return;
    if (newData.branch !== oldData.branch || newData.variant !== oldData.variant) {
        form.order_date = null;
        fetchSchedule(newData.branch, newData.variant);
    }
}, { deep: true });
watch(() => form.variant, (newVariant, oldVariant) => {
    if (isAddingNewItem.value) return;
    if (newVariant !== oldVariant) {
        form.items = [{
            store_branch_id: form.items[0]?.store_branch_id || null,
            item_id: null, quantity: 0, cost: 0, uom: null,
        }];
        itemSearchQueries.value = [];
        if (newVariant) {
            fetchItemsByVariant(newVariant);
        } else {
            globalItems.value = [];
        }
    }
});

// --- General Functions ---
const filteredBranches = (index) => {
    const query = branchSearchQueries.value[index] || '';
    return props.branches.filter(branch => branch.label.toLowerCase().includes(query.toLowerCase()));
};
const filteredItems = (index) => {
    const query = itemSearchQueries.value[index] || '';
    return globalItems.value.filter(item => item.label.toLowerCase().includes(query.toLowerCase()));
};

const handleItemSelection = (index, itemCode) => {
    const selectedItem = globalItems.value.find(item => item.value === itemCode);
    if (selectedItem) {
        form.items[index] = {
            ...form.items[index],
            item_id: selectedItem.value,
            uom: selectedItem.uom,
            cost: selectedItem.cost,
        };
    }
    itemSearchQueries.value[index] = '';
    isItemSelectOpen.value[index] = false;
};

const addItem = async () => {
    isAddingNewItem.value = true;
    const lastItem = form.items[form.items.length - 1];
    const lastItemIndex = form.items.length - 1;
    const lastUomInput = document.getElementById(`uom_${lastItemIndex}`);
    const uomValue = lastUomInput ? lastUomInput.value : null;

    form.items.push({
        store_branch_id: lastItem?.store_branch_id || null,
        item_id: lastItem?.item_id || null,
        quantity: lastItem?.quantity || 0,
        cost: lastItem?.cost || 0,
        uom: null, // Set UOM to null initially
    });
    await nextTick();

    const newIndex = form.items.length - 1;
    form.items[newIndex].uom = uomValue; // Update UOM after the item is pushed and rendered

    const inputRef = quantityInputs.value[newIndex];
    if (inputRef) {
        const targetInput = inputRef.$el || inputRef;
        if (targetInput && typeof targetInput.focus === 'function') {
            targetInput.focus();
            targetInput.select();
        }
    }
    isAddingNewItem.value = false;
};

const removeItem = (index) => {
    form.items.splice(index, 1);
    quantityInputs.value.splice(index, 1);
    const stateArrays = [branchSearchQueries, itemSearchQueries, isBranchSelectOpen, isItemSelectOpen];
    stateArrays.forEach(stateRef => {
        if (Array.isArray(stateRef.value)) {
            stateRef.value.splice(index, 1);
        }
    });
};

const submit = () => { showConfirmationModal.value = true; };
const proceedWithSubmit = () => {
    form.transform(data => ({
        ...data,
        items: data.items.map(item => ({ ...item, variant: data.variant }))
    })).post(route('dts-orders.store'), {
        onSuccess: () => {
            toast({ title: 'Success!', description: 'DTS order(s) placed successfully.', variant: 'success' });
        },
        onFinish: () => {
            showConfirmationModal.value = false;
        },
    });
};
const formatCurrency = (value) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);
const totalCostPerItem = (index) => (form.items[index].quantity || 0) * (form.items[index].cost || 0);
const overallTotal = computed(() => form.items.reduce((sum, item) => sum + ((item.quantity || 0) * (item.cost || 0)), 0));
onMounted(() => { currentCalendarDate.value = new Date(); });
</script>

<template>
    <Layout heading="Create DTS Orders">
        <form @submit.prevent="submit" class="space-y-6">
            <Card>
                <CardHeader><CardTitle>DTS Order Details</CardTitle></CardHeader>
                <CardContent class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
                    <InputContainer>
                        <Label>Supplier</Label>
                        <SelectShad :model-value="String(dtsSupplier.value)" disabled>
                            <SelectTrigger><SelectValue :placeholder="dtsSupplier.label" /></SelectTrigger>
                            <SelectContent><SelectItem :value="String(dtsSupplier.value)">{{ dtsSupplier.label }}</SelectItem></SelectContent>
                        </SelectShad>
                    </InputContainer>

                    <InputContainer>
                        <Label for="variant" class="flex items-center"><span class="bg-gray-700 text-white text-xs rounded-full size-5 flex items-center justify-center mr-4">2</span>Variant</Label>
                        <SelectShad v-model="form.variant" :disabled="!form.items[0]?.store_branch_id">
                            <SelectTrigger id="variant"><SelectValue placeholder="Select Variant..." /></SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Variants</SelectLabel>
                                    <SelectItem v-for="variant in variants" :key="variant" :value="variant">{{ variant }}</SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </SelectShad>
                        <FormError :message="form.errors.variant" />
                    </InputContainer>

                    <div class="relative">
                        <Label for="order_date" class="flex items-center"><span class="bg-gray-700 text-white text-xs rounded-full size-5 flex items-center justify-center mr-2">3</span>Order Date</Label>
                        <div class="relative">
                            <input id="order_date" type="text" readonly :value="form.order_date" @click="showCalendar = !showCalendar" :disabled="!form.items[0]?.store_branch_id || !form.variant || loadingSchedules" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer" placeholder="Select date" />
                            <Loader2 v-if="loadingSchedules" class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-gray-500 animate-spin" />
                            <CalendarIcon v-else class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-gray-500 pointer-events-none" />
                        </div>
                        <FormError :message="form.errors.order_date" />
                        <div v-show="showCalendar" class="absolute z-50 mt-2 bg-white border border-gray-200 rounded-lg shadow-lg p-4 w-full min-w-[280px]">
                            <div class="flex justify-between items-center mb-4">
                                <button type="button" @click.stop="goToPrevMonth()" class="p-2 rounded-full hover:bg-gray-200">&lt;</button>
                                <h2 class="text-lg font-semibold">{{ (currentCalendarDate || new Date()).toLocaleString('default', { month: 'long', year: 'numeric' }) }}</h2>
                                <button type="button" @click.stop="goToNextMonth()" class="p-2 rounded-full hover:bg-gray-200">&gt;</button>
                            </div>
                            <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-gray-500 mb-2"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
                            <div class="grid grid-cols-7 gap-1">
                                <template v-for="(day, d_idx) in getCalendarDays()" :key="d_idx">
                                    <div class="text-center py-1.5 rounded-full text-sm" :class="{ 'bg-gray-200 text-gray-400 cursor-not-allowed': day && day.isDisabled, 'bg-blue-500 text-white': day && !day.isDisabled && form.order_date && day.date.toDateString() === new Date(form.order_date + 'T00:00:00').toDateString(), 'hover:bg-gray-200 cursor-pointer': day && !day.isDisabled }" @click="selectDate(day)">{{ day ? day.day : '' }}</div>
                                </template>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Order Items</CardTitle></CardHeader>
                <CardContent>
                    <div class="space-y-6">
                        <div v-for="(item, index) in form.items" :key="index" class="p-4 border rounded-lg shadow-sm bg-gray-50 relative">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
                                <!-- Row 1: Branch, Item, Quantity -->
                                <InputContainer>
                                    <Label :for="`branch_${index}`" class="flex items-center"><span v-if="index === 0" class="bg-gray-700 text-white text-xs rounded-full size-5 flex items-center justify-center mr-2">1</span>Store Branch</Label>
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
                                    <Label :for="`item_${index}`" class="flex items-center"><span v-if="index === 0" class="bg-gray-700 text-white text-xs rounded-full size-5 flex items-center justify-center mr-2">4</span>Item</Label>
                                    <SelectShad v-model="item.item_id" v-model:open="isItemSelectOpen[index]" @update:model-value="handleItemSelection(index, $event)" :disabled="loadingGlobalItems || !form.variant">
                                        <SelectTrigger :id="`item_${index}`"><SelectValue placeholder="Select Item..." /></SelectTrigger>
                                        <SelectContent>
                                            <div class="p-2 sticky top-0 bg-white z-10 border-b"><input type="text" v-model="itemSearchQueries[index]" placeholder="Search..." class="w-full px-3 py-1.5 border rounded-md" @click.stop @keydown.stop/></div>
                                            <SelectGroup>
                                                <SelectLabel>Items</SelectLabel>
                                                <div v-if="loadingGlobalItems" class="text-center py-4">Loading...</div>
                                                <template v-else-if="filteredItems(index).length > 0">
                                                    <SelectItem v-for="filteredItem in filteredItems(index)" :key="filteredItem.value" :value="String(filteredItem.value)">{{ filteredItem.label }}</SelectItem>
                                                </template>
                                                <div v-else class="px-2 py-4 text-center text-gray-500">No items found</div>
                                            </SelectGroup>
                                        </SelectContent>
                                    </SelectShad>
                                    <FormError :message="form.errors[`items.${index}.item_id`]" />
                                </InputContainer>
                                
                                <InputContainer>
                                    <Label :for="`quantity_${index}`" class="flex items-center"> <span v-if="index === 0" class="bg-gray-700 text-white text-xs rounded-full size-5 flex items-center justify-center mr-2">5</span>Quantity</Label>
                                    <Input :ref="el => { if (el) quantityInputs[index] = el }" :id="`quantity_${index}`" v-model.number="item.quantity" type="number" step="0.01" min="0" />
                                    <FormError :message="form.errors[`items.${index}.quantity`]" />
                                </InputContainer>

                                <!-- Row 2: UOM, Cost -->
                                <InputContainer>
                                    <Label :for="`uom_${index}`">UOM</Label>
                                    <Input :id="`uom_${index}`" :value="item.uom" disabled placeholder="UOM" />
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
                    <Button type="submit" :disabled="form.processing">Place Order(s)</Button>
                </CardFooter>
            </Card>
        </form>

        <ConfirmationModal 
            :show="showConfirmationModal" 
            title="Place Order(s)" 
            message="Are you sure you want to place this order(s)? This action cannot be undone."
            @confirm="proceedWithSubmit"
            @cancel="showConfirmationModal = false"
        />
    </Layout>
</template>