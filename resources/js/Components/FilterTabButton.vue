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
        ? "bg-primary text-white text-xs"
        : "bg-white/10 text-gray-800 text-xs";
});
</script>

<template>
    <Button
        class="sm:px-10 px-5 hover:text-white gap-3"
        :class="isFilterActive"
    >
        {{ label }}
        <Badge
            v-if="hasBadge"
            class="border border-gray text-xs py-2"
            :class="{
                'bg-transparent text-gray-900': !isFilterActive,
                'text-white': isFilterActive,
            }"
        >
            {{ badgeText }}
        </Badge>
    </Button>
</template>
