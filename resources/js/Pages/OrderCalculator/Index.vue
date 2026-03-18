<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';
import { format, addDays, parseISO } from 'date-fns';
import Select from 'primevue/select';
import { 
  Filter, 
  Store, 
  Calendar as CalendarIcon, 
  Info, 
  Calculator,
  ChevronDown,
  ChevronRight
} from 'lucide-vue-next';

const props = defineProps({
    stores: {
        type: Array,
        required: true,
    },
    templates: {
        type: Array,
        required: true,
    },
    startOfForecastingWeek: {
        type: String,
        required: true,
    }
});

const form = ref({
    store_branch_id: props.stores.length > 0 ? props.stores[0].value : null,
    ordering_template: null,
    target_dtl: format(addDays(parseISO(props.startOfForecastingWeek), 9), 'yyyy-MM-dd'), // Default to 7 days + 2 buffer
    sunday_date: format(addDays(parseISO(props.startOfForecastingWeek), -1), 'yyyy-MM-dd'),
    adu_month: format(new Date(), 'yyyy-MM'),
    pmix_month: format(new Date(), 'yyyy-MM'),
});

const items = ref([]);
const loading = ref(false);

const fetchData = async () => {
    if (!form.value.store_branch_id || !form.value.ordering_template) return;
    
    loading.value = true;
    try {
        const response = await axios.post('/ordering-tools/order-calculator/calculate', {
            ...form.value
        });
        // Initialize reactive incremental value per item
        items.value = response.data.map(item => ({
            ...item,
            incremental: 0
        }));
    } catch (error) {
        console.error("Failed to fetch calculator data:", error);
    } finally {
        loading.value = false;
    }
};

const calculateMetrics = (item, baseRate) => {
    const rate = Number(baseRate) || 0;
    const soh = Number(item.sunday_ending_inventory) || 0;
    const incoming = Number(item.incoming_deliveries) || 0;
    const incPercent = (Number(item.incremental) || 0) / 100;
    const revisedRate = rate * (1 + incPercent);
    
    let dtl1 = null;
    let dtl2 = null;
    let suggestedOrder = 0;
    
    const sundayDate = parseISO(form.value.sunday_date);
    const targetDtl = parseISO(form.value.target_dtl);
    const startWeek = parseISO(props.startOfForecastingWeek);
    const msPerDay = 24 * 60 * 60 * 1000;

    if (rate > 0) {
        const daysSoh = soh / rate;
        const daysIncoming = incoming / rate;
        
        const dtl1Time = sundayDate.getTime() + (daysSoh * msPerDay);
        dtl1 = new Date(dtl1Time);
        
        const dtl2Time = dtl1Time + (daysIncoming * msPerDay);
        dtl2 = new Date(dtl2Time);
        
        const targetTime = targetDtl.getTime();
        const startWeekTime = startWeek.getTime();

        if (dtl2Time > targetTime) {
            suggestedOrder = 0;
        } else if (dtl2Time < startWeekTime) {
            const daysDiff = (targetTime - startWeekTime) / msPerDay;
            suggestedOrder = daysDiff > 0 ? daysDiff * revisedRate : 0;
        } else {
            const daysDiff = (targetTime - dtl2Time) / msPerDay;
            suggestedOrder = daysDiff > 0 ? daysDiff * revisedRate : 0;
        }
    }

    return {
        rate: rate.toFixed(4),
        dtl1: dtl1 ? format(dtl1, 'MM/dd/yyyy') : 'N/A',
        dtl2: dtl2 ? format(dtl2, 'MM/dd/yyyy') : 'N/A',
        revisedRate: revisedRate.toFixed(4),
        suggestedOrder: suggestedOrder.toFixed(2)
    };
};

const computedItems = computed(() => {
    return items.value.map((item, index) => {
        const adu = calculateMetrics(item, item.base_adu);
        const pmix = calculateMetrics(item, item.base_pmix);
        return {
            originalIndex: index,
            ...item,
            calculated_adu: adu,
            calculated_pmix: pmix
        };
    });
});

const selectedTemplateName = computed(() => {
    const template = props.templates.find(t => t.value === form.value.ordering_template);
    return template ? template.label : '[Template]';
});

const selectedStoreName = computed(() => {
    const store = props.stores.find(s => s.value === form.value.store_branch_id);
    return store ? store.label : '[Store]';
});
</script>

<template>
    <Layout heading="Order Calculator">
        <div class="space-y-6">
            <Card>
                <CardHeader class="pb-3 bg-muted/20">
                    <div class="flex items-center gap-2">
                        <Filter class="w-5 h-5 text-primary" />
                        <CardTitle class="text-lg">Calculator Filters</CardTitle>
                    </div>
                </CardHeader>
                <CardContent class="pt-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-2">
                            <Label for="template" class="text-sm font-semibold">Select Ordering Template</Label>
                            <Select
                                id="template"
                                v-model="form.ordering_template"
                                :options="templates"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Choose ordering template"
                                class="w-full"
                                filter
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="store" class="text-sm font-semibold">Store</Label>
                            <Select
                                id="store"
                                v-model="form.store_branch_id"
                                :options="stores"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Select assigned store"
                                class="w-full"
                                filter
                                :disabled="!form.ordering_template"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label class="text-sm font-semibold">Start of Forecasting Week</Label>
                            <div class="h-10 px-3 py-2 bg-muted/10 border rounded-md text-sm flex items-center text-muted-foreground">
                                <CalendarIcon class="w-4 h-4 mr-2" />
                                {{ format(parseISO(startOfForecastingWeek), 'MMMM d, yyyy') }}
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="target_dtl" class="text-sm font-semibold">Target Date to Last</Label>
                            <input
                                id="target_dtl"
                                type="date"
                                v-model="form.target_dtl"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="sunday_date" class="text-sm font-semibold">Sunday Date (Ending Inventory)</Label>
                            <input
                                id="sunday_date"
                                type="date"
                                v-model="form.sunday_date"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                        </div>

                        <div class="md:col-span-3 grid grid-cols-1 md:grid-cols-3 gap-6 pt-4 border-t mt-2">
                            <div class="space-y-2">
                                <Label for="adu_month" class="text-sm font-semibold">ADU Basis Month</Label>
                                <input
                                    id="adu_month"
                                    type="month"
                                    v-model="form.adu_month"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="pmix_month" class="text-sm font-semibold">PMIX Basis Month</Label>
                                <input
                                    id="pmix_month"
                                    type="month"
                                    v-model="form.pmix_month"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                />
                            </div>
                            <div class="space-y-2 flex items-end">
                                <Button @click="fetchData" :disabled="loading || !form.ordering_template || !form.store_branch_id" class="w-full">
                                    <Calculator v-if="!loading" class="w-4 h-4 mr-2" />
                                    <span v-if="loading">Calculating...</span>
                                    <span v-else>Generate Computation</span>
                                </Button>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="items.length > 0">
                <CardHeader class="border-b bg-muted/5 py-4">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="space-y-1">
                            <CardTitle class="text-lg text-primary font-bold">
                                Computation Results for {{ selectedTemplateName }}
                            </CardTitle>
                            <p class="text-xs text-muted-foreground flex items-center gap-2">
                                <Store class="w-3 h-3" /> {{ selectedStoreName }} | <CalendarIcon class="w-3 h-3" /> Start of Forecasting: {{ format(parseISO(startOfForecastingWeek), 'MM/dd/yyyy') }}
                            </p>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="p-0 overflow-x-auto">
                    <table class="w-full border-collapse text-[11px]">
                        <thead>
                            <tr class="bg-muted/50 border-b">
                                <th colspan="7" class="px-3 py-2 text-center font-bold border-r">ITEM DETAILS</th>
                                <th colspan="3" class="px-3 py-2 text-center font-bold border-r">CURRENT STATUS</th>
                                <th colspan="5" class="px-3 py-2 text-center font-bold border-r bg-indigo-50/50 text-indigo-700">ADU BASIS</th>
                                <th colspan="5" class="px-3 py-2 text-center font-bold bg-green-50/50 text-green-700">PMIX BASIS</th>
                            </tr>
                            <tr class="bg-muted/20 border-b uppercase">
                                <th class="px-3 py-2 text-left border-r">CODE</th>
                                <th class="px-3 py-2 text-left border-r min-w-[150px]">NAME</th>
                                <th class="px-3 py-2 text-left border-r">CAT</th>
                                <th class="px-3 py-2 text-left border-r">BRAND</th>
                                <th class="px-3 py-2 text-left border-r">CLASS</th>
                                <th class="px-3 py-2 text-left border-r">PKG</th>
                                <th class="px-3 py-2 text-left border-r">UNIT</th>
                                
                                <th class="px-3 py-2 text-right border-r">Sunday E.I.</th>
                                <th class="px-3 py-2 text-right border-r">Incoming</th>
                                <th class="px-3 py-2 text-right border-r">Inc %</th>

                                <th class="px-3 py-2 text-right border-r bg-indigo-50/30 text-indigo-600">ADU</th>
                                <th class="px-3 py-2 text-right border-r bg-indigo-50/30 text-indigo-600">DTL1</th>
                                <th class="px-3 py-2 text-right border-r bg-indigo-50/30 text-indigo-600">DTL2</th>
                                <th class="px-3 py-2 text-right border-r bg-indigo-50/30 text-indigo-600">Rev. ADU</th>
                                <th class="px-3 py-2 text-right border-r bg-indigo-50/30 text-indigo-700 font-bold underline">SUGG</th>

                                <th class="px-3 py-2 text-right border-r bg-green-50/30 text-green-600">Daily PMIX</th>
                                <th class="px-3 py-2 text-right border-r bg-green-50/30 text-green-600">DTL1</th>
                                <th class="px-3 py-2 text-right border-r bg-green-50/30 text-green-600">DTL2</th>
                                <th class="px-3 py-2 text-right border-r bg-green-50/30 text-green-600">Rev. PMIX</th>
                                <th class="px-3 py-2 text-right bg-green-50/30 text-green-700 font-bold underline">SUGG</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in computedItems" :key="item.item_code" class="border-b hover:bg-muted/5">
                                <td class="px-3 py-2 border-r">{{ item.item_code }}</td>
                                <td class="px-3 py-2 border-r truncate max-w-[150px] font-medium">{{ item.item_name }}</td>
                                <td class="px-3 py-2 border-r text-muted-foreground">{{ item.category }}</td>
                                <td class="px-3 py-2 border-r text-muted-foreground">{{ item.brand }}</td>
                                <td class="px-3 py-2 border-r text-muted-foreground">{{ item.classification }}</td>
                                <td class="px-3 py-2 border-r text-muted-foreground">{{ item.packaging_config }}</td>
                                <td class="px-3 py-2 border-r text-muted-foreground">{{ item.uom }}</td>
                                
                                <td class="px-3 py-2 text-right border-r font-semibold">{{ item.sunday_ending_inventory }}</td>
                                <td class="px-3 py-2 text-right border-r font-semibold">{{ item.incoming_deliveries }}</td>
                                <td class="px-3 py-1 border-r">
                                    <input 
                                        type="number" 
                                        v-model.number="items[item.originalIndex].incremental" 
                                        class="w-16 text-right text-xs bg-transparent border rounded px-1 focus:ring-1 focus:ring-primary outline-none" 
                                        placeholder="0"
                                    />
                                </td>

                                <td class="px-3 py-2 text-right border-r bg-indigo-50/20">{{ item.calculated_adu.rate }}</td>
                                <td class="px-3 py-2 text-right border-r bg-indigo-50/20 whitespace-nowrap">{{ item.calculated_adu.dtl1 }}</td>
                                <td class="px-3 py-2 text-right border-r bg-indigo-50/20 whitespace-nowrap">{{ item.calculated_adu.dtl2 }}</td>
                                <td class="px-3 py-2 text-right border-r bg-indigo-50/20 font-semibold">{{ item.calculated_adu.revisedRate }}</td>
                                <td class="px-3 py-2 text-right border-r bg-indigo-50/20 font-black text-indigo-700">{{ item.calculated_adu.suggestedOrder }}</td>

                                <td class="px-3 py-2 text-right border-r bg-green-50/20">{{ item.calculated_pmix.rate }}</td>
                                <td class="px-3 py-2 text-right border-r bg-green-50/20 whitespace-nowrap">{{ item.calculated_pmix.dtl1 }}</td>
                                <td class="px-3 py-2 text-right border-r bg-green-50/20 whitespace-nowrap">{{ item.calculated_pmix.dtl2 }}</td>
                                <td class="px-3 py-2 text-right border-r bg-green-50/20 font-semibold">{{ item.calculated_pmix.revisedRate }}</td>
                                <td class="px-3 py-2 text-right bg-green-50/20 font-black text-green-700">{{ item.calculated_pmix.suggestedOrder }}</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <div v-else-if="!loading" class="flex flex-col items-center justify-center py-20 bg-white rounded-lg border-2 border-dashed border-muted shadow-sm">
                <Calculator class="w-16 h-16 text-muted mb-4 opacity-20" />
                <h3 class="text-lg font-medium text-muted-foreground">Please select a Template and Store to generate computation</h3>
                <p class="text-sm text-muted-foreground/60 max-w-md text-center mt-2">
                    Computation is done in real-time based on historical sales (PMIX) and historical orders (ADU).
                </p>
            </div>

            <div class="p-6 bg-muted/30 rounded-lg border border-dashed border-muted-foreground/30">
                <div class="flex gap-2">
                    <Info class="w-5 h-5 text-muted-foreground shrink-0" />
                    <div class="space-y-1">
                        <p class="text-sm font-bold text-muted-foreground underline">Computation Logic & Notes:</p>
                        <ul class="text-[11px] text-muted-foreground/80 list-disc pl-4 space-y-1">
                            <li><strong>DTL1 (Sunday E.I. DTL)</strong>: Date of Sunday + (Sunday Ending Inventory / Historical Rate)</li>
                            <li><strong>DTL2 (DTL with Deliveries)</strong>: DTL1 + (Incoming Deliveries / Historical Rate)</li>
                            <li><strong>SUGG (Suggested Order)</strong>:
                                <ul class="list-circle pl-4 mt-1">
                                    <li>If DTL2 > Target DTL ➔ Suggested Order = 0</li>
                                    <li>If DTL2 < Start of Forecasting Week ➔ Suggested Order = (Target DTL - Start Week) * Revised Rate</li>
                                    <li>Else ➔ Suggested Order = (Target DTL - DTL2) * Revised Rate</li>
                                </ul>
                            </li>
                            <li>All calculations are done using <strong>Ordering UoM</strong>.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </Layout>
</template>

<style scoped>
table {
  table-layout: fixed;
}
</style>
