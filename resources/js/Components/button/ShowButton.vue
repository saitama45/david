<script setup>
const props = defineProps({
    isLink: {
        type: Boolean,
        default: false,
    },
});
</script>
<template>
    <Component
        :is="isLink ? 'Link' : 'Button'"
        class="cursor-pointer"
        variant="link"
    >
        <Eye />
    </Component>
</template>
