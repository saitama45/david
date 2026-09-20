<script setup>
import { computed, ref } from 'vue';
import AutoComplete from 'primevue/autocomplete';
const props = defineProps({ modelValue: [String, Number], options: { type: Array, default: () => [] }, placeholder: String });
const emit = defineEmits(['update:modelValue']);
const suggestions = ref([]);
const selected = computed({
    get: () => props.options.find(option => String(option.value) === String(props.modelValue)) ?? null,
    set: value => {
        if (value && typeof value === 'object') emit('update:modelValue', value.value);
        else if (value == null) emit('update:modelValue', null);
    },
});
const complete = ({ query }) => {
    const term = (query ?? '').trim().toLowerCase();
    suggestions.value = props.options.filter(option => option.label.toLowerCase().includes(term));
};
</script>

<template>
    <AutoComplete v-model="selected" :suggestions="suggestions" optionLabel="label" :placeholder="placeholder"
        dropdown forceSelection @complete="complete" class="w-full" inputClass="w-full" />
</template>
