<script setup>
import { ArrowLeft } from "lucide-vue-next";
import { Link } from "@inertiajs/vue3"; // Assuming Link is globally registered or imported as needed

defineProps({
    href: {
        type: String,
        required: false, // Not required if it just goes back
    },
});

const goBack = () => {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        // Fallback if history is not available (e.g., direct access)
        // You might want to define a default route or let the user handle it
        console.warn("No history to go back to. Consider defining a fallback route.");
    }
};
</script>

<template>
    <Link
        v-if="href"
        :href="href"
        class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground h-9 px-4 py-2"
    >
        <ArrowLeft class="h-4 w-4 mr-2" />
        Back
    </Link>
    <button
        v-else
        @click="goBack"
        type="button"
        class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground h-9 px-4 py-2"
    >
        <ArrowLeft class="h-4 w-4 mr-2" />
        Back
    </button>
</template>