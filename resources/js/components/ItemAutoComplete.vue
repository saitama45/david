<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick, h } from 'vue'
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
    },
    // What the search can find, for the "nothing found" message
    noResultsLabel: {
        type: String,
        default: 'items'
    },
    dropdownMaxWidth: {
        type: Number,
        default: 320
    }
})

const emit = defineEmits(['update:modelValue', 'item-selected', 'items-selected'])

// Component state
const searchInput = ref('')
const isDropdownOpen = ref(false)
const searchResults = ref([])
const searchedTerm = ref('')
const moreProducts = ref(false)
const isLoading = ref(false)
const errorMessage = ref('')
const highlightedIndex = ref(-1)
const inputRef = ref(null)
const dropdownRef = ref(null)
const listRef = ref(null)

// An item with no stock on hand is listed, so it is clear why it is missing, but cannot be picked.
// Nor can a Sub-Prep the server blocked - its `stock` is what its raw materials cover.
const canPick = (item) => !item.blocked_reason && Number(item.stock) > 0

// Rows found through a POS product carry it; they are listed under that product's heading.
// The index is the row's place in searchResults, which the keyboard moves through.
const resultGroups = computed(() => {
    const groups = []
    searchResults.value.forEach((item, index) => {
        const key = item.product?.code ?? ''
        let group = groups[groups.length - 1]
        if (!group || group.key !== key) {
            group = { key, product: item.product ?? null, subPrep: Boolean(item.product?.sub_prep), rows: [], pickable: [] }
            groups.push(group)
        }
        group.rows.push({ item, index })
        if (canPick(item)) {
            group.pickable.push(item)
        }
    })
    return groups
})

const productGroupCount = computed(() => resultGroups.value.filter(group => group.product).length)
const dropdownMaxHeight = computed(() => (productGroupCount.value > 0 ? 320 : 240))

// Marks the typed text inside a code or description, so it is clear why a row was found.
const Highlight = (highlightProps) => {
    const text = String(highlightProps.text ?? '')
    const at = searchedTerm.value ? text.toLowerCase().indexOf(searchedTerm.value.toLowerCase()) : -1
    if (at === -1) return text
    const end = at + searchedTerm.value.length
    return [text.slice(0, at), h('mark', { class: 'bg-yellow-200 text-inherit rounded-sm' }, text.slice(at, end)), text.slice(end)]
}
Highlight.props = ['text']

// Fixed positioning state
const dropdownPosition = ref({ top: '0px', bottom: 'auto', left: '0px', width: '0px' })

// Calculate dropdown position for fixed positioning
const calculateDropdownPosition = async () => {
    if (!inputRef.value || !isDropdownOpen.value) return

    await nextTick()

    const inputRect = inputRef.value.getBoundingClientRect()
    const scrollY = window.pageYOffset || document.documentElement.scrollTop
    const scrollX = window.pageXOffset || document.documentElement.scrollLeft

    // Calculate position with viewport boundary checks
    const dropdownHeight = dropdownMaxHeight.value // Approximate max height
    const spaceBelow = window.innerHeight - inputRect.bottom
    const spaceAbove = inputRect.top

    let top = `${inputRect.bottom + scrollY}px`
    let bottom = 'auto'

    // If not enough space below, open upwards from the input, whatever the list's height
    if (spaceBelow < dropdownHeight && spaceAbove > dropdownHeight) {
        top = 'auto'
        bottom = `${window.innerHeight - inputRect.top}px`
    }

    // Ensure dropdown doesn't go off screen horizontally
    const maxWidth = props.dropdownMaxWidth // Maximum width for dropdown
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
        top,
        bottom,
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

// Only the latest search may show its results; an answer that arrives after a newer
// search, or after an item was picked, is dropped.
let searchSeq = 0

// Debounced search function
const debouncedSearch = debounce(async (searchTerm) => {
    const seq = ++searchSeq

    if (!searchTerm || searchTerm.length < 3) {
        searchResults.value = []
        isDropdownOpen.value = false
        isLoading.value = false
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

        if (seq !== searchSeq) return

        searchResults.value = (response.data.items || []).filter(item => item.stock > 0 || props.searchRoute === 'wastage.items.search')
        searchedTerm.value = searchTerm
        moreProducts.value = Boolean(response.data.more_products)
        // Stays open with nothing found, so the "nothing found" message answers the search
        isDropdownOpen.value = true
        highlightedIndex.value = -1

        // Calculate dropdown position when results are loaded
        await calculateDropdownPosition()
    } catch (error) {
        if (seq !== searchSeq) return

        errorMessage.value = error.response?.data?.message || 'Failed to search items'
        searchResults.value = []
        isDropdownOpen.value = false
    } finally {
        if (seq === searchSeq) {
            isLoading.value = false
        }
    }
}, 300)

// The text a selection writes into the box is not something to search for.
let skipNextSearch = false
const showSelection = (item) => {
    const text = `${item.item_code} - ${item.description}`
    if (searchInput.value !== text) {
        skipNextSearch = true
        searchInput.value = text
    }
}

// Watch for modelValue changes from parent
watch(() => props.modelValue, (newValue) => {
    if (newValue && typeof newValue === 'object') {
        showSelection(newValue)
    } else {
        searchInput.value = ''
    }
})

// Watch for search input changes
watch(searchInput, (newValue) => {
    if (skipNextSearch) {
        skipNextSearch = false
        debouncedSearch.cancel()
        searchSeq++
        isLoading.value = false
        return
    }
    debouncedSearch(newValue)
})

// Handle item selection
const selectItem = (item) => {
    if (!canPick(item)) return

    showSelection(item)
    emit('update:modelValue', item)
    emit('item-selected', item)
    isDropdownOpen.value = false
    highlightedIndex.value = -1
}

// Hand over every ingredient of one product that has stock, at once
const selectGroup = (group) => {
    emit('items-selected', {
        product: group.product,
        items: group.pickable,
        outOfStock: group.rows.length - group.pickable.length,
    })
    clearSelection()
}

const showProducts = () => {
    const heading = listRef.value?.querySelector('[data-product-heading]')
    if (heading) {
        listRef.value.scrollTop = heading.offsetTop
    }
}

const moveHighlight = (index) => {
    highlightedIndex.value = index
    nextTick(() => {
        listRef.value?.querySelector(`[data-result-index="${index}"]`)?.scrollIntoView({ block: 'nearest' })
    })
}

// The next row the keyboard can land on: rows that cannot be picked are stepped over.
const nextPickable = (from, step) => {
    for (let index = from + step; index >= 0 && index < searchResults.value.length; index += step) {
        if (canPick(searchResults.value[index])) return index
    }
    return step > 0 ? from : -1
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
            moveHighlight(nextPickable(highlightedIndex.value, 1))
            break
        case 'ArrowUp':
            event.preventDefault()
            moveHighlight(nextPickable(highlightedIndex.value, -1))
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
    showSelection(props.modelValue)
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
            ref="listRef"
            class="fixed z-dropdown bg-white border border-gray-300 rounded-md shadow-2xl max-h-60 overflow-y-auto"
            :style="{
                top: dropdownPosition.top,
                bottom: dropdownPosition.bottom,
                left: dropdownPosition.left,
                width: dropdownPosition.width,
                maxHeight: `${dropdownMaxHeight}px`
            }"
        >
            <template v-for="group in resultGroups" :key="group.key">
                <!-- A POS product is a heading over its recipe's ingredients, never a choice itself -->
                <div
                    v-if="group.product"
                    data-product-heading
                    class="sticky top-0 z-10 flex items-center justify-between gap-2 px-3 py-2 bg-amber-50 border-y border-amber-200"
                >
                    <div class="min-w-0">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">
                            {{ group.subPrep ? 'Sub-Prep' : 'Ingredients of' }}
                        </div>
                        <div class="text-sm font-semibold text-gray-900 truncate" :title="`${group.product.code} - ${group.product.description || ''}`">
                            <Highlight :text="group.product.code" />
                            <span v-if="group.product.description"> · <Highlight :text="group.product.description" /></span>
                        </div>
                    </div>
                    <button
                        v-if="!group.subPrep && group.rows.length > 1 && group.pickable.length > 0"
                        type="button"
                        class="shrink-0 px-2 py-1 text-xs font-medium text-white bg-green-600 rounded hover:bg-green-700"
                        @click.stop="selectGroup(group)"
                    >
                        {{ group.pickable.length === group.rows.length ? `Add all ${group.rows.length}` : `Add ${group.pickable.length} in stock` }}
                    </button>
                    <span v-else-if="!group.subPrep && group.rows.length > 1" class="shrink-0 text-xs font-medium text-red-600">
                        All out of stock
                    </span>
                </div>
                <div
                    v-else-if="productGroupCount > 0"
                    class="sticky top-0 z-10 flex items-center justify-between gap-2 px-3 py-1.5 bg-gray-50 border-b border-gray-200 text-[11px] font-semibold uppercase tracking-wide text-gray-500"
                >
                    <span>Items</span>
                    <button type="button" class="font-medium normal-case tracking-normal text-amber-700 hover:underline" @click.stop="showProducts">
                        {{ productGroupCount }} matching {{ productGroupCount === 1 ? 'product' : 'products' }} below ↓
                    </button>
                </div>

                <div
                    v-for="{ item, index } in group.rows"
                    :key="`${group.key}|${item.sub_prep ? 'sub-prep' : item.id}`"
                    :data-result-index="index"
                    class="px-3 py-2 border-b border-gray-100 last:border-b-0 scroll-mt-14"
                    :class="[
                        canPick(item) ? 'cursor-pointer hover:bg-gray-100' : 'cursor-not-allowed bg-gray-50 opacity-60',
                        { 'bg-blue-50': highlightedIndex === index }
                    ]"
                    :aria-disabled="!canPick(item)"
                    :title="canPick(item) ? null : (item.blocked_reason || 'Out of stock. This item cannot be added.')"
                    @click="selectItem(item)"
                    @mouseenter="highlightedIndex = canPick(item) ? index : -1"
                >
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <div class="font-medium text-sm text-gray-900">
                                <Highlight :text="item.item_code" />
                            </div>
                            <div class="text-sm text-gray-600">
                                <Highlight :text="item.description" />
                            </div>
                            <div class="text-xs text-gray-500">
                                UOM: {{ item.alt_uom || item.uom || 'not set' }}
                                <span v-if="item.sub_prep" class="text-amber-700">
                                    · Wasted as itself
                                </span>
                                <span v-else-if="item.recipe_qty" class="text-amber-700">
                                    · Recipe uses {{ item.recipe_qty }} {{ item.recipe_uom }}
                                </span>
                            </div>
                        </div>

                        <!-- A Sub-Prep has no stock of its own: what counts is what its raw materials cover -->
                        <div v-if="item.sub_prep" class="ml-2 text-right max-w-[45%]">
                            <template v-if="canPick(item)">
                                <div class="text-sm font-medium text-green-600">
                                    Enough for {{ item.stock }} {{ item.alt_uom }}
                                </div>
                                <div class="text-xs text-green-500">Available</div>
                            </template>
                            <template v-else>
                                <div class="text-sm font-medium text-red-600">Cannot be added</div>
                                <div class="text-xs text-red-500">{{ item.blocked_reason }}</div>
                            </template>
                        </div>
                        <div v-else class="ml-2 text-right">
                            <div class="text-sm font-medium" :class="item.stock > 0 ? 'text-green-600' : 'text-red-600'">
                                Stock: {{ item.stock }}
                            </div>
                            <div v-if="item.stock > 0" class="text-xs text-green-500">
                                Available
                            </div>
                            <div v-else class="text-xs text-red-500">
                                Out of stock
                                <div class="text-gray-600">Cannot be added</div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <div v-if="moreProducts" class="px-3 py-2 text-xs text-gray-500 bg-gray-50 border-t border-gray-200">
                More products match. Type more of the name or code to narrow the list.
            </div>
        </div>

        <!-- No Results Message -->
        <div
            v-if="isDropdownOpen && !isLoading && searchInput.length >= 3 && searchResults.length === 0 && !errorMessage"
            class="fixed z-dropdown bg-white border border-gray-300 rounded-md shadow-2xl px-3 py-2 text-sm text-gray-500"
            :style="{
                top: dropdownPosition.top,
                bottom: dropdownPosition.bottom,
                left: dropdownPosition.left,
                width: dropdownPosition.width
            }"
        >
            No {{ noResultsLabel }} found matching "{{ searchInput }}"
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