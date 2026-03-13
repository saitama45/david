<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue'
import axios from 'axios'
import { debounce } from 'lodash'

const props = defineProps({
    modelValue: {
        type: [String, Object],
        default: ''
    },
    sendingStoreId: {
        type: [Number, null],
        default: null
    },
    placeholder: {
        type: String,
        default: 'Type at least 3 characters to search...'
    },
    disabled: {
        type: Boolean,
        default: false
    },
    searchRoute: {
        type: String,
        default: 'interco.items.search'
    }
})

const emit = defineEmits(['update:modelValue', 'item-selected'])

// Component state
const searchInput = ref('')
const isDropdownOpen = ref(false)
const searchResults = ref([])
const isLoading = ref(false)
const errorMessage = ref('')
const highlightedIndex = ref(-1)
const inputRef = ref(null)
const dropdownRef = ref(null)

// Fixed positioning state
const dropdownPosition = ref({ top: '0px', left: '0px', width: '0px' })

// Calculate dropdown position for fixed positioning
const calculateDropdownPosition = async () => {
    if (!inputRef.value || !isDropdownOpen.value) return

    await nextTick()

    const inputRect = inputRef.value.getBoundingClientRect()
    const scrollY = window.pageYOffset || document.documentElement.scrollTop
    const scrollX = window.pageXOffset || document.documentElement.scrollLeft

    // Calculate position with viewport boundary checks
    const dropdownHeight = 240 // Approximate max height (60 * 4 rows)
    const spaceBelow = window.innerHeight - inputRect.bottom
    const spaceAbove = inputRect.top

    let top = inputRect.bottom + scrollY

    // If not enough space below, position above the input
    if (spaceBelow < dropdownHeight && spaceAbove > dropdownHeight) {
        top = inputRect.top + scrollY - dropdownHeight
    }

    // Ensure dropdown doesn't go off screen horizontally
    const maxWidth = 320 // Maximum width for dropdown
    let left = inputRect.left + scrollX
    let width = Math.min(inputRect.width, maxWidth)

    // Adjust if dropdown would go off right edge
    if (left + width > window.innerWidth + scrollX) {
        left = window.innerWidth + scrollX - width - 8 // 8px padding
    }

    // Ensure dropdown doesn't go off left edge
    if (left < scrollX) {
        left = scrollX + 8
    }

    dropdownPosition.value = {
        top: `${top}px`,
        left: `${left}px`,
        width: `${width}px`
    }
}

// Update dropdown position when it opens or window resizes
const updateDropdownPosition = () => {
    if (isDropdownOpen.value) {
        calculateDropdownPosition()
    }
}

// Debounced search function
const debouncedSearch = debounce(async (searchTerm) => {
    if (!searchTerm || searchTerm.length < 3) {
        searchResults.value = []
        isDropdownOpen.value = false
        return
    }

    if (!props.sendingStoreId) {
        errorMessage.value = 'Please select a sending store first'
        return
    }

    isLoading.value = true
    errorMessage.value = ''

    try {
        // Determine the param key based on the route
        const storeParamKey = props.searchRoute === 'wastage.items.search' ? 'store_id' : 'sending_store_id';
        
        const params = {
            search: searchTerm
        };
        params[storeParamKey] = props.sendingStoreId;

        const response = await axios.get(route(props.searchRoute), {
            params: params
        });

        searchResults.value = (response.data.items || []).filter(item => item.stock > 0 || props.searchRoute === 'wastage.items.search')
        isDropdownOpen.value = searchResults.value.length > 0
        highlightedIndex.value = -1

        // Calculate dropdown position when results are loaded
        if (isDropdownOpen.value) {
            await calculateDropdownPosition()
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'Failed to search items'
        searchResults.value = []
        isDropdownOpen.value = false
    } finally {
        isLoading.value = false
    }
}, 300)

// Watch for modelValue changes from parent
watch(() => props.modelValue, (newValue) => {
    if (newValue && typeof newValue === 'object') {
        searchInput.value = `${newValue.item_code} - ${newValue.description}`
    } else {
        searchInput.value = ''
    }
})

// Watch for search input changes
watch(searchInput, (newValue) => {
    debouncedSearch(newValue)
})

// Handle item selection
const selectItem = (item) => {
    searchInput.value = `${item.item_code} - ${item.description}`
    emit('update:modelValue', item)
    emit('item-selected', item)
    isDropdownOpen.value = false
    highlightedIndex.value = -1
}

// Handle input click (show initial results if any)
const handleInputClick = async () => {
    if (searchResults.value.length > 0 || searchInput.value.length >= 3) {
        isDropdownOpen.value = true
        await calculateDropdownPosition()
    }
}

// Handle keyboard navigation
const handleKeyDown = (event) => {
    if (!isDropdownOpen.value) return

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault()
            highlightedIndex.value = Math.min(highlightedIndex.value + 1, searchResults.value.length - 1)
            break
        case 'ArrowUp':
            event.preventDefault()
            highlightedIndex.value = Math.max(highlightedIndex.value - 1, -1)
            break
        case 'Enter':
            event.preventDefault()
            if (highlightedIndex.value >= 0 && searchResults.value[highlightedIndex.value]) {
                selectItem(searchResults.value[highlightedIndex.value])
            }
            break
        case 'Escape':
            isDropdownOpen.value = false
            highlightedIndex.value = -1
            break
    }
}

// Click outside handler
const handleClickOutside = (event) => {
    if (!dropdownRef.value?.contains(event.target) && !inputRef.value?.contains(event.target)) {
        isDropdownOpen.value = false
        highlightedIndex.value = -1
    }
}

// Clear selection
const clearSelection = () => {
    searchInput.value = ''
    emit('update:modelValue', '')
    emit('item-selected', null)
    searchResults.value = []
    isDropdownOpen.value = false
}

// Lifecycle hooks
onMounted(() => {
    document.addEventListener('click', handleClickOutside)
    window.addEventListener('resize', updateDropdownPosition)
    window.addEventListener('scroll', updateDropdownPosition)
})

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside)
    window.removeEventListener('resize', updateDropdownPosition)
    window.removeEventListener('scroll', updateDropdownPosition)
})

// Initialize searchInput based on modelValue
if (props.modelValue && typeof props.modelValue === 'object') {
    searchInput.value = `${props.modelValue.item_code} - ${props.modelValue.description}`
}
</script>

<template>
    <div class="relative dropdown-container" ref="dropdownRef">
        <!-- Input Field -->
        <div class="relative">
            <input
                ref="inputRef"
                v-model="searchInput"
                type="text"
                :placeholder="sendingStoreId ? placeholder : 'Please select a sending store first'"
                :disabled="disabled"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 pr-10"
                @click="handleInputClick"
                @keydown="handleKeyDown"
                @focus="handleInputClick"
            />

            <!-- Clear Button -->
            <button
                v-if="searchInput && !disabled"
                type="button"
                class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600"
                @click="clearSelection"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>

            <!-- Loading Spinner -->
            <div
                v-if="isLoading"
                class="absolute right-2 top-1/2 transform -translate-y-1/2"
            >
                <svg class="animate-spin h-4 w-4 text-blue-500" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
        </div>

        <!-- Error Message -->
        <div v-if="errorMessage" class="mt-1 text-sm text-red-600">
            {{ errorMessage }}
        </div>

        <!-- Search Results Dropdown -->
        <div
            v-if="isDropdownOpen && searchResults.length > 0"
            class="fixed z-dropdown bg-white border border-gray-300 rounded-md shadow-2xl max-h-60 overflow-y-auto"
            :style="{
                top: dropdownPosition.top,
                left: dropdownPosition.left,
                width: dropdownPosition.width
            }"
        >
            <div
                v-for="(item, index) in searchResults"
                :key="item.id"
                class="px-3 py-2 cursor-pointer hover:bg-gray-100 border-b border-gray-100 last:border-b-0"
                :class="{ 'bg-blue-50': highlightedIndex === index }"
                @click="selectItem(item)"
                @mouseenter="highlightedIndex = index"
            >
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <div class="font-medium text-sm text-gray-900">
                            {{ item.item_code }}
                        </div>
                        <div class="text-sm text-gray-600">
                            {{ item.description }}
                        </div>
                        <div class="text-xs text-gray-500">
                            UOM: {{ item.alt_uom || item.uom }}
                        </div>
                    </div>
                    <div class="ml-2 text-right">
                        <div class="text-sm font-medium" :class="item.stock > 0 ? 'text-green-600' : 'text-red-600'">
                            Stock: {{ item.stock }}
                        </div>
                        <div v-if="item.stock > 0" class="text-xs text-green-500">
                            Available
                        </div>
                        <div v-else class="text-xs text-red-500">
                            Out of stock
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- No Results Message -->
        <div
            v-if="isDropdownOpen && !isLoading && searchInput.length >= 3 && searchResults.length === 0 && !errorMessage"
            class="fixed z-dropdown bg-white border border-gray-300 rounded-md shadow-2xl px-3 py-2 text-sm text-gray-500"
            :style="{
                top: dropdownPosition.top,
                left: dropdownPosition.left,
                width: dropdownPosition.width
            }"
        >
            No items found matching "{{ searchInput }}"
        </div>

        <!-- Search Hint -->
        <div
            v-if="!isDropdownOpen && searchInput.length < 3 && searchInput.length > 0"
            class="mt-1 text-xs text-gray-500"
        >
            Type at least 3 characters to search
        </div>
    </div>
</template>

<style scoped>
/* Improve scrollbar styling for better UX */
.max-h-60 {
    scrollbar-width: thin;
    scrollbar-color: #cbd5e0 #f7fafc;
}

.max-h-60::-webkit-scrollbar {
    width: 6px;
}

.max-h-60::-webkit-scrollbar-track {
    background: #f7fafc;
    border-radius: 3px;
}

.max-h-60::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 3px;
}

.max-h-60::-webkit-scrollbar-thumb:hover {
    background: #a0aec0;
}

/* Animation for dropdown */
@media (prefers-reduced-motion: no-preference) {
    .fixed.z-dropdown {
        animation: dropdown-fade-in 0.1s ease-out;
    }
}

@keyframes dropdown-fade-in {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>