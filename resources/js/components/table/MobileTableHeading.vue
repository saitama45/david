<script setup>
defineProps({
    title: String,
});
</script>
<template>
    <DivFlexCenter class="justify-between">
        <SpanBold class="text-sm">
            {{ title }}
        </SpanBold>
        <DivFlexCenter class="gap-3">
            <slot />
        </DivFlexCenter>
    </DivFlexCenter>
</template>
