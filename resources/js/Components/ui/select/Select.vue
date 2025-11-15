<script setup>
import { computed } from 'vue';
import {
  SelectRoot,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
  SelectItemText,
  SelectViewport,
  SelectPortal,
  SelectIcon,
  SelectItemIndicator,
} from 'radix-vue';
import { cn } from '@/lib/utils';
import { CaretSortIcon, CheckIcon } from '@radix-icons/vue';

const props = defineProps({
  modelValue: { type: [String, Number], required: false },
  options: { type: Array, required: true, default: () => [] },
  optionLabel: { type: String, default: 'label' },
  optionValue: { type: String, default: 'value' },
  placeholder: { type: String, default: 'Select...' },
  disabled: { type: Boolean, default: false },
  class: { type: String, required: false }
});

const emit = defineEmits(['update:modelValue']);

const displayValue = computed(() => {
  if (props.modelValue === undefined || props.modelValue === null) return '';
  const selectedOption = props.options.find(option =>
    option[props.optionValue] == props.modelValue
  );
  return selectedOption ? selectedOption[props.optionLabel] : '';
});

// Radix-vue select works with string values, so we ensure values are strings
const handleUpdate = (value) => {
  // Find the original option to emit the original value type (string or number)
  const selectedOption = props.options.find(option => option[props.optionValue].toString() === value);
  if (selectedOption) {
    emit('update:modelValue', selectedOption[props.optionValue]);
  }
};
</script>

<template>
  <SelectRoot
    :model-value="modelValue?.toString()"
    @update:model-value="handleUpdate"
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
      <SelectIcon as-child>
        <CaretSortIcon class="h-4 w-4 opacity-50 shrink-0" />
      </SelectIcon>
    </SelectTrigger>

    <SelectPortal>
      <SelectContent
        class="relative z-50 max-h-96 min-w-[8rem] overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md"
        position="popper"
        side="bottom"
        :side-offset="4"
      >
        <SelectViewport class="p-1">
          <SelectItem
            v-for="option in options"
            :key="option[optionValue]"
            :value="option[optionValue].toString()"
            class="relative flex w-full cursor-default select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm outline-none focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
          >
            <span class="absolute left-2 flex h-3.5 w-3.5 items-center justify-center">
              <SelectItemIndicator>
                <CheckIcon class="h-4 w-4" />
              </SelectItemIndicator>
            </span>
            <SelectItemText>
              {{ option[optionLabel] }}
            </SelectItemText>
          </SelectItem>
        </SelectViewport>
      </SelectContent>
    </SelectPortal>
  </SelectRoot>
</template>
