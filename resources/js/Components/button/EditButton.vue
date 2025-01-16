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
        class="cursor-pointer text-blue-500 p-0 sm:mr-3 size-5"
        variant="link"
    >
        <Pencil />
    </Component>
</template>
