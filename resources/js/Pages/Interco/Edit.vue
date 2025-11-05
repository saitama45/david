<script setup>
import { ref, reactive, computed, watch, nextTick, onMounted } from 'vue'
import Select from "primevue/select";
import axios from 'axios';
import { useForm, Link, router } from '@inertiajs/vue3'
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useEditQuantity } from "@/Composables/useEditQuantity";
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Alert, AlertDescription } from '@/components/ui/alert'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { ArrowLeft, Plus, X, Edit, Trash2, Package, AlertTriangle, ChevronDown, ChevronLeft, ChevronRight, Download } from 'lucide-vue-next'
import StockIndicator from './Components/StockIndicator.vue'
import ItemQuantityModal from './Components/ItemQuantityModal.vue'

const props = defineProps({
  branches: Array,
  items: Array,
  user_store_branch_id: Number,
  order: Object  // Existing interco transfer data for editing (renamed from 'interco' to match backend)
})

const confirm = useConfirm();
const { toast } = useToast();

// Form state - ensure numeric types for store IDs
const form = useForm({
  store_branch_id: props.order?.store_branch_id ? Number(props.order.store_branch_id) : props.user_store_branch_id,
  sending_store_branch_id: props.order?.sending_store_branch_id ? Number(props.order.sending_store_branch_id) : null,
  transfer_date: props.order?.order_date ? new Date(props.order.order_date).toISOString().split('T')[0] : new Date().toISOString().split('T')[0],
  interco_reason: props.order?.interco_reason || '',
  remarks: props.order?.remarks || '',
  items: []
})

// PRE-POPULATE FORM FIELDS IMMEDIATELY
onMounted(() => {
  // Ensure form is populated with existing data on component mount
  if (props.order) {
    // Wait a bit to ensure branchesOptions are fully processed
    nextTick(() => {
      // Set sending store first to ensure receiving store options work correctly
      if (props.order.sending_store_branch_id) {
        const sendingStoreId = Number(props.order.sending_store_branch_id) // Ensure numeric type
        form.sending_store_branch_id = sendingStoreId

        // Then set receiving store after sending store is processed
        nextTick(() => {
          if (props.order.store_branch_id) {
            const receivingStoreId = Number(props.order.store_branch_id) // Ensure numeric type
            form.store_branch_id = receivingStoreId
          }

          // Pre-populate other form fields
          if (props.order.order_date) form.transfer_date = new Date(props.order.order_date).toISOString().split('T')[0]
          if (props.order.interco_reason) form.interco_reason = props.order.interco_reason
          if (props.order.remarks) form.remarks = props.order.remarks

          // Validate data integrity after pre-population - only if branches are ready
          if (branchesOptions.value && branchesOptions.value.length > 0) {
            validateDataIntegrity()
          }
        })
      }
    })
  }

  // Load existing items
  if (props.order && props.order.store_order_items) {
    selectedItems.value = props.order.store_order_items.map(item => ({
      id: Date.now() + Math.random(), // Generate unique temp ID for Vue
      original_id: item.id, // Keep original database ID for submission
      item_code: String(item.sapMasterfile?.ItemCode || item.item_code),
      description: item.sapMasterfile?.ItemDescription || item.description || 'No description',
      unit_of_measurement: String(item.uom || 'PCS'), // Use the actual uom from store_order_items
      alt_uom: String(item.sapMasterfile?.AltUOM || item.uom || 'PCS'), // Use SAP AltUOM if available, fallback to uom
      quantity_ordered: parseFloat(Number(item.quantity_ordered || 0)),
      cost_per_quantity: Number(item.cost_per_quantity || 0),
      uom: item.sapMasterfile?.BaseUOM || item.uom || 'PCS', // Use SAP BaseUOM for internal consistency
      stock: 0, // Will be populated when fetching item details via API
      total_cost: parseFloat((Number(item.quantity_ordered || 0) * Number(item.cost_per_quantity || 0)).toFixed(2)),
      remarks: item.remarks || ''
    }))

    // Fetch SOH stock data for existing items
    fetchStockDataForExistingItems()

    // Fetch items for the sending store to enable adding more items
    if (form.sending_store_branch_id) {
      fetchSendingStoreItems(form.sending_store_branch_id)
    }
  }
})

// Local state
const selectedItems = ref([])
const editingItem = ref(null)
const showQuantityModal = ref(false)
const selectedItemForModal = ref(null)
const availableCategories = ref([])
const recentlyUsedItems = ref([])
const formErrors = ref({})
const isSubmitting = ref(false)

// MassOrders-style state management
const productId = ref(null)
const isLoading = ref(false)
const availableProductsOptions = ref([])

// Product details reactive object (from MassOrders)
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
})

// Item form for validation (from MassOrders)
const itemForm = useForm({
  item: null,
});

// Create branch options using composable
const { options: branchesOptions } = useSelectOptions(props.branches);

// Edit quantity functionality (from MassOrders)
const {
  isEditQuantityModalOpen,
  formQuantity,
  openEditQuantityModal,
} = useEditQuantity(form, selectedItems, null);

// Accordion state management (from MassOrders)
const openSections = ref({
  transferDetails: true,
  addItem: true,
  items: true
})

const toggleSection = (section) => {
  openSections.value[section] = !openSections.value[section]
}

// Search and Pagination for Items (from MassOrders)
const searchTerm = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)

const filteredItems = computed(() => {
  if (!searchTerm.value) {
    return selectedItems.value
  }

  const term = searchTerm.value.toLowerCase()
  return selectedItems.value.filter(item =>
    item.description.toLowerCase().includes(term) ||
    item.item_code.toLowerCase().includes(term) ||
    (item.uom && item.uom.toLowerCase().includes(term))
  )
})

const totalPages = computed(() => {
  return Math.ceil(filteredItems.value.length / itemsPerPage.value)
})

const paginatedItems = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredItems.value.slice(start, end)
})

const visiblePages = computed(() => {
  const total = totalPages.value
  const current = currentPage.value
  const delta = 2
  const range = []

  for (let i = Math.max(2, current - delta); i <= Math.min(total - 1, current + delta); i++) {
    range.push(i)
  }

  if (current - delta > 2) {
    range.unshift('...')
  }
  if (current + delta < total - 1) {
    range.push('...')
  }

  if (total > 1) {
    range.unshift(1)
  }
  if (total > 1 && total !== 1) {
    range.push(total)
  }

  return range.filter(page => page !== '...' || range.indexOf(page) === range.lastIndexOf(page))
})

// Computed properties
const selectedSendingStore = computed(() => {
  return props.branches.find(branch => branch.id === form.sending_store_branch_id)
})

const selectedReceivingStore = computed(() => {
  return props.branches.find(branch => branch.id === form.store_branch_id)
})

const totalItems = computed(() => {
  return selectedItems.value.reduce((total, item) => total + (item.quantity_ordered || 0), 0)
})

const totalValue = computed(() => {
  return selectedItems.value.reduce((total, item) => {
    return total + ((item.quantity_ordered || 0) * (item.cost_per_quantity || 0))
  }, 0)
})

const canAddItems = computed(() => {
  return form.sending_store_branch_id && form.store_branch_id && form.sending_store_branch_id !== form.store_branch_id
})

const isValidStoreSelection = computed(() => {
  return form.sending_store_branch_id && form.store_branch_id && form.sending_store_branch_id !== form.store_branch_id
})

// Computed property for filtered receiving store options
const filteredReceivingStoreOptions = computed(() => {
  if (!form.sending_store_branch_id) {
    return []
  }

  const filteredOptions = branchesOptions.value.filter(branch =>
    branch.value !== form.sending_store_branch_id
  )

  // Ensure the currently selected receiving store is included in the options
  // This handles pre-populated values that might otherwise be filtered out
  if (form.store_branch_id && !filteredOptions.some(option => option.value === form.store_branch_id)) {
    const selectedBranch = branchesOptions.value.find(branch => branch.value === form.store_branch_id)
    if (selectedBranch) {
      filteredOptions.push(selectedBranch)
    }
  }

  return filteredOptions
})

// Computed property for receiving store dropdown disabled state
const isReceivingStoreDisabled = computed(() => {
  return !form.sending_store_branch_id
})

// Methods (MassOrders-style)
// Function to fetch items for sending store (adapted from MassOrders fetchSupplierItems)
const fetchSendingStoreItems = async (sendingStoreId) => {
  if (!sendingStoreId) {
    availableProductsOptions.value = []
    return
  }
  isLoading.value = true
  try {
    const response = await axios.get(route('interco.get-available-items', { sending_store_id: sendingStoreId }))

    // Filter out any invalid items before processing
    const validItems = response.data.items.filter(item =>
      item &&
      item.item_code &&
      item.item_code.trim() !== '' &&
      item.alt_uom &&
      item.alt_uom.trim() !== ''
    )

    availableProductsOptions.value = validItems.map(item => {
      const option = {
        value: `${item.item_code}|${item.alt_uom}`, // Store composite identifier: ItemCode|AltUOM
        label: `${item.item_code} - ${item.description} (${item.alt_uom})`,
        // Store original item data for later use
        _originalData: {
          item_code: item.item_code,
          description: item.description,
          alt_uom: item.alt_uom,
          uom: item.uom,
          cost_per_quantity: item.cost_per_quantity
        }
      }

      // Log specifically for 916A2C
      if (item.item_code === '916A2C') {
        console.log('🔍 DEBUG: Processing 916A2C in fetchSendingStoreItems:', {
          item_code: item.item_code,
          description: item.description,
          alt_uom: item.alt_uom,
          final_label: option.label,
          original_data_description: option._originalData.description
        })
      }

      return option
    })

    // Update existing items with rich description data
    selectedItems.value.forEach(existingItem => {
      const fetchedItem = validItems.find(item =>
        item.item_code === existingItem.item_code &&
        item.alt_uom === (existingItem.alt_uom || existingItem.uom)
      )
      if (fetchedItem && fetchedItem.description && fetchedItem.description !== existingItem.description) {
        // Use reactive assignment to ensure Vue reactivity triggers
        Object.assign(existingItem, { description: fetchedItem.description })

        // Log specifically for 916A2C
        if (existingItem.item_code === '916A2C') {
          console.log('🎯 DEBUG: Reactively updated 916A2C item description:', {
            item_code: existingItem.item_code,
            old_description: existingItem.description,
            new_description: fetchedItem.description,
            matched_uom: existingItem.alt_uom || existingItem.uom
          })
        }
      }
    })

    // Force Vue reactivity update
    selectedItems.value = [...selectedItems.value]
  } catch (err) {
    toast.add({
      severity: "error",
      summary: "Error",
      detail: "Failed to load items for the selected sending store.",
      life: 5000,
    })
    availableProductsOptions.value = []
  } finally {
    isLoading.value = false
  }
}

const addToOrdersButton = () => {
  itemForm.clearErrors()
  if (!itemForm.item) {
    itemForm.setError("item", "Item field is required")
    return
  }
  if (isNaN(Number(productDetails.quantity)) || Number(productDetails.quantity) < 1) {
    itemForm.setError("quantity", "Quantity must be at least 1 and a valid number")
    return
  }
  if (productDetails.cost === null || Number(productDetails.cost) === 0) {
    toast.add({
      severity: "error",
      summary: "Validation Error",
      detail: "Item cost cannot be zero or empty.",
      life: 5000,
    })
    return
  }

  // Enhanced validation with better error handling
  const validationErrors = []

  if (!productDetails.inventory_code) {
    validationErrors.push("Item code is missing")
  }

  if (!productDetails.unit_of_measurement) {
    validationErrors.push("UOM is missing")
  }

  if (!productDetails.quantity || Number(productDetails.quantity) < 1) {
    validationErrors.push("Valid quantity is required")
  }

  if (productDetails.cost === null || isNaN(Number(productDetails.cost))) {
    validationErrors.push("Item cost is invalid")
  }

  // Description is no longer required - we have fallback logic
  if (!productDetails.description) {
    productDetails.description = 'No description'
  }

  if (validationErrors.length > 0) {
    toast.add({
      severity: "error",
      summary: "Validation Error",
      detail: validationErrors.join("; "),
      life: 5000,
    })
    return
  }

  const existingItemIndex = selectedItems.value.findIndex(
    (item) => item.item_code === productDetails.inventory_code
  )

  const currentQuantity = Number(productDetails.quantity)
  const currentCost = Number(productDetails.cost)

  if (existingItemIndex !== -1) {
    const existingItem = selectedItems.value[existingItemIndex]
    const newTotalQuantity = existingItem.quantity_ordered + currentQuantity
    const newTotalCost = parseFloat((newTotalQuantity * currentCost).toFixed(2))

    existingItem.quantity_ordered = newTotalQuantity
    existingItem.total_cost = newTotalCost
    // Ensure stock data is preserved for existing items
    if (existingItem.stock === undefined && productDetails.stock !== undefined) {
      existingItem.stock = productDetails.stock
    }
  } else {
    const newItem = {
      id: Date.now(), // Temporary ID for new items
      item_code: String(productDetails.inventory_code),
      description: productDetails.description || 'No description',
      unit_of_measurement: productDetails.unit_of_measurement,
      alt_uom: productDetails.unit_of_measurement, // Preserve AltUOM for display
      quantity_ordered: parseFloat(Number(productDetails.quantity).toFixed(2)),
      cost_per_quantity: Number(productDetails.cost),
      uom: productDetails.uom,
      stock: productDetails.stock || 0, // Preserve SOH stock data
      total_cost: parseFloat((Number(productDetails.quantity) * Number(productDetails.cost)).toFixed(2)),
    }

    selectedItems.value.push(newItem)
  }

  Object.keys(productDetails).forEach((key) => {
    productDetails[key] = null
  })
  productId.value = null
  toast.add({
    severity: "success",
    summary: "Success",
    detail: "Item added successfully.",
    life: 5000,
  })
  itemForm.item = null
  itemForm.clearErrors()
}

const removeItem = (index) => {
  selectedItems.value.splice(index, 1)
}

const editItemQuantity = (item, index) => {
  openEditQuantityModal(item.id, item.quantity_ordered)
}

const saveQuantityChanges = ({ item, quantity }) => {
  if (editingItem.value !== null) {
    selectedItems.value[editingItem.value].quantity_ordered = quantity
    // Update total cost
    const selectedItem = selectedItems.value[editingItem.value]
    selectedItem.total_cost = parseFloat((quantity * selectedItem.cost_per_quantity).toFixed(2))
  }
  showQuantityModal.value = false
  editingItem.value = null
  selectedItemForModal.value = null
}

const editQuantity = () => {
  // Try to find the item in selectedItems
  let itemIndex = selectedItems.value.findIndex(item => item.id === formQuantity.id)

  // If not found, try to find by item_code in case of ID mismatch
  if (itemIndex === -1) {
    itemIndex = selectedItems.value.findIndex(item =>
      item.item_code === formQuantity.item_code ||
      item.description === formQuantity.description
    )
  }

  if (itemIndex !== -1) {
    const newQuantity = Number(formQuantity.quantity)
    const currentItem = selectedItems.value[itemIndex]

    if (isNaN(newQuantity) || newQuantity <= 0) {
      formQuantity.errors.quantity = "Quantity must be a positive number."
      toast.add({ severity: "error", summary: "Validation Error", detail: "Quantity must be a positive number.", life: 3000 })
      return
    }

    const itemCost = Number(currentItem.cost_per_quantity)
    if (isNaN(itemCost)) {
      toast.add({ severity: "error", summary: "Calculation Error", detail: "Item cost is invalid. Cannot update total cost.", life: 3000 })
      return
    }

    // Update the item in selectedItems
    currentItem.quantity_ordered = parseFloat(newQuantity.toFixed(2))
    currentItem.total_cost = parseFloat((newQuantity * itemCost).toFixed(2))

    // Create a new array to trigger reactivity
    selectedItems.value = [...selectedItems.value]

    toast.add({ severity: "success", summary: "Success", detail: "Quantity Updated.", life: 3000 })
    isEditQuantityModalOpen.value = false
  } else {
    // Enhanced error message with debugging info
    toast.add({
      severity: "error",
      summary: "Error",
      detail: `Item not found in transfer list. Looking for ID: ${formQuantity.id}, Item Code: ${formQuantity.item_code}, Description: ${formQuantity.description}`,
      life: 5000
    })
  }
}

const validateForm = () => {
  const errors = {}

  // Validate store selection
  if (!form.sending_store_branch_id) {
    errors.sending_store_branch_id = 'Sending store is required'
  }

  if (!form.store_branch_id) {
    errors.store_branch_id = 'Receiving store is required'
  }

  if (form.sending_store_branch_id === form.store_branch_id) {
    errors.store_selection = 'Sending and receiving stores must be different'
  }

  // Validate transfer date
  if (!form.transfer_date) {
    errors.transfer_date = 'Transfer date is required'
  }

  // Validate reason
  if (!form.interco_reason || !form.interco_reason.trim()) {
    errors.interco_reason = 'Reason for transfer is required'
  }

  // Validate items
  if (selectedItems.value.length === 0) {
    errors.items = 'At least one item is required'
  }

  // Validate each item
  selectedItems.value.forEach((item, index) => {
    if (!item.quantity_ordered || item.quantity_ordered < 1) {
      errors[`item_${index}_quantity`] = 'Quantity must be at least 1'
    }

    if (item.quantity_ordered > (item.stock || 0)) {
      errors[`item_${index}_stock`] = `Insufficient stock. Only ${item.stock || 0} available`
    }
  })

  formErrors.value = errors
  return Object.keys(errors).length === 0
}

const submitForm = () => {
  // Validate form first
  if (!validateForm()) {
    toast.add({
      severity: "error",
      summary: "Validation Error",
      detail: "Please fix all validation errors before submitting.",
      life: 5000,
    })
    return
  }

  // Double-check items before submission
  if (selectedItems.value.length === 0) {
    toast.add({
      severity: "error",
      summary: "No Items",
      detail: "Please add at least one item to the transfer before submitting.",
      life: 5000,
    })
    return
  }

  // Show confirmation dialog before submission
  const confirmMessage = "Are you sure you want to update this interco transfer?"

  confirm.require({
    message: confirmMessage,
    header: 'Confirm Interco Transfer Update',
    icon: 'pi pi-exclamation-triangle',
    rejectClass: 'p-button-danger',
    rejectLabel: 'No',
    acceptLabel: 'Yes',
    acceptClass: 'p-button-success',
    accept: () => {
      executeFormSubmission()
    },
    reject: () => {
      toast.add({
        severity: "info",
        summary: "Cancelled",
        detail: "Interco transfer update was cancelled.",
        life: 3000,
      })
    }
  })
}

const executeFormSubmission = () => {

  // Prepare form data with proper validation and structure
  const submitData = {
    store_branch_id: form.store_branch_id,
    sending_store_branch_id: form.sending_store_branch_id,
    transfer_date: form.transfer_date,
    interco_reason: form.interco_reason,
    remarks: form.remarks || '',
    items: selectedItems.value.map((item, index) => {
      const mappedItem = {
        id: item.original_id || null, // Include original ID for existing items
        item_code: String(item.item_code),
        quantity_ordered: parseInt(Number(item.quantity_ordered)),
        cost_per_quantity: Number(item.cost_per_quantity || 1.0),
        uom: String(item.unit_of_measurement || item.uom || 'PCS'),
        remarks: item.remarks || ''
      }

            return mappedItem
    })
  }

  isSubmitting.value = true

  // Use Inertia's visit instead of form.post for better control
  router.put(route('interco.update', props.order.id), submitData, {
    onSuccess: (page) => {
      isSubmitting.value = false
      toast.add({
        severity: "success",
        summary: "Success",
        detail: "Interco transfer request updated successfully!",
        life: 5000,
      })
    },
    onError: (errors) => {
      formErrors.value = errors

      // Handle specific error messages with user-friendly feedback
      if (errors.error) {
        toast.add({
          severity: "error",
          summary: "Submission Failed",
          detail: errors.error,
          life: 8000,
        })
      } else if (errors.items) {
        toast.add({
          severity: "error",
          summary: "Item Validation Error",
          detail: `Issue with items: ${errors.items}`,
          life: 8000,
        })
      } else if (errors.store_branch_id) {
        toast.add({
          severity: "error",
          summary: "Store Selection Error",
          detail: `Receiving store: ${errors.store_branch_id}`,
          life: 8000,
        })
      } else if (errors.sending_store_branch_id) {
        toast.add({
          severity: "error",
          summary: "Store Selection Error",
          detail: `Sending store: ${errors.sending_store_branch_id}`,
          life: 8000,
        })
      } else {
        // Show all validation errors
        const errorList = Object.entries(errors)
          .map(([field, message]) => `${field}: ${message}`)
          .join('; ')

        toast.add({
          severity: "error",
          summary: "Validation Failed",
          detail: errorList,
          life: 10000,
        })
      }

      isSubmitting.value = false
    },
    onCancel: () => {
      isSubmitting.value = false
    },
    onFinish: () => {
      isSubmitting.value = false
    },
    preserveState: true,
    preserveScroll: true,
  })
}

const resetForm = () => {
  form.reset()
  selectedItems.value = []
  formErrors.value = {}
}

const clearAllItems = () => {
  try {
    // Use PrimeVue's confirm if available, fallback to native browser confirm
    if (selectedItems.value.length === 0) {
      toast.add({
        severity: "info",
        summary: "Info",
        detail: "No items to clear.",
        life: 3000,
      })
      return
    }

    // PrimeVue confirm dialog
    confirm.require({
      message: 'Are you sure you want to remove ALL items from the transfer?',
      header: 'Confirm Clear All',
      icon: 'pi pi-exclamation-triangle',
      rejectClass: 'p-button-secondary p-button-outlined',
      rejectLabel: 'Cancel',
      acceptLabel: 'Clear All',
      acceptClass: 'p-button-danger',
      accept: () => {
        selectedItems.value = []
        toast.add({
          severity: "success",
          summary: "Success",
          detail: "All items have been removed from the transfer.",
          life: 3000,
        })
      },
      reject: () => {
        // User cancelled - no action needed
      }
    })
  } catch (error) {
    // Fallback to native browser confirm if PrimeVue confirm fails
    if (window.confirm('Are you sure you want to remove ALL items from the transfer?')) {
      selectedItems.value = []
    }
  }
}


// Fetch SOH stock data for existing items
const fetchStockDataForExistingItems = async () => {
  if (!form.sending_store_branch_id || selectedItems.value.length === 0) {
    return
  }

  try {
    const itemCodes = selectedItems.value
      .map(item => item.item_code)
      .filter(code => code) // Remove any empty/null codes
      .join(',')

    if (!itemCodes) {
      return
    }

    const response = await axios.get(`/interco/branch-inventory`, {
      params: {
        branch_id: form.sending_store_branch_id,
        item_codes: itemCodes
      }
    })

    if (response.data && response.data.items) {
      const stockData = response.data.items

      // Update stock values for existing items
      selectedItems.value.forEach(item => {
        const stockInfo = stockData.find(stock =>
          stock.item_code === item.item_code ||
          stock.ItemCode === item.item_code
        )

        if (stockInfo) {
          item.stock = parseFloat(stockInfo.quantity || stockInfo.Quantity || 0)
        } else {
          item.stock = 0
        }
      })
    } else {
      // Set stock to 0 for all items if no data received
      selectedItems.value.forEach(item => {
        item.stock = 0
      })
    }
  } catch (error) {
    if (error.response && error.response.status === 405) {
      toast.add({
        severity: "warn",
        summary: "Stock Data Unavailable",
        detail: "Unable to fetch current stock levels. Using default values.",
        life: 5000,
      })
    } else if (error.response && error.response.status === 403) {
      toast.add({
        severity: "warn",
        summary: "Access Restricted",
        detail: "You don't have permission to view stock levels for this branch.",
        life: 5000,
      })
    } else {
      toast.add({
        severity: "error",
        summary: "Stock Data Error",
        detail: "Failed to load stock information. Items will show 0 stock.",
        life: 5000,
      })
    }

    // Set stock to 0 for all items on error - this prevents the form from breaking
    selectedItems.value.forEach(item => {
      item.stock = 0
    })
  }
}

// Data integrity validation for pre-populated values
const validateDataIntegrity = () => {
  const issues = []

  // Check if essential order data is present
  if (!props.order) {
    return
  }

  // Validate store data
  if (!props.order.sending_store_branch_id) {
    issues.push('Sending store ID is missing from order data')
  }

  if (!props.order.store_branch_id) {
    issues.push('Receiving store ID is missing from order data')
  }

  if (props.order.sending_store_branch_id === props.order.store_branch_id) {
    issues.push('Sending and receiving stores cannot be the same')
  }

  // Validate date data
  if (!props.order.order_date) {
    issues.push('Order date is missing from order data')
  } else {
    const dateObj = new Date(props.order.order_date)
    if (isNaN(dateObj.getTime())) {
      issues.push('Order date is invalid')
    }
  }

  // Validate branch options - make this more forgiving
  if (!branchesOptions.value || branchesOptions.value.length === 0) {
    // Don't block the form if branches are missing - this could be a permissions issue
    return
  } else {
    // Check if pre-populated stores exist in options
    const sendingStoreExists = branchesOptions.value.some(branch =>
      branch.value === form.sending_store_branch_id
    )
    const receivingStoreExists = branchesOptions.value.some(branch =>
      branch.value === form.store_branch_id
    )

    if (!sendingStoreExists && props.order.sending_store_branch_id) {
      issues.push(`You may not have permission to access sending store ${props.order.sending_store_branch_id}`)
    }

    if (!receivingStoreExists && props.order.store_branch_id) {
      issues.push(`You may not have permission to access receiving store ${props.order.store_branch_id}`)
    }
  }

  // Only show toast if there are critical issues that would prevent form submission
  if (issues.length > 0) {
    const criticalIssues = issues.filter(issue =>
      issue.includes('missing') || issue.includes('invalid') || issue.includes('same')
    )

    if (criticalIssues.length > 0) {
      toast.add({
        severity: "warn",
        summary: "Data Quality Warning",
        detail: `Some data may need attention: ${criticalIssues.slice(0, 2).join(', ')}`,
        life: 6000,
      })
    }
  }
}

// Watch for sending store changes
watch(() => form.sending_store_branch_id, (newValue) => {
  if (newValue) {
    // Clear selected items if they don't have stock in the new sending store
    selectedItems.value = selectedItems.value.filter(item => {
      return item.stock > 0
    })

    // Fetch items for the new sending store
    fetchSendingStoreItems(newValue)
  } else {
    availableProductsOptions.value = []
  }

  // Clear dependent fields
  productId.value = null
  Object.keys(productDetails).forEach((key) => {
    productDetails[key] = null
  })

  // Clear receiving store if it matches the new sending store
  if (form.store_branch_id === newValue) {
    form.store_branch_id = null
  }
})

// Watch for productId changes (from MassOrders)
watch(productId, async (itemCode) => {
  if (itemCode) {
    isLoading.value = true
    itemForm.item = itemCode

    // Parse composite identifier: ItemCode|AltUOM
    const [parsedItemCode, selectedAltUOM] = itemCode.split('|')

    try {
      const sendingStoreId = form.sending_store_branch_id

      if (!sendingStoreId) {
        toast.add({
          severity: "error",
          summary: "Error",
          detail: "Failed to determine sending store.",
          life: 5000,
        })
        isLoading.value = false
        return
      }

      const response = await axios.get(route("interco.get-item-details", {
        itemCode: parsedItemCode,
        altUOM: selectedAltUOM,
        sendingStoreId: sendingStoreId
      }))
      const result = response.data.item

      if (result) {
        productDetails.inventory_code = String(result.item_code)
        productDetails.description = result.description || 'No description'
        productDetails.unit_of_measurement = result.alt_uom || result.uom  // Use AltUOM if available, fallback to BaseUOM
        productDetails.cost = Number(result.cost_per_quantity)
        productDetails.uom = result.uom
        productDetails.stock = result.stock || 0

        // Log specifically for 916A2C
        if (parsedItemCode === '916A2C') {
          console.log('🎯 DEBUG: 916A2C API success - Product details updated:', {
            inventory_code: productDetails.inventory_code,
            description: productDetails.description,
            unit_of_measurement: productDetails.unit_of_measurement,
            stock: productDetails.stock,
            original_api_description: result.description,
            fallback_used: result.description === null || result.description === undefined
          })
        }

      } else {
        // Fallback: try to get data from the stored original data in availableProductsOptions
        const selectedOption = availableProductsOptions.value.find(option =>
          option.value === itemCode
        )

        if (selectedOption && selectedOption._originalData) {
          const data = selectedOption._originalData
          productDetails.inventory_code = String(data.item_code)
          productDetails.description = data.description || 'No description'
          productDetails.unit_of_measurement = data.alt_uom || data.uom
          productDetails.cost = Number(data.cost_per_quantity || 0)
          productDetails.uom = data.uom
          productDetails.stock = 0

          // Log specifically for 916A2C
          if (parsedItemCode === '916A2C') {
            console.log('🎯 DEBUG: 916A2C fallback - Product details set:', {
              inventory_code: productDetails.inventory_code,
              description: productDetails.description,
              unit_of_measurement: productDetails.unit_of_measurement,
              original_fallback_description: data.description,
              selected_option_label: selectedOption.label
            })
          }
        } else {
          toast.add({
            severity: "error",
            summary: "Error",
            detail: "Item details not found.",
            life: 5000,
          })
        }
      }
    } catch (err) {
      // Fallback: try to get data from the stored original data in availableProductsOptions
      const selectedOption = availableProductsOptions.value.find(option =>
        option.value === itemCode
      )

      if (selectedOption && selectedOption._originalData) {
        const data = selectedOption._originalData
        productDetails.inventory_code = String(data.item_code)
        productDetails.description = data.description || 'No description'
        productDetails.unit_of_measurement = data.alt_uom || data.uom
        productDetails.cost = Number(data.cost_per_quantity || 0)
        productDetails.uom = data.uom
        productDetails.stock = 0

        console.log('✅ DEBUG: Catch fallback product details set:', {
          inventory_code: productDetails.inventory_code,
          description: productDetails.description
        })
      } else {
        toast.add({
          severity: "error",
          summary: "Error",
          detail: "Failed to load item details.",
          life: 5000,
        })
      }
    } finally {
      isLoading.value = false
    }
  } else {
    Object.keys(productDetails).forEach((key) => {
      productDetails[key] = null
    })
  }
}, { deep: true })

</script>

<template>
  <Layout heading="Edit Store Transfer">
    <template #header-actions>
      <Button variant="outline" @click="router.get(route('interco.index'))">
        <ArrowLeft class="w-4 h-4 mr-2" />
        Back to Transfers
      </Button>
    </template>

    <!-- Accordion-style Form Sections -->
    <div class="space-y-4">
      <!-- Transfer Details Section -->
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <!-- Section Header -->
        <button
          @click="toggleSection('transferDetails')"
          class="w-full px-4 sm:px-6 py-4 bg-gradient-to-r from-blue-50 to-blue-100 border-b border-blue-200 flex items-center justify-between text-left hover:from-blue-100 hover:to-blue-200 transition-colors"
        >
          <div class="flex items-center gap-3">
            <div class="p-2 bg-blue-600 rounded-lg">
              <Package class="w-5 h-5 text-white" />
            </div>
            <div>
              <h2 class="text-lg font-semibold text-gray-900">Transfer Details</h2>
              <p class="text-sm text-gray-600">Basic transfer information</p>
            </div>
          </div>
          <ChevronDown
            :class="['w-5 h-5 text-gray-600 transition-transform duration-200', { 'rotate-180': openSections.transferDetails }]"
          />
        </button>

        <!-- Section Content -->
        <div v-show="openSections.transferDetails" class="px-4 sm:px-6 py-4">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Sending Store -->
            <div class="space-y-2">
              <Label for="sending_store">Sending Store *</Label>
              <Select
                filter
                placeholder="Select sending store"
                v-model="form.sending_store_branch_id"
                :options="branchesOptions"
                optionLabel="label"
                optionValue="value"
                class="w-full"
              />
              <p v-if="formErrors.sending_store_branch_id" class="text-sm text-red-600">
                {{ formErrors.sending_store_branch_id }}
              </p>
            </div>

            <!-- Receiving Store -->
            <div class="space-y-2">
              <Label for="receiving_store">Receiving Store *</Label>
              <Select
                filter
                placeholder="Select receiving store"
                v-model="form.store_branch_id"
                :options="filteredReceivingStoreOptions"
                :disabled="isReceivingStoreDisabled"
                optionLabel="label"
                optionValue="value"
                class="w-full"
              />
              <p v-if="formErrors.store_branch_id" class="text-sm text-red-600">
                {{ formErrors.store_branch_id }}
              </p>
              <p v-if="!form.sending_store_branch_id" class="text-sm text-muted-foreground">
                Select a sending store first
              </p>
            </div>

            <!-- Transfer Date -->
            <div class="space-y-2">
              <Label for="transfer_date">Transfer Date</Label>
              <Input
                id="transfer_date"
                type="date"
                v-model="form.transfer_date"
                class="w-full"
              />
              <p v-if="formErrors.transfer_date" class="text-sm text-red-600">
                {{ formErrors.transfer_date }}
              </p>
            </div>

            <!-- Reason -->
            <div class="space-y-2 md:col-span-3">
              <Label for="reason">Reason for Transfer *</Label>
              <Textarea
                id="reason"
                v-model="form.interco_reason"
                placeholder="Explain why this transfer is needed..."
                rows="3"
              />
              <p v-if="formErrors.interco_reason" class="text-sm text-red-600">
                {{ formErrors.interco_reason }}
              </p>
            </div>

            <!-- Remarks -->
            <div class="space-y-2 md:col-span-3">
              <Label for="remarks">Remarks (Optional)</Label>
              <Textarea
                id="remarks"
                v-model="form.remarks"
                placeholder="Additional notes or instructions..."
                rows="2"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Add Item Section -->
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <!-- Section Header -->
        <button
          @click="toggleSection('addItem')"
          class="w-full px-4 sm:px-6 py-4 bg-gradient-to-r from-green-50 to-green-100 border-b border-green-200 flex items-center justify-between text-left hover:from-green-100 hover:to-green-200 transition-colors"
        >
          <div class="flex items-center gap-3">
            <div class="p-2 bg-green-600 rounded-lg">
              <Plus class="w-5 h-5 text-white" />
            </div>
            <div>
              <h3 class="text-lg font-semibold text-gray-900">Add Items</h3>
              <p class="text-sm text-gray-600">Add items to your transfer</p>
            </div>
          </div>
          <ChevronDown
            :class="['w-5 h-5 text-gray-600 transition-transform duration-200', { 'rotate-180': openSections.addItem }]"
          />
        </button>

        <!-- Section Content -->
        <div v-show="openSections.addItem" class="px-4 sm:px-6 py-4">
          <!-- Store Selection Alert -->
          <Alert v-if="formErrors.store_selection" class="mb-4">
            <AlertTriangle class="w-4 h-4" />
            <AlertDescription>
              {{ formErrors.store_selection }}
            </AlertDescription>
          </Alert>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Item -->
            <div class="space-y-2">
              <Label class="text-sm font-medium text-gray-700">Item</Label>
              <Select
                filter
                placeholder="Select an Item"
                v-model="productId"
                :options="availableProductsOptions"
                optionLabel="label"
                optionValue="value"
                :disabled="!form.sending_store_branch_id || isLoading"
                class="w-full"
              >
                <template #empty>
                  <div v-if="isLoading" class="p-4 text-center text-gray-500">
                    Loading items...
                  </div>
                  <div v-else class="p-4 text-center text-gray-500">
                    No items available for this sending store.
                  </div>
                </template>
              </Select>
              <p v-if="itemForm.errors.item" class="text-sm text-red-600">
                {{ itemForm.errors.item }}
              </p>
            </div>

            <!-- UOM -->
            <div class="space-y-2">
              <Label class="text-sm font-medium text-gray-700">UOM</Label>
              <Input
                type="text"
                disabled
                v-model="productDetails.unit_of_measurement"
                class="flex h-10 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm"
              />
            </div>

            <!-- SOH Stock -->
            <div class="space-y-2">
              <Label class="text-sm font-medium text-gray-700">SOH Stock</Label>
              <Input
                type="text"
                disabled
                :value="productDetails.stock || 0"
                class="flex h-10 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm"
              />
            </div>

            <!-- Quantity -->
            <div class="space-y-2">
              <Label class="text-sm font-medium text-gray-700">Quantity</Label>
              <Input
                type="number"
                v-model="productDetails.quantity"
                class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm"
              />
              <p v-if="itemForm.errors.quantity" class="text-sm text-red-600">
                {{ itemForm.errors.quantity }}
              </p>
            </div>
          </div>

          <div class="flex justify-end mt-4">
            <Button @click="addToOrdersButton" :disabled="isLoading || !form.sending_store_branch_id">
              Add to Transfer
            </Button>
          </div>

          <p v-if="!form.sending_store_branch_id" class="text-sm text-muted-foreground mt-2">
            Select a sending store to add items
          </p>
        </div>
      </div>

      <!-- Transfer Items Section -->
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <!-- Section Header -->
        <button
          @click="toggleSection('items')"
          class="w-full px-4 sm:px-6 py-4 bg-gradient-to-r from-purple-50 to-purple-100 border-b border-purple-200 flex items-center justify-between text-left hover:from-purple-100 hover:to-purple-200 transition-colors"
        >
          <div class="flex items-center gap-3">
            <div class="p-2 bg-purple-600 rounded-lg">
              <Package class="w-5 h-5 text-white" />
            </div>
            <div>
              <h3 class="text-lg font-semibold text-gray-900">Transfer Items ({{ selectedItems.length }})</h3>
              <p class="text-sm text-gray-600">Manage your transfer items and quantities</p>
            </div>
          </div>
          <ChevronDown
            :class="['w-5 h-5 text-gray-600 transition-transform duration-200', { 'rotate-180': openSections.items }]"
          />
        </button>

        <!-- Section Content -->
        <div v-show="openSections.items" class="p-4 sm:p-6 space-y-4">
          <!-- Search and Tools -->
          <div class="flex flex-col gap-3">
            <!-- Search Bar -->
            <div class="relative">
              <input
                type="text"
                v-model="searchTerm"
                placeholder="Search items by code or description..."
                class="w-full h-10 pl-10 pr-4 rounded-md border border-gray-300 bg-white text-sm shadow-sm transition-colors placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
              />
              <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
              </svg>
            </div>

            <!-- Summary Stats -->
            <div class="flex flex-wrap gap-2">
              <div class="flex items-center gap-2">
                <span class="text-sm text-gray-600">Total Quantity:</span>
                <span class="font-bold text-lg">{{ totalItems }}</span>
              </div>
            </div>
          </div>

          <!-- Items Count and View Toggle -->
          <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-sm text-gray-600">
            <span>{{ filteredItems.length }} items found</span>
            <div class="flex items-center gap-2">
              <span>Items per page:</span>
              <button
                @click="itemsPerPage = 10"
                :class="['px-2 py-1 rounded text-xs font-medium transition-colors', itemsPerPage === 10 ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:text-gray-700']"
              >
                10
              </button>
              <button
                @click="itemsPerPage = 25"
                :class="['px-2 py-1 rounded text-xs font-medium transition-colors', itemsPerPage === 25 ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:text-gray-700']"
              >
                25
              </button>
              <button
                @click="itemsPerPage = 50"
                :class="['px-2 py-1 rounded text-xs font-medium transition-colors', itemsPerPage === 50 ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:text-gray-700']"
              >
                50
              </button>
            </div>
          </div>

          <!-- Mobile Card View -->
          <div class="block md:hidden space-y-2">
            <div
              v-for="(item, index) in paginatedItems"
              :key="item.id"
              class="bg-white border border-gray-200 rounded-lg p-3 hover:shadow-md transition-shadow"
            >
              <div class="flex items-start justify-between mb-2">
                <div class="flex-1">
                  <div class="font-medium text-gray-900">{{ item.item_code }} - {{ item.description }} ({{ item.alt_uom || item.uom }})</div>
                </div>
                <button
                  @click="removeItem(index)"
                  class="h-8 w-8 p-0 hover:bg-red-50 hover:text-red-600 hover:border-red-300 flex-shrink-0 ml-2 rounded-md border border-gray-300"
                >
                  <Trash2 class="w-4 h-4" />
                </button>
              </div>
              <div class="grid grid-cols-2 gap-2 text-sm">
                <div>
                  <span class="text-gray-600">Quantity:</span>
                  <div class="mt-1 flex items-center gap-1">
                    <span class="font-medium">{{ item.quantity_ordered }}</span>
                    <button
                      @click="editItemQuantity(item, index)"
                      class="text-blue-600 hover:text-blue-800"
                    >
                      <Edit class="w-3 h-3" />
                    </button>
                  </div>
                </div>
                <div>
                  <span class="text-gray-600">SOH Stock:</span>
                  <div class="mt-1">
                    <StockIndicator
                      :available="Number(item.stock || 0)"
                      :requested="item.quantity_ordered"
                      size="small"
                    />
                  </div>
                </div>
              </div>
              <div class="mt-2 pt-2 border-t border-gray-100">
                <button
                  @click="editItemQuantity(item, index)"
                  class="w-full text-sm text-blue-600 hover:text-blue-800 font-medium"
                >
                  Edit Quantity
                </button>
              </div>
            </div>

            <!-- Empty State for Mobile -->
            <div v-if="paginatedItems.length === 0" class="text-center py-8 text-gray-500">
              <Package class="w-12 h-12 text-gray-400 mx-auto mb-2" />
              <p>No items found. Try searching or add items manually.</p>
            </div>
          </div>

          <!-- Desktop Table View -->
          <div class="hidden md:block">
            <div class="overflow-x-auto border border-gray-200 rounded-lg">
              <table class="w-full text-sm">
                <!-- Table Header -->
                <thead class="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-900">Description</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-900">Quantity</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-900">SOH Stock</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-900">Actions</th>
                  </tr>
                </thead>

                <!-- Table Body -->
                <tbody class="divide-y divide-gray-200">
                  <tr v-for="(item, index) in paginatedItems" :key="item.id" class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-900">
                      <div>
                        <p class="font-medium">{{ item.item_code }} - {{ item.description }} ({{ item.alt_uom || item.uom }})</p>
                      </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                      <div class="flex items-center justify-center gap-2">
                        <span class="font-medium">{{ item.quantity_ordered }}</span>
                        <button
                          @click="editItemQuantity(item, index)"
                          class="text-blue-600 hover:text-blue-800"
                        >
                          <Edit class="w-3 h-3" />
                        </button>
                      </div>
                      <p v-if="formErrors[`item_${index}_quantity`]" class="text-xs text-red-600 text-center">
                        {{ formErrors[`item_${index}_quantity`] }}
                      </p>
                      <p v-if="formErrors[`item_${index}_stock`]" class="text-xs text-red-600 text-center">
                        {{ formErrors[`item_${index}_stock`] }}
                      </p>
                    </td>
                    <td class="px-4 py-3 text-gray-900">
                      <StockIndicator
                        :available="Number(item.stock || 0)"
                        :requested="item.quantity_ordered"
                        size="small"
                      />
                    </td>
                    <td class="px-4 py-3 text-center">
                      <div class="flex justify-center gap-1">
                        <button
                          @click="editItemQuantity(item, index)"
                          class="text-blue-600 hover:text-blue-800 text-sm font-medium"
                        >
                          Edit
                        </button>
                        <button
                          @click="removeItem(index)"
                          class="text-red-600 hover:text-red-800"
                        >
                          <Trash2 class="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>

                  <!-- Empty State for Desktop -->
                  <tr v-if="paginatedItems.length === 0">
                    <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                      <div class="flex flex-col items-center">
                        <Package class="w-12 h-12 text-gray-400 mb-2" />
                        <p>No items found. Try searching or add items manually.</p>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Pagination -->
          <div v-if="filteredItems.length > itemsPerPage" class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 border-t border-gray-200">
            <div class="text-sm text-gray-700">
              Showing {{ (currentPage - 1) * itemsPerPage + 1 }} to {{ Math.min(currentPage * itemsPerPage, filteredItems.length) }} of {{ filteredItems.length }} items
            </div>
            <div class="flex items-center gap-1">
              <button
                @click="currentPage--"
                :disabled="currentPage === 1"
                class="h-8 w-8 p-0 rounded-md border border-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <ChevronLeft class="w-4 h-4" />
              </button>

              <button
                v-for="page in visiblePages"
                :key="page"
                @click="currentPage = page === '...' ? null : currentPage = page"
                :disabled="page === '...'"
                :class="[
                  'h-8 w-8 p-0 rounded-md text-sm font-medium transition-colors',
                  currentPage === page ? 'bg-blue-600 text-white' :
                  page === '...' ? 'text-gray-500 cursor-default' :
                  'border border-gray-300 hover:bg-gray-50'
                ]"
              >
                {{ page }}
              </button>

              <button
                @click="currentPage++"
                :disabled="currentPage === totalPages"
                class="h-8 w-8 p-0 rounded-md border border-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <ChevronRight class="w-4 h-4" />
              </button>
            </div>
          </div>

          <!-- Form Errors -->
          <div v-if="formErrors.items" class="mt-4">
            <Alert variant="destructive">
              <AlertTriangle class="w-4 h-4" />
              <AlertDescription>
                {{ formErrors.items }}
              </AlertDescription>
            </Alert>
          </div>

          <!-- Form Actions -->
          <div class="flex flex-col sm:flex-row gap-3 justify-end pt-4 border-t border-gray-200">
            <div class="flex gap-2">
              <Button
                @click="clearAllItems"
                variant="outline"
                class="text-red-600 hover:text-red-700 hover:bg-red-50"
                :disabled="selectedItems.length === 0"
              >
                <Trash2 class="w-4 h-4 mr-2" />
                Clear All
              </Button>
                          </div>
            <Button
              @click="submitForm"
              :disabled="isSubmitting || !isValidStoreSelection"
              class="min-w-[120px]"
            >
              <svg v-if="isSubmitting" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ isSubmitting ? 'Updating...' : 'Update Transfer' }}
            </Button>
          </div>
        </div>
      </div>
    </div>

    <!-- Edit Quantity Dialog (from MassOrders) -->
    <Dialog v-model:open="isEditQuantityModalOpen">
      <DialogContent class="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Edit Quantity</DialogTitle>
          <DialogDescription>
            Make changes to the quantity here. Click save when you're done.
          </DialogDescription>
        </DialogHeader>
        <div class="grid gap-4 py-4">
          <div class="grid grid-cols-4 items-center gap-4">
            <Label for="quantity" class="text-right"> Quantity </Label>
            <Input
              id="quantity"
              type="number"
              class="col-span-3"
              v-model="formQuantity.quantity"
            />
          </div>
        </div>
        <DialogFooter>
          <Button @click="editQuantity">Save changes</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </Layout>
</template>

<style scoped>
/* Custom styles for better responsive design */
@media (max-width: 768px) {
  .grid-cols-3 {
    grid-template-columns: 1fr;
  }

  .col-span-2 {
    grid-column: span 1;
  }
}
</style>