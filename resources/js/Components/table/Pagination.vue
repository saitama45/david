<script setup>
defineProps({
    data: {
        type: Object,
        required: true,
    },
});

function filterLinks(links) {
    if (!links || links.length === 0) return [];

    // Find the index of the active page
    const activeIndex = links.findIndex((link) => link.active);

    // Always include Previous (index 0), Next (last index)
    // First page (index 1), Last page (second-to-last index)
    // And the active page
    const filteredLinks = links.filter((link, index) => {
        return (
            index === 0 || // Previous
            index === links.length - 1 || // Next
            index === 1 || // First page
            index === links.length - 2 || // Last page
            link.active // Current page
        );
    });

    return filteredLinks;
}
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
            preserve-scroll
            preserve-state
            v-for="(link, index) in filterLinks(data.links)"
            :key="index"
            :is="link.url ? 'Link' : 'span'"
            :href="link.url"
            v-html="link.label"
            class="px-3 py-1 border border-gray-200 text-primary-font font-bold rounded-lg sm:text-sm text-xs"
            :class="{
                'bg-primary text-white': link.active,
                'hover:bg-primary/50 transition-colors transition-duration duration-300':
                    link.url,
            }"
        />
    </div>
</template>
