<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import {
  SelectRoot,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
  SelectItemText,
  SelectViewport,
  SelectPortal,
  SelectIcon
} from 'radix-vue';
import { cn } from '@/lib/utils';
import { CaretSortIcon } from '@radix-icons/vue';
import { Search, X } from 'lucide-vue-next';
import { Input } from '@/Components/ui/input';

const props = defineProps({
  modelValue: { type: [String, Number], required: false },
  options: { type: Array, required: true },
  optionLabel: { type: String, default: 'label' },
  optionValue: { type: String, default: 'value' },
  placeholder: { type: String, default: 'Select...' },
  clearable: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  class: { type: String, required: false }
});

const emit = defineEmits(['update:modelValue']);

// Search state
const searchQuery = ref('');
const isOpen = ref(false);
const searchInputRef = ref(null);

// Computed filtered options
const filteredOptions = computed(() => {
  if (!searchQuery.value) return props.options;

  const searchTerm = searchQuery.value.toLowerCase();
  return props.options.filter(option => {
    const label = (option[props.optionLabel] || '').toLowerCase();
    const searchTerms = (option.searchTerms || '').toLowerCase();
    return label.includes(searchTerm) || searchTerms.includes(searchTerm);
  });
});

// Get display value for selected option
const displayValue = computed(() => {
  if (!props.modelValue) return '';
  const selectedOption = props.options.find(option =>
    option[props.optionValue] === props.modelValue
  );
  return selectedOption ? selectedOption[props.optionLabel] : '';
});

// Handle option selection
const handleSelect = (value) => {
  emit('update:modelValue', value);
  searchQuery.value = ''; // Clear search after selection
  isOpen.value = false;
};

// Handle clear
const handleClear = () => {
  emit('update:modelValue', null);
  searchQuery.value = '';
};

// Handle search input focus
const handleSearchFocus = () => {
  if (!isOpen.value) {
    isOpen.value = true;
  }
};

// Watch for dropdown open/close to manage search state
watch(isOpen, (newValue) => {
  if (newValue) {
    nextTick(() => {
      searchInputRef.value?.focus();
    });
  } else {
    searchQuery.value = '';
  }
});

// Handle outside click
const handleInteractOutside = () => {
  isOpen.value = false;
  searchQuery.value = '';
};
</script>

<template>
  <SelectRoot
    :model-value="modelValue"
    @update:model-value="handleSelect"
    :open="isOpen"
    @update:open="isOpen = $event"
  >
    <SelectTrigger
      :class="cn(
        'flex h-9 w-full items-center justify-between whitespace-nowrap rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm ring-offset-background data-[placeholder]:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50 [&>span]:truncate text-start',
        props.class
      )"
      :disabled="disabled"
    >
      <SelectValue :placeholder="placeholder">
        {{ displayValue || placeholder }}
      </SelectValue>
      <div class="flex items-center gap-1">
        <button
          v-if="clearable && modelValue"
          @click.stop="handleClear"
          class="rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:pointer-events-none"
          type="button"
        >
          <X class="h-3.5 w-3.5" />
        </button>
        <SelectIcon as-child>
          <CaretSortIcon class="h-4 w-4 opacity-50 shrink-0" />
        </SelectIcon>
      </div>
    </SelectTrigger>

    <SelectPortal>
      <SelectContent
        @interact-outside="handleInteractOutside"
        class="relative z-50 max-h-96 min-w-[8rem] overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2"
        position="popper"
        side="bottom"
        :side-offset="4"
      >
        <!-- Search Input -->
        <div class="p-2 border-b">
          <div class="relative">
            <Search class="absolute left-2 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" />
            <Input
              ref="searchInputRef"
              v-model="searchQuery"
              placeholder="Search stores..."
              class="pl-8 pr-8 h-8 text-sm"
              @focus="handleSearchFocus"
            />
            <button
              v-if="searchQuery"
              @click="searchQuery = ''"
              class="absolute right-2 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500 hover:text-gray-700"
            >
              <X class="h-4 w-4" />
            </button>
          </div>
        </div>

        <!-- Options List -->
        <SelectViewport class="p-1">
          <SelectItem
            v-for="option in filteredOptions"
            :key="option[optionValue]"
            :value="option[optionValue]"
            class="relative flex w-full cursor-default select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm outline-none focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
          >
            <span class="absolute left-2 flex h-3.5 w-3.5 items-center justify-center">
              <SelectItemText />
            </span>

            <SelectItemText>
              {{ option[optionLabel] }}
            </SelectItemText>
          </SelectItem>

          <!-- No Results Message -->
          <div
            v-if="filteredOptions.length === 0 && searchQuery"
            class="relative flex w-full cursor-default select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm text-muted-foreground"
          >
            <span class="absolute left-2">
              <Search class="h-3.5 w-3.5" />
            </span>
            No stores found
          </div>

          <!-- No Options Available -->
          <div
            v-if="options.length === 0"
            class="relative flex w-full cursor-default select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm text-muted-foreground"
          >
            No stores available
          </div>
        </SelectViewport>
      </SelectContent>
    </SelectPortal>
  </SelectRoot>
</template>