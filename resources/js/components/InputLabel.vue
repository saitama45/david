<script setup>
defineProps({
    value: {
        type: String,
    },
});
</script>

<template>
    <label class="block sm:text-sm text-xs font-medium text-gray-700">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
