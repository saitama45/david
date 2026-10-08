<script setup>
import { computed } from "vue";

// Who created a record and who changed it last. A name or a date that is not known
// (nothing saved yet, or saved before changes were recorded) shows as a dash.
const props = defineProps({
    details: {
        type: Object,
        default: () => ({}),
    },
});

const formatDate = (value) =>
    value
        ? new Date(value).toLocaleString("en-US", {
              month: "short",
              day: "numeric",
              year: "numeric",
              hour: "numeric",
              minute: "2-digit",
              timeZone: "Asia/Manila",
          })
        : "—";

const items = computed(() => [
    { label: "Created By", value: props.details.created_by || "—" },
    { label: "Updated By", value: props.details.updated_by || "—" },
    { label: "Created At", value: formatDate(props.details.created_at) },
    { label: "Updated At", value: formatDate(props.details.updated_at) },
]);
</script>

<template>
    <div class="grid sm:grid-cols-2 gap-3">
        <div
            v-for="item in items"
            :key="item.label"
            class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-3"
        >
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                {{ item.label }}
            </p>
            <p class="mt-1 text-sm font-semibold text-gray-800">
                {{ item.value }}
            </p>
        </div>
    </div>
</template>
