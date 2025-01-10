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
        class="cursor-pointer text-blue-500 p-0 mr-3"
        variant="link"
    >
        <Pencil />
    </Component>
</template>
