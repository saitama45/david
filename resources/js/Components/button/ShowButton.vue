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
        :is="isLink ? 'Link' : 'button'"
        class="cursor-pointer p-0 size-5 sm:mr-3"
        variant="link"
    >
        <Eye />
    </Component>
</template>
