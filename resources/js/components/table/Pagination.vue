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
    const totalPages = links.length - 2; // Exclude Previous and Next

    // Always include Previous (index 0) and Next (last index)
    const filteredLinks = [links[0]]; // Previous

    if (totalPages <= 11) {
        // If total pages is small, show all pages
        for (let i = 1; i <= totalPages; i++) {
            filteredLinks.push(links[i]);
        }
    } else {
        // Show 10 pages around current page
        let startPage = Math.max(1, activeIndex - 5);
        let endPage = Math.min(totalPages, startPage + 9);

        // Adjust start if we're near the end
        if (endPage - startPage < 9) {
            startPage = Math.max(1, endPage - 9);
        }

        // Always include first page
        if (startPage > 1) {
            filteredLinks.push(links[1]); // First page
            if (startPage > 2) {
                // Add ellipsis placeholder
                filteredLinks.push({
                    label: '...',
                    url: null,
                    active: false
                });
            }
        }

        // Add pages around current page
        for (let i = startPage; i <= endPage; i++) {
            filteredLinks.push(links[i]);
        }

        // Always include last page
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                // Add ellipsis placeholder
                filteredLinks.push({
                    label: '...',
                    url: null,
                    active: false
                });
            }
            filteredLinks.push(links[totalPages]); // Last page
        }
    }

    filteredLinks.push(links[links.length - 1]); // Next

    return filteredLinks;
}
</script>
<template>
    <div
        v-if="data.data.length !== 0"
        class="flex flex-col sm:flex-row items-center justify-between gap-4 w-full"
    >
        <div class="text-sm text-gray-600">
            Showing {{ data.from }} to {{ data.to }} of {{ data.total }} records
        </div>
        
        <div class="flex items-center justify-end gap-2">
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
    </div>
</template>
