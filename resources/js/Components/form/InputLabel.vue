<script setup>
defineProps({
    isRequired: {
        type: Boolean,
        default: true,
    },
    label: {
        type: String,
        default: "test",
    },
});
</script>
<template>
    <Label>
        {{ label }} <span v-if="isRequired" class="text-red-500">*</span></Label
    >
</template>
