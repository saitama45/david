<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
const data = ref(null);
const error = ref(false);
const controller = new AbortController();
async function load() {
    error.value = false;
    try { data.value = (await axios.get(route('my-actions.index'), { headers: { Accept: 'application/json' }, signal: controller.signal })).data; }
    catch (e) { if (!axios.isCancel(e)) error.value = true; }
}
onMounted(load);
onUnmounted(() => controller.abort());
</script>
<template>
    <section class="mb-5 rounded-lg border border-blue-200 bg-blue-50 p-4">
        <div class="flex items-center justify-between gap-3"><h2 class="font-semibold">My Actions</h2><Link :href="route('my-actions.index')" class="font-medium text-blue-700 underline">Open all tasks →</Link></div>
        <p v-if="data" class="mt-2 text-sm">{{ data.summary.mine }} available to you · {{ data.summary.waiting }} waiting for others · {{ data.summary.unassigned }} need assignment · {{ data.summary.overdue }} overdue</p>
        <p v-else-if="error" class="mt-2 text-sm">Tasks could not be loaded. <button class="underline" @click="load">Retry</button></p>
        <p v-else class="mt-2 text-sm" role="status">Loading your tasks…</p>
        <ul v-if="data?.tasks?.length" class="mt-3 space-y-2 text-sm"><li v-for="task in data.tasks" :key="task.id"><Link :href="task.url || route('my-actions.index')" class="underline">{{ task.action }} — {{ task.branch }} · {{ task.reference }}</Link></li></ul>
    </section>
</template>
