<script setup>
// The red count beside a closed sidebar group: the total of the badges inside it.
defineProps({
    count: { type: Number, default: 0 },
    title: { type: String, default: "" },
});
</script>

<template>
    <span
        v-if="count > 0"
        class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1.5 text-[11px] font-semibold leading-5 text-white"
        :title="title"
        data-testid="group-badge"
    >{{ count > 99 ? '99+' : count }}</span>
</template>
