<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import AutoComplete from 'primevue/autocomplete';
import { MIN_SEARCH_CHARS, SEARCH_DELAY_MS } from '@/lib/searchRules';

// Picks a CPO Orders vendor by searching on the server. The list lives in the
// database and grows, so it is an autocomplete: matches show from MIN_SEARCH_CHARS characters.
const props = defineProps({
    // The chosen vendor; with allowNew, the text of the Vendor Name.
    modelValue: { type: [Object, String], default: null },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    searchRoute: { type: String, default: 'cpo-orders.vendors.search' },
    // A name may also be typed that is not on the list. A vendor chosen from it is sent by "select".
    allowNew: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'select']);

const suggestions = ref([]);
const typed = ref('');
const searching = ref(false);
let latestRequest = 0;

const selected = computed({
    get: () => props.modelValue,
    set: (value) => {
        if (props.allowNew) {
            if (value && typeof value === 'object') {
                emit('update:modelValue', value.name);
                emit('select', value);
            } else {
                emit('update:modelValue', value ?? '');
            }
        } else if (value && typeof value === 'object') {
            emit('update:modelValue', value);
        } else if (!value) {
            emit('update:modelValue', null);
        }
    },
});

const tooShort = computed(() => (props.allowNew || !props.modelValue) && typed.value.trim().length > 0 && typed.value.trim().length < MIN_SEARCH_CHARS);

const search = async ({ query }) => {
    typed.value = query ?? '';
    const term = typed.value.trim();
    const request = ++latestRequest;

    if (!term) {
        suggestions.value = [];
        return;
    }

    searching.value = true;
    try {
        const { data } = await axios.get(route(props.searchRoute), { params: { q: term } });
        // A slower answer to an earlier keystroke must not replace a newer one.
        if (request === latestRequest) {
            suggestions.value = data;
        }
    } catch (error) {
        if (request === latestRequest) {
            suggestions.value = [];
        }
    } finally {
        if (request === latestRequest) {
            searching.value = false;
        }
    }
};

const emptyMessage = computed(() => (typed.value.trim().length < MIN_SEARCH_CHARS
    ? `Type at least ${MIN_SEARCH_CHARS} characters to see matches.`
    : (props.allowNew ? 'Not on the vendor list yet. Saving adds it.' : 'No vendor matches. Use Add New to add it.')));
</script>

<template>
    <div class="w-full">
        <AutoComplete
            v-model="selected"
            :suggestions="suggestions"
            optionLabel="name"
            :minLength="1"
            :delay="SEARCH_DELAY_MS"
            :loading="searching"
            :disabled="disabled"
            :invalid="invalid"
            :forceSelection="!allowNew"
            showEmptyMessage
            :emptySearchMessage="emptyMessage"
            :placeholder="allowNew ? 'Type the vendor name' : `Type at least ${MIN_SEARCH_CHARS} characters to search vendors`"
            fluid
            @complete="search"
            @clear="typed = ''"
        >
            <template #option="{ option }">
                <div class="flex flex-col">
                    <span class="font-medium">{{ option.name }}</span>
                    <span class="text-xs text-gray-500">TIN: {{ option.tin || 'none' }} · {{ option.address }}</span>
                </div>
            </template>
        </AutoComplete>
        <p v-if="tooShort" class="mt-1 text-xs text-gray-500">
            Type at least {{ MIN_SEARCH_CHARS }} characters to see matches. A shorter name is found when typed in full.
        </p>
    </div>
</template>
