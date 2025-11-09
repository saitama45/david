<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useForm, Link, router } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { ArrowLeft, Package, AlertTriangle, Calculator, ChevronDown, Plus, Trash2 } from 'lucide-vue-next'
import Select from 'primevue/select'
import ItemAutoComplete from '@/Components/ItemAutoComplete.vue'
import { useSelectOptions } from '@/Composables/useSelectOptions'

const props = defineProps({
  branches: Array,
  items: Array
})

// Create branch options using composable
const { options: branchesOptions } = useSelectOptions(props.branches)

// Form state
const form = useForm({
  store_branch_id: '',
  wastage_reason: '',
  cartItems: []
})

// Local state
const selectedAutoCompleteItem = ref(null)
const isLoading = ref(false)
const cartItems = ref([])

// Product details reactive object for item search
const productDetails = reactive({
  id: null,
  inventory_code: null,
  name: null,
  description: null,
  unit_of_measurement: null,
  base_uom: null,
  base_qty: null,
  quantity: null,
  cost: null,
  total_cost: null,
  uom: null,
  stock: 0,
})

// Accordion state management
const openSections = ref({
  wastageDetails: true,
  itemSelection: true,
  summary: true
})

const toggleSection = (section) => {
  openSections.value[section] = !openSections.value[section]
}

// Computed properties
const selectedBranch = computed(() => {
  return props.branches.find(branch => branch.value === form.store_branch_id)
})

const cartTotalCost = computed(() => {
  return cartItems.value.reduce((total, item) => {
    return total + (parseFloat(item.quantity || 0) * parseFloat(item.cost || 0))
  }, 0)
})

const formattedCartTotal = computed(() => {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP'
  }).format(cartTotalCost.value)
})

const isFormValid = computed(() => {
  return form.store_branch_id &&
         form.wastage_reason &&
         cartItems.value.length > 0 &&
         cartItems.value.every(item =>
           item.sap_masterfile_id &&
           parseFloat(item.quantity) > 0 &&
           parseFloat(item.cost) >= 0
         )
})

// Computed for summary section
const hasSelectedStore = computed(() => form.store_branch_id)
const cartItemsCount = computed(() => cartItems.value.length)
const isCartEmpty = computed(() => cartItems.value.length === 0)

// Handler for auto-complete item selection
const handleAutoCompleteItemSelect = (item) => {
  if (!item) {
    Object.keys(productDetails).forEach((key) => {
      productDetails[key] = null
    })
    selectedAutoCompleteItem.value = null
    return
  }

  // Directly populate productDetails from the auto-complete selection
  productDetails.id = item.id
  productDetails.inventory_code = String(item.item_code)
  productDetails.description = item.description || 'No description'
  productDetails.unit_of_measurement = item.alt_uom || item.uom
  productDetails.cost = Number(item.cost_per_quantity || 1.0)
  productDetails.uom = item.uom
  productDetails.stock = item.stock || 0

  // Set the selected item for reference
  selectedAutoCompleteItem.value = item
}

// Cart management functions
const addToCart = () => {
  if (!selectedAutoCompleteItem.value) {
    return
  }

  // Check if item already exists in cart
  const existingItem = cartItems.value.find(item =>
    item.sap_masterfile_id === selectedAutoCompleteItem.value.id
  )

  if (existingItem) {
    alert('This item is already in the cart. You can update the quantity there.')
    return
  }

  const cartItem = {
    id: Date.now(), // unique client-side ID
    sap_masterfile_id: selectedAutoCompleteItem.value.id,
    item_code: selectedAutoCompleteItem.value.item_code,
    description: selectedAutoCompleteItem.value.description || 'No description',
    quantity: 1,
    cost: productDetails.cost || 0,
    uom: productDetails.unit_of_measurement,
    total_cost: productDetails.cost || 0
  }

  cartItems.value.push(cartItem)

  // Clear selected item
  selectedAutoCompleteItem.value = null
  Object.keys(productDetails).forEach((key) => {
    productDetails[key] = null
  })
}

const removeFromCart = (itemId) => {
  const index = cartItems.value.findIndex(item => item.id === itemId)
  if (index > -1) {
    cartItems.value.splice(index, 1)
  }
}

const updateCartItemQuantity = (itemId, quantity) => {
  const item = cartItems.value.find(item => item.id === itemId)
  if (item) {
    item.quantity = parseFloat(quantity) || 0
    item.total_cost = item.quantity * item.cost
  }
}

const updateCartItemCost = (itemId, cost) => {
  const item = cartItems.value.find(item => item.id === itemId)
  if (item) {
    item.cost = parseFloat(cost) || 0
    item.total_cost = item.quantity * item.cost
  }
}

const clearCart = () => {
  cartItems.value = []
}

// Methods
const submit = () => {
  if (!isFormValid.value) {
    return
  }

  // Transform cart data for submission
  const submitData = {
    store_branch_id: form.store_branch_id,
    wastage_reason: form.wastage_reason,
    cartItems: cartItems.value.map(item => ({
      sap_masterfile_id: item.sap_masterfile_id,
      quantity: parseFloat(item.quantity),
      cost: parseFloat(item.cost)
    }))
  }

  form.transform(() => submitData).post(route('wastage.store'), {
    onSuccess: () => {
      // Clear cart and reset form
      clearCart()
      // Success message will be shown via flash message
    },
    onError: (errors) => {
      console.error('Form validation errors:', errors)
    }
  })
}

const resetForm = () => {
  form.reset()
  clearCart()
  selectedAutoCompleteItem.value = null
  Object.keys(productDetails).forEach((key) => {
    productDetails[key] = null
  })
}

const cancel = () => {
  router.get(route('wastage.index'))
}

const getSelectedBranchName = () => {
  return selectedBranch.value?.label || 'Select a store'
}

// Format functions
const formatNumber = (value) => {
  if (!value) return '0'
  return parseFloat(value).toFixed(2)
}

const formatCurrency = (amount) => {
  if (!amount) return '₱0.00'
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP'
  }).format(amount)
}

</script>

<template>
  <Layout heading="Create Wastage Record">
    <template #header-actions>
      <Button variant="outline" @click="cancel">
        <ArrowLeft class="w-4 h-4 mr-2" />
        Back to Records
      </Button>
    </template>

    <!-- Accordion-style Form Sections -->
    <div class="space-y-4">
      <!-- Wastage Details Section -->
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <!-- Section Header -->
        <button
          @click="toggleSection('wastageDetails')"
          class="w-full px-4 sm:px-6 py-4 bg-gradient-to-r from-blue-50 to-blue-100 border-b border-blue-200 flex items-center justify-between text-left hover:from-blue-100 hover:to-blue-200 transition-colors"
        >
          <div class="flex items-center gap-3">
            <div class="p-2 bg-blue-600 rounded-lg">
              <Package class="w-5 h-5 text-white" />
            </div>
            <div>
              <h2 class="text-lg font-semibold text-gray-900">Wastage Details</h2>
              <p class="text-sm text-gray-600">Basic wastage information</p>
            </div>
          </div>
          <ChevronDown
            :class="['w-5 h-5 text-gray-600 transition-transform duration-200', { 'rotate-180': openSections.wastageDetails }]"
          />
        </button>

        <!-- Section Content -->
        <div v-show="openSections.wastageDetails" class="px-4 sm:px-6 py-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Store Branch -->
            <div class="space-y-2">
              <Label for="store_branch_id">Store Branch *</Label>
              <Select
                v-model="form.store_branch_id"
                filter
                placeholder="Select a store branch"
                :options="branchesOptions"
                optionLabel="label"
                optionValue="value"
                class="w-full"
              />
              <p v-if="form.errors.store_branch_id" class="text-sm text-red-600">
                {{ form.errors.store_branch_id }}
              </p>
            </div>

            <!-- Wastage Reason -->
            <div class="space-y-2 md:col-span-2">
              <Label for="wastage_reason">Wastage Reason *</Label>
              <Textarea
                id="wastage_reason"
                v-model="form.wastage_reason"
                rows="3"
                placeholder="Enter reason for wastage"
                :class="{ 'border-red-500': form.errors.wastage_reason }"
              />
              <p v-if="form.errors.wastage_reason" class="text-sm text-red-600">
                {{ form.errors.wastage_reason }}
              </p>
            </div>

                      </div>
        </div>
      </div>

      <!-- Item Selection Section -->
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <!-- Section Header -->
        <button
          @click="toggleSection('itemSelection')"
          class="w-full px-4 sm:px-6 py-4 bg-gradient-to-r from-green-50 to-green-100 border-b border-green-200 flex items-center justify-between text-left hover:from-green-100 hover:to-green-200 transition-colors"
        >
          <div class="flex items-center gap-3">
            <div class="p-2 bg-green-600 rounded-lg">
              <Plus class="w-5 h-5 text-white" />
            </div>
            <div>
              <h3 class="text-lg font-semibold text-gray-900">Item Selection & Cart</h3>
              <p class="text-sm text-gray-600">Search items and add to cart ({{ cartItemsCount }} items)</p>
            </div>
          </div>
          <ChevronDown
            :class="['w-5 h-5 text-gray-600 transition-transform duration-200', { 'rotate-180': openSections.itemSelection }]"
          />
        </button>

        <!-- Section Content -->
        <div v-show="openSections.itemSelection" class="px-4 sm:px-6 py-4">
          <div v-if="!hasSelectedStore" class="mb-4">
            <Alert class="border-yellow-200 bg-yellow-50">
              <AlertTriangle class="w-4 h-4 text-yellow-600" />
              <AlertDescription class="text-yellow-800">
                Please select a store branch first to search for items.
              </AlertDescription>
            </Alert>
          </div>

          <!-- Item Search and Add -->
          <div class="grid grid-cols-1 md:grid-cols-12 gap-4 mb-6">
            <!-- Item Search -->
            <div class="space-y-2 md:col-span-8">
              <Label class="text-sm font-medium text-gray-700">Search Items *</Label>
              <ItemAutoComplete
                v-model="selectedAutoCompleteItem"
                :sending-store-id="parseInt(form.store_branch_id)"
                placeholder="Type at least 3 characters to search for items..."
                :disabled="!form.store_branch_id || isLoading"
                @item-selected="handleAutoCompleteItemSelect"
              />
            </div>

            <!-- Add to Cart Button -->
            <div class="space-y-2 md:col-span-4 flex items-end">
              <Button
                @click="addToCart"
                :disabled="!selectedAutoCompleteItem || !form.store_branch_id"
                class="w-full h-10 bg-green-600 hover:bg-green-700"
              >
                <Plus class="w-4 h-4 mr-2" />
                Add Items
              </Button>
            </div>
          </div>

          <!-- Selected Item Details -->
          <div v-if="selectedAutoCompleteItem" class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm bg-blue-50 p-3 rounded-lg">
            <div>
              <span class="text-gray-600">Item Code:</span>
              <div class="font-medium">{{ selectedAutoCompleteItem.item_code }}</div>
            </div>
            <div>
              <span class="text-gray-600">Description:</span>
              <div class="font-medium">{{ selectedAutoCompleteItem.description || 'No description' }}</div>
            </div>
            <div>
              <span class="text-gray-600">UoM:</span>
              <div class="font-medium">{{ productDetails.unit_of_measurement }}</div>
            </div>
          </div>

          <!-- Cart Items Table -->
          <div v-if="cartItems.length > 0" class="space-y-4">
            <div class="flex items-center justify-between">
              <h4 class="text-md font-semibold text-gray-900">Cart Items</h4>
              <Button variant="outline" size="sm" @click="clearCart">
                Clear Cart
              </Button>
            </div>

            <div class="border rounded-lg overflow-hidden">
              <table class="w-full">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">UoM</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantity</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cost</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                  <tr v-for="item in cartItems" :key="item.id" class="hover:bg-gray-50">
                    <td class="px-4 py-4">
                      <div>
                        <div class="font-medium text-gray-900">{{ item.item_code }}</div>
                        <div class="text-sm text-gray-500">{{ item.description }}</div>
                      </div>
                    </td>
                    <td class="px-4 py-4">
                      <div class="text-sm text-gray-900">{{ item.uom }}</div>
                    </td>
                    <td class="px-4 py-4">
                      <Input
                        type="number"
                        v-model="item.quantity"
                        @input="updateCartItemQuantity(item.id, $event.target.value)"
                        step="0.01"
                        min="0.01"
                        class="w-24 h-8 text-sm"
                      />
                    </td>
                    <td class="px-4 py-4">
                      <Input
                        type="number"
                        v-model="item.cost"
                        @input="updateCartItemCost(item.id, $event.target.value)"
                        step="0.01"
                        min="0"
                        class="w-24 h-8 text-sm"
                      />
                    </td>
                    <td class="px-4 py-4">
                      <div class="text-sm font-medium text-gray-900">
                        {{ formatCurrency(item.total_cost) }}
                      </div>
                    </td>
                    <td class="px-4 py-4 text-center">
                      <Button
                        variant="ghost"
                        size="sm"
                        @click="removeFromCart(item.id)"
                        class="text-red-600 hover:text-red-700 hover:bg-red-50"
                      >
                        <Trash2 class="w-4 h-4" />
                      </Button>
                    </td>
                  </tr>
                </tbody>
                <tfoot class="bg-gray-50">
                  <tr>
                    <td colspan="4" class="px-4 py-3 text-right font-medium text-gray-900">
                      Cart Total:
                    </td>
                    <td colspan="2" class="px-4 py-3 font-bold text-green-600">
                      {{ formattedCartTotal }}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>

          <!-- Empty Cart Message -->
          <div v-else class="text-center py-8 border-2 border-dashed border-gray-300 rounded-lg">
            <div class="text-gray-500">
              <Package class="w-12 h-12 mx-auto mb-4 text-gray-400" />
              <h3 class="text-lg font-medium mb-2">No items in cart</h3>
              <p class="text-sm">Search for items above and add them to the cart to continue.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Summary Section -->
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <!-- Section Header -->
        <button
          @click="toggleSection('summary')"
          class="w-full px-4 sm:px-6 py-4 bg-gradient-to-r from-purple-50 to-purple-100 border-b border-purple-200 flex items-center justify-between text-left hover:from-purple-100 hover:to-purple-200 transition-colors"
        >
          <div class="flex items-center gap-3">
            <div class="p-2 bg-purple-600 rounded-lg">
              <Calculator class="w-5 h-5 text-white" />
            </div>
            <div>
              <h3 class="text-lg font-semibold text-gray-900">Summary & Submit</h3>
              <p class="text-sm text-gray-600">Review and create wastage record</p>
            </div>
          </div>
          <ChevronDown
            :class="['w-5 h-5 text-gray-600 transition-transform duration-200', { 'rotate-180': openSections.summary }]"
          />
        </button>

        <!-- Section Content -->
        <div v-show="openSections.summary" class="px-4 sm:px-6 py-4">
          <!-- Cart Summary Display -->
          <div v-if="!isCartEmpty" class="bg-blue-50 p-4 rounded-lg mb-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div class="text-center">
                <div class="text-2xl font-bold text-blue-600">{{ cartItemsCount }}</div>
                <div class="text-sm text-gray-600">Items in Cart</div>
              </div>
              <div class="text-center">
                <div class="text-2xl font-bold text-green-600">{{ formattedCartTotal }}</div>
                <div class="text-sm text-gray-600">Total Cost</div>
              </div>
              <div class="text-center">
                <div class="text-lg font-semibold text-purple-600">{{ getSelectedBranchName() }}</div>
                <div class="text-sm text-gray-600">Store Branch</div>
              </div>
            </div>
          </div>

          <!-- Empty Cart Alert -->
          <Alert v-if="isCartEmpty" class="mb-4 border-yellow-200 bg-yellow-50">
            <AlertTriangle class="h-4 w-4 text-yellow-600" />
            <AlertDescription class="text-yellow-800">
              Please add at least one item to the cart before saving.
            </AlertDescription>
          </Alert>

          <!-- Validation Alert -->
          <Alert v-else-if="!isFormValid" class="mb-4">
            <AlertTriangle class="h-4 w-4 text-yellow-600" />
            <AlertDescription class="text-yellow-800">
              Please fill in all required fields and ensure all items have valid quantities and costs.
            </AlertDescription>
          </Alert>

          <!-- Action Buttons -->
          <div class="flex flex-col sm:flex-row gap-3">
            <Button
              @click="submit"
              :disabled="!isFormValid || form.processing || isCartEmpty"
              class="flex-1"
              size="lg"
            >
              <Calculator class="w-4 h-4 mr-2" />
              {{ form.processing ? 'Saving...' : 'Save Wastage Record' }}
            </Button>
          </div>
        </div>
      </div>
    </div>
  </Layout>
</template>

<style scoped>
/* Custom styles for better responsive design */
.grid-cols-1 {
  grid-template-columns: 1fr;
}

@media (min-width: 768px) {
  .grid-cols-1.md\:grid-cols-2 {
    grid-template-columns: repeat(2, 1fr);
  }

  .md\:col-span-2 {
    grid-column: span 2;
  }

  .md\:col-span-3 {
    grid-column: span 3;
  }
}

@media (min-width: 1024px) {
  .md\:col-span-3 {
    grid-template-columns: repeat(3, 1fr);
  }
}

/* Accordion transitions */
.transition-colors {
  transition-property: background-color, border-color;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 200ms;
}

.transition-transform {
  transition-property: transform;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 200ms;
}

.rotate-180 {
  transform: rotate(180deg);
}
</style>