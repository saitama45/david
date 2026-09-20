<script setup>
import { onMounted, onBeforeUnmount } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useToast } from "@/composables/useToast";
import { salesStatusLabel } from "@/composables/salesImportStatus";

const page = usePage();
const { toast } = useToast();
const seen = new Set();
let timer;
let controller;
let stopped = false;
const context = () => `${page.props.auth?.user?.id}:${page.props.auth?.activeEntity}`;

async function poll() {
    const permissions = page.props.auth?.permissions ?? [];
    if (stopped || document.hidden || controller || !permissions.some((p) => ["view import logs", "create store transactions"].includes(p))) return;
    const currentContext = context();
    controller = new AbortController();
    try {
        const response = await fetch(route("import-logs.sales-completions"), {
            headers: { Accept: "application/json" }, credentials: "same-origin", signal: controller.signal,
        });
        if (!response.ok) return;
        const logs = await response.json();
        if (stopped || context() !== currentContext) return;
        const fresh = logs.filter((log) => {
            const key = `sales-import:${currentContext}:${log.id}:${log.status}:${log.updated_at}`;
            if (seen.has(key)) return false;
            seen.add(key);
            try {
                if (localStorage.getItem(key)) return false;
                localStorage.setItem(key, "1");
            } catch { /* Notifications still work when browser storage is unavailable. */ }
            return true;
        });
        if (!fresh.length) return;
        const hasFailure = fresh.some((log) => log.status === "failed");
        const hasIssues = fresh.some((log) => log.status === "completed_with_issues");
        const detail = fresh.length === 1
            ? `${fresh[0].filename}: ${salesStatusLabel(fresh[0].status)}. ${fresh[0].processed_count} processed; ${fresh[0].skipped_count} skipped / need review.`
            : `${fresh.length} sales uploads have finished. ${fresh.filter((log) => log.status !== "completed").length} need attention.`;
        toast.add({ severity: hasFailure ? "error" : hasIssues ? "warn" : "success", summary: "Sales upload update",
            detail: detail + (permissions.includes("view import logs") ? " See Work Queue for details." : ""), life: 15000 });
    } catch { /* Retry on the next interval after a network interruption. */ }
    finally { controller = null; }
}

onMounted(() => { poll(); timer = window.setInterval(poll, 10000); });
onBeforeUnmount(() => { stopped = true; clearInterval(timer); controller?.abort(); });
</script>

<template><span class="hidden" aria-hidden="true" /></template>
