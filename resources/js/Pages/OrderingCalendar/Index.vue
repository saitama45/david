<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { Head, router } from '@inertiajs/vue3'
// Layout, Card, Label, Button, Badge are globally registered in app.js
import Select from 'primevue/select'
import { 
  Calendar as CalendarIcon, 
  ChevronLeft, 
  ChevronRight, 
  Info,
  Download,
  Filter,
  Store
} from 'lucide-vue-next'
import axios from 'axios'
import { useToast } from "@/composables/useToast"

const props = defineProps({
  templates: Array,
  stores: Array
})

const { toast } = useToast()

// Form state
const selectedTemplate = ref(null)
const selectedStore = ref(null)
const selectedItem = ref(null)
const items = ref([])
const isLoadingItems = ref(false)
const isLoadingCalendar = ref(false)

// Calendar state
const currentMonth = ref(new Date().getMonth())
const currentYear = ref(new Date().getFullYear())
const calendarData = ref([])

const monthNames = [
  "January", "February", "March", "April", "May", "June",
  "July", "August", "September", "October", "November", "December"
]

const dayNames = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat"] // Sunday excluded

// Fetch items when template changes
watch(selectedTemplate, async (newVal) => {
  if (newVal) {
    isLoadingItems.value = true
    selectedItem.value = null
    try {
      const response = await axios.get(route('store-orders.get-supplier-items', { supplierCode: newVal }))
      
      if (response.data && response.data.items) {
          items.value = response.data.items.map(item => ({
            label: item.label,
            value: item.value,
            item_code: item.value,
            description: item.label
          }))
      }
    } catch (error) {
      console.error('Error fetching items:', error)
    } finally {
      isLoadingItems.value = false
    }
  } else {
    items.value = []
    selectedItem.value = null
  }
})

// Fetch calendar data when selections change
watch([selectedStore, selectedItem, currentMonth, currentYear], () => {
  fetchCalendarData()
})

const fetchCalendarData = async () => {
  if (!selectedStore.value || !selectedItem.value || !selectedTemplate.value) return

  isLoadingCalendar.value = true
  try {
    const response = await axios.get(route('ordering-calendar.data'), {
      params: {
        store_id: selectedStore.value,
        item_code: selectedItem.value,
        supplier_code: selectedTemplate.value,
        month: currentMonth.value + 1,
        year: currentYear.value
      }
    })
    
    if (response.data && response.data.data) {
        calendarData.value = response.data.data
    }
    
  } catch (error) {
    console.error('Error fetching calendar data:', error)
  } finally {
    isLoadingCalendar.value = false
  }
}

const exportToPdf = () => {
    if (!selectedStore.value || !selectedItem.value || !selectedTemplate.value) return
    
    const url = route('ordering-calendar.export', {
        store_id: selectedStore.value,
        item_code: selectedItem.value,
        supplier_code: selectedTemplate.value,
        month: currentMonth.value + 1,
        year: currentYear.value
    })
    
    window.open(url, '_blank')
}

const generateDummyCalendarData = () => {
    // Keep this as fallback or initial state
    const data = []
    const daysInMonth = new Date(currentYear.value, currentMonth.value + 1, 0).getDate()
    
    for (let i = 1; i <= daysInMonth; i++) {
        const date = new Date(currentYear.value, currentMonth.value, i)
        if (date.getDay() === 0) continue // Skip Sundays
        
        data.push({
            day: i,
            date: date,
            status: null,
            qty: null,
            has_schedule: false
        })
    }
    calendarData.value = data
}

// Calendar computation logic
const calendarWeeks = computed(() => {
  const weeks = []
  const firstDayOfMonth = new Date(currentYear.value, currentMonth.value, 1)
  const lastDayOfMonth = new Date(currentYear.value, currentMonth.value + 1, 0)
  
  let currentWeek = []
  const dataMap = {}
  calendarData.value.forEach(d => {
      dataMap[d.day] = d
  })

  const startOffset = firstDayOfMonth.getDay() === 0 ? 0 : firstDayOfMonth.getDay() - 1
  
  if (firstDayOfMonth.getDay() !== 0) {
      for (let i = 0; i < startOffset; i++) {
          currentWeek.push({ day: null, empty: true })
      }
  }

  for (let day = 1; day <= lastDayOfMonth.getDate(); day++) {
    const date = new Date(currentYear.value, currentMonth.value, day)
    const dayOfWeek = date.getDay()
    
    if (dayOfWeek === 0) continue // Skip Sundays
    
    currentWeek.push({
      day: day,
      month: monthNames[currentMonth.value].substring(0, 3),
      data: dataMap[day] || { status: null, qty: null, has_schedule: false }
    })
    
    if (currentWeek.length === 6) {
      weeks.push(currentWeek)
      currentWeek = []
    }
  }
  
  if (currentWeek.length > 0) {
    while (currentWeek.length < 6) {
      currentWeek.push({ day: null, empty: true })
    }
    weeks.push(currentWeek)
  }
  
  return weeks
})

const nextMonth = () => {
  if (currentMonth.value === 11) {
    currentMonth.value = 0
    currentYear.value++
  } else {
    currentMonth.value++
  }
}

const prevMonth = () => {
  if (currentMonth.value === 0) {
    currentMonth.value = 11
    currentYear.value--
  } else {
    currentMonth.value--
  }
}

const getStatusClass = (status) => {
  switch (status) {
    case 'ordered': return 'bg-[#fff2cc] text-amber-900 border-amber-200' 
    case 'committed': return 'bg-[#00ffff] text-cyan-900 border-cyan-300' 
    case 'delivered': return 'bg-[#d9ead3] text-green-900 border-green-200' 
    case 'no-delivery': return 'bg-[#ffffff] text-gray-400 border-gray-200' 
    default: return 'bg-white text-gray-400 border-gray-100'
  }
}

const selectedItemName = computed(() => {
  const item = items.value.find(i => i.value === selectedItem.value)
  return item ? item.description : '[Item Name]'
})

const selectedStoreName = computed(() => {
  const store = props.stores.find(s => s.value === selectedStore.value)
  return store ? store.label : '[Store]'
})

const todayDateFormatted = computed(() => {
  return new Date().toLocaleDateString('en-US', { 
    year: 'numeric', 
    month: 'long', 
    day: 'numeric' 
  })
})

onMounted(() => {
    generateDummyCalendarData()
})

</script>

<template>
  <Head title="Ordering Calendar" />

  <Layout heading="Ordering Calendar">
    <div class="space-y-6">
      <Card>
        <CardHeader class="pb-3 bg-muted/20">
            <div class="flex items-center gap-2">
                <Filter class="w-5 h-5 text-primary" />
                <CardTitle class="text-lg">Calendar Filters</CardTitle>
            </div>
        </CardHeader>
        <CardContent class="pt-6">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="space-y-2">
              <Label for="template" class="text-sm font-semibold">Select Ordering Template</Label>
              <Select
                id="template"
                v-model="selectedTemplate"
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
                v-model="selectedStore"
                :options="stores"
                optionLabel="label"
                optionValue="value"
                placeholder="Select assigned store"
                class="w-full"
                filter
                :disabled="!selectedTemplate"
              />
            </div>

            <div class="space-y-2">
              <Label for="item" class="text-sm font-semibold">Select Item</Label>
              <Select
                id="item"
                v-model="selectedItem"
                :options="items"
                optionLabel="label"
                optionValue="value"
                placeholder="Search item..."
                class="w-full"
                filter
                :disabled="!selectedStore || isLoadingItems"
                :loading="isLoadingItems"
              />
            </div>
          </div>
        </CardContent>
      </Card>

      <Card v-if="selectedItem && selectedStore">
        <CardHeader class="border-b bg-muted/5">
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="space-y-1">
              <CardTitle class="text-xl text-primary font-bold">
                Ordering Calendar for {{ selectedItemName }}
              </CardTitle>
              <p class="text-sm text-muted-foreground flex items-center gap-2">
                <Store class="w-4 h-4" /> {{ selectedStoreName }} | <CalendarIcon class="w-4 h-4" /> As of {{ todayDateFormatted }}
              </p>
            </div>
            
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" @click="prevMonth">
                    <ChevronLeft class="w-4 h-4" />
                </Button>
                <span class="text-sm font-bold min-w-[120px] text-center">
                    {{ monthNames[currentMonth] }} {{ currentYear }}
                </span>
                <Button variant="outline" size="sm" @click="nextMonth">
                    <ChevronRight class="w-4 h-4" />
                </Button>
                <Button variant="secondary" size="sm" class="ml-2" @click="exportToPdf">
                    <Download class="w-4 h-4 mr-2" /> Export
                </Button>
            </div>
          </div>
        </CardHeader>
        
        <CardContent class="p-0 sm:p-6 overflow-x-auto">
          <div class="flex flex-wrap gap-4 mb-6 px-4 pt-4 sm:p-0">
              <div class="flex items-center gap-2">
                  <div class="w-4 h-4 rounded border bg-[#fff2cc] border-amber-200"></div>
                  <span class="text-xs font-medium">Ordered</span>
              </div>
              <div class="flex items-center gap-2">
                  <div class="w-4 h-4 rounded border bg-[#00ffff] border-cyan-300"></div>
                  <span class="text-xs font-medium">Committed</span>
              </div>
              <div class="flex items-center gap-2">
                  <div class="w-4 h-4 rounded border bg-[#d9ead3] border-green-200"></div>
                  <span class="text-xs font-medium">Delivered</span>
              </div>
              <div class="flex items-center gap-2">
                  <div class="w-4 h-4 rounded border bg-[#ffffff] border-gray-200"></div>
                  <span class="text-xs font-medium">No Delivery</span>
              </div>
          </div>

          <div class="min-w-[800px] border rounded-lg overflow-hidden shadow-sm m-4 sm:m-0">
            <table class="w-full border-collapse">
              <thead>
                <tr class="bg-primary text-primary-foreground">
                  <th v-for="day in dayNames" :key="day" class="py-3 px-4 text-center font-bold text-sm uppercase tracking-wider border-x border-primary/20">
                    {{ day }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(week, weekIdx) in calendarWeeks" :key="weekIdx" class="border-b last:border-0 h-32">
                  <td 
                    v-for="(dayObj, dayIdx) in week" 
                    :key="dayIdx" 
                    :class="[
                      'p-2 border-x align-top transition-colors',
                      dayObj.empty ? 'bg-muted/10' : 'hover:bg-muted/5',
                      dayObj.data?.status ? getStatusClass(dayObj.data.status) : ''
                    ]"
                  >
                    <div v-if="!dayObj.empty" class="flex flex-col h-full">
                      <div class="flex justify-between items-start mb-2">
                        <span :class="['text-sm font-bold', dayObj.data?.status ? 'opacity-90' : 'text-muted-foreground']">
                          {{ dayObj.month }} {{ dayObj.day }}
                        </span>
                        <Badge v-if="dayObj.data?.status && dayObj.data?.status !== 'no-delivery'" variant="outline" class="text-[10px] uppercase font-bold bg-white/50 border-none px-1 h-4">
                           {{ dayObj.data.status }}
                        </Badge>
                      </div>
                      
                      <div class="flex-grow flex items-center justify-center">
                        <span v-if="dayObj.data?.qty" class="text-3xl font-black tracking-tighter">
                          {{ dayObj.data.qty }}
                        </span>
                        <span v-else-if="dayObj.data?.status === 'no-delivery'" class="text-[10px] font-bold uppercase text-gray-400 text-center leading-tight">
                            NO DELIVERY
                        </span>
                      </div>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <div class="p-6 mt-4 bg-muted/30 rounded-lg border border-dashed border-muted-foreground/30">
            <div class="flex gap-2">
                <Info class="w-5 h-5 text-muted-foreground shrink-0" />
                <div class="space-y-1">
                    <p class="text-sm font-bold text-muted-foreground">Notes:</p>
                    <ul class="text-xs text-muted-foreground/80 list-disc pl-4 space-y-1">
                        <li>There are no Sunday Deliveries, hence Sundays are excluded from this view.</li>
                        <li>Quantities are expressed in terms of **Ordering UoM**.</li>
                        <li>Color coding indicates the status of the transaction for that specific date.</li>
                    </ul>
                </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <div v-else class="flex flex-col items-center justify-center py-20 bg-white rounded-lg border-2 border-dashed border-muted shadow-sm">
          <CalendarIcon class="w-16 h-16 text-muted mb-4 opacity-20" />
          <h3 class="text-lg font-medium text-muted-foreground">Please select a Template, Store, and Item to view the calendar</h3>
          <p class="text-sm text-muted-foreground/60 max-w-md text-center mt-2">
              Select your ordering template first, then choose an assigned store and specific item to load the ordering timeline.
          </p>
      </div>
    </div>
  </Layout>
</template>

<style scoped>
table {
  table-layout: fixed;
}

td {
  min-width: 120px;
}
</style>
