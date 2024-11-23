<script setup>
import { computed } from "vue";

const props = defineProps({
    filter: {
        type: String,
        required: true,
    },
    currentFilter: {
        type: String,
        required: true,
    },
    hasBadge: {
        type: Boolean,
        default: false,
    },
    badgeText: {
        type: String,
    },
    label: {
        type: String,
    },
});

const isFilterActive = computed(() => {
    return props.filter === props.currentFilter
        ? "bg-primary text-white"
        : "bg-white/10 text-gray-800";
});
</script>

<template>
    <Button class="px-10 hover:text-white gap-3" :class="isFilterActive">
        {{ label }}
        <Badge
            v-if="hasBadge"
            class="border border-gray"
            :class="{
                'bg-transparent text-gray-900': !isFilterActive,
                'text-white': isFilterActive,
            }"
        >
            {{ badgeText }}
        </Badge>
    </Button>
</template>
