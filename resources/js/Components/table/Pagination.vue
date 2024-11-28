<script setup>
defineProps({
    data: {
        type: Object,
        required: true,
    },
});
</script>
<template>
    <div v-if="data.data.length === 0" class="p-5 flex justify-center w-full">
        Nothing to show
    </div>
    <div
        v-if="data.data.length !== 0"
        class="flex items-center justify-end gap-2"
    >
        <Component
            v-for="link in data.links"
            :is="link.url ? 'Link' : 'span'"
            :href="link.url"
            v-html="link.label"
            class="px-3 py-1 border border-gray-200 text-primary-font font-bold rounded-lg"
            :class="{
                'bg-primary text-white': link.active,
                'hover:bg-primary/50 transition-colors transition-duration duration-300':
                    link.url,
            }"
        />
    </div>
</template>
