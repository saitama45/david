<script setup>
import { Link } from "@inertiajs/vue3";
import { usePage, router } from "@inertiajs/vue3";

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
    // A count shown at the right of the link; nothing below 1.
    badge: {
        type: Number,
        default: 0,
    },
    badgeTitle: {
        type: String,
        default: "",
    },
    // Where a click on the badge itself goes (the link keeps its own href).
    badgeHref: {
        type: String,
        default: null,
    },
});

const openBadge = (event) => {
    if (!props.badgeHref) return;
    event.preventDefault();
    event.stopPropagation();
    router.visit(props.badgeHref);
};

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
        <span
            v-if="badge > 0"
            class="ml-auto inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1.5 text-[11px] font-semibold leading-5 text-white"
            :class="{ 'cursor-pointer hover:bg-red-600': badgeHref }"
            :title="badgeTitle"
            data-testid="nav-badge"
            @click="openBadge"
        >{{ badge > 99 ? '99+' : badge }}</span>
    </Link>
</template>
