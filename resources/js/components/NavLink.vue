<script setup>
import { Link } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    icon: {
        required: true,
    },
    isActive: {
        type: Boolean,
        default: false,
    },
});

const internalIsActive = (path) => {
    const currentUrl = usePage().url.split('?')[0];

    // Exact match
    if (path === currentUrl) {
        return true;
    }

    // Don't match root '/' as a prefix for everything
    if (path === '/') {
        return false;
    }

    // Prefix match for nested routes
    if (currentUrl.startsWith(path) && currentUrl[path.length] === '/') {
        return true;
    }

    return false;
};
</script>

<template>
    <Link
        :href="href"
        class="flex items-center gap-3 rounded-l-lg px-3 py-2 text-muted-foreground transition-all hover:text-primary"
        :class="{ 'text-primary bg-primary/10': isActive || internalIsActive(href) }"
    >
        <component :is="icon" class="h-4 w-4" />
        <slot></slot>
    </Link>
</template>