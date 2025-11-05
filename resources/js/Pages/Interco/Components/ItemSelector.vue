<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Search, X, Plus, Eye } from 'lucide-vue-next'
import StockIndicator from './StockIndicator.vue'

const props = defineProps({
  items: {
    type: Array,
    default: () => []
  },
  categories: {
    type: Array,
    default: () => []
  },
  recentlyUsed: {
    type: Array,
    default: () => []
  },
  sendingStoreId: {
    type: Number,
    required: true
  }
})

const emit = defineEmits(['select-item', 'quick-add', 'view-details'])

const searchQuery = ref('')
const selectedCategory = ref(null)
const showResults = ref(false)
const searchInputRef = ref(null)
const highlightedIndex = ref(-1)

// Computed properties for filtering and searching
const filteredItems = computed(() => {
  let items = props.items || []

  // Filter by category if selected
  if (selectedCategory.value) {
    items = items.filter(item => item.category_id === selectedCategory.value)
  }

  // Filter by search query
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    items = items.filter(item =>
      item.item_code.toLowerCase().includes(query) ||
      item.description.toLowerCase().includes(query)
    )
  }

  // Sort by relevance (exact matches first)
  if (searchQuery.value) {
    items.sort((a, b) => {
      const aExact = a.item_code.toLowerCase() === searchQuery.value.toLowerCase()
      const bExact = b.item_code.toLowerCase() === searchQuery.value.toLowerCase()
      if (aExact && !bExact) return -1
      if (!aExact && bExact) return 1
      return 0
    })
  }

  return items.slice(0, 10) // Limit results for performance
})

// Keyboard navigation
const handleKeyDown = (event) => {
  const items = filteredItems.value

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      highlightedIndex.value = Math.min(highlightedIndex.value + 1, items.length - 1)
      break
    case 'ArrowUp':
      event.preventDefault()
      highlightedIndex.value = Math.max(highlightedIndex.value - 1, 0)
      break
    case 'Enter':
      event.preventDefault()
      if (highlightedIndex.value >= 0 && items[highlightedIndex.value]) {
        selectItem(items[highlightedIndex.value])
      }
      break
    case 'Escape':
      clearSearch()
      break
  }
}

const selectItem = (item) => {
  emit('select-item', item)
  clearSearch()
}

const quickAdd = (item) => {
  emit('quick-add', item)
  clearSearch()
}

const viewDetails = (item) => {
  emit('view-details', item)
}

const clearSearch = () => {
  searchQuery.value = ''
  selectedCategory.value = null
  showResults.value = false
  highlightedIndex.value = -1
  searchInputRef.value?.focus()
}

const focusInput = () => {
  showResults.value = true
  highlightedIndex.value = -1
}

// Watch for search query changes
watch(searchQuery, () => {
  showResults.value = true
  highlightedIndex.value = 0
})

// Watch for selected category changes
watch(selectedCategory, () => {
  showResults.value = true
  highlightedIndex.value = 0
})

// Close results when clicking outside
const handleClickOutside = (event) => {
  if (!event.target.closest('.item-selector')) {
    showResults.value = false
  }
}

// Add click outside listener
onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
  <div class="item-selector space-y-4">
    <!-- Search Input -->
    <div class="relative">
      <Search class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-muted-foreground" />
      <Input
        ref="searchInputRef"
        v-model="searchQuery"
        placeholder="Search products by code or description..."
        class="pl-10 pr-10"
        @focus="focusInput"
        @keydown="handleKeyDown"
      />
      <Button
        v-if="searchQuery"
        size="sm"
        variant="ghost"
        class="absolute right-2 top-1/2 transform -translate-y-1/2 h-6 w-6 p-0"
        @click="clearSearch"
      >
        <X class="w-4 h-4" />
      </Button>
    </div>

    <!-- Category Filter -->
    <div v-if="categories.length > 0" class="flex gap-2 flex-wrap">
      <Button
        v-for="category in categories"
        :key="category.id"
        size="sm"
        :variant="selectedCategory === category.id ? 'default' : 'outline'"
        @click="selectedCategory = category.id"
      >
        {{ category.name }}
      </Button>
      <Button
        v-if="selectedCategory"
        size="sm"
        variant="ghost"
        @click="selectedCategory = null"
      >
        Clear
      </Button>
    </div>

    <!-- Search Results -->
    <div
      v-if="showResults && filteredItems.length > 0"
      class="border rounded-lg max-h-96 overflow-y-auto bg-background shadow-lg"
    >
      <div
        v-for="(item, index) in filteredItems"
        :key="item.id"
        class="p-4 border-b hover:bg-muted/50 cursor-pointer transition-colors"
        :class="{ 'bg-muted/50': index === highlightedIndex }"
        @click="selectItem(item)"
        @mouseenter="highlightedIndex = index"
      >
        <div class="flex items-center justify-between">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span class="font-medium text-sm">{{ item.item_code }}</span>
              <Badge v-if="item.category" variant="secondary" class="text-xs">
                {{ item.category }}
              </Badge>
            </div>
            <p class="text-sm text-muted-foreground truncate">{{ item.description }}</p>
            <div class="flex items-center gap-4 mt-2">
              <StockIndicator
                :available="Number(item.stock || 0)"
                :requested="item.quantity || 0"
                size="small"
              />
              <span class="text-sm font-medium">₱{{ (item.cost_per_quantity || 0).toFixed(2) }}</span>
              <Badge v-if="item.uom" variant="outline" class="text-xs">
                {{ item.uom }}
              </Badge>
            </div>
          </div>
          <div class="flex items-center gap-2 ml-4">
            <Button
              size="sm"
              variant="ghost"
              @click.stop="quickAdd(item)"
              title="Quick Add"
            >
              <Plus class="w-4 h-4" />
            </Button>
            <Button
              size="sm"
              variant="ghost"
              @click.stop="viewDetails(item)"
              title="View Details"
            >
              <Eye class="w-4 h-4" />
            </Button>
          </div>
        </div>
      </div>
    </div>

    <!-- No Results -->
    <div
      v-if="showResults && searchQuery && filteredItems.length === 0"
      class="border rounded-lg p-8 text-center bg-muted/30"
    >
      <div class="text-muted-foreground">
        <Search class="w-8 h-8 mx-auto mb-2 opacity-50" />
        <p>No items found for "{{ searchQuery }}"</p>
        <p class="text-sm mt-1">Try a different search term or category</p>
      </div>
    </div>

    <!-- Recently Used Items -->
    <div v-if="!searchQuery && !selectedCategory && recentlyUsed.length > 0">
      <h4 class="text-sm font-medium mb-2 flex items-center gap-2">
        <span>Recently Used</span>
        <Badge variant="secondary" class="text-xs">{{ recentlyUsed.length }}</Badge>
      </h4>
      <div class="flex flex-col gap-2">
        <div
          v-for="item in recentlyUsed"
          :key="item.id"
          class="flex items-center justify-between p-3 border rounded-lg hover:bg-muted/50 cursor-pointer transition-colors"
          @click="selectItem(item)"
        >
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="font-medium text-sm">{{ item.item_code }}</span>
              <span class="text-sm text-muted-foreground truncate">{{ item.description }}</span>
            </div>
            <div class="flex items-center gap-2 mt-1">
              <StockIndicator
                :available="Number(item.stock || 0)"
                size="small"
              />
              <span class="text-xs text-muted-foreground">₱{{ (item.cost_per_quantity || 0).toFixed(2) }}</span>
            </div>
          </div>
          <Button size="sm" variant="ghost" @click.stop="quickAdd(item)">
            <Plus class="w-4 h-4" />
          </Button>
        </div>
      </div>
    </div>

    <!-- Instructions -->
    <div v-if="!searchQuery && !selectedCategory && recentlyUsed.length === 0">
      <div class="text-center py-8 text-muted-foreground">
        <Search class="w-8 h-8 mx-auto mb-2 opacity-50" />
        <p class="text-sm">Start typing to search for products</p>
        <p class="text-xs mt-1">Use categories to filter results</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.item-selector {
  position: relative;
}
</style>