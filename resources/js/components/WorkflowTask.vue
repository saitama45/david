<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ task: { type: Object, required: true }, compact: Boolean });
</script>
<template>
    <div :class="compact ? 'space-y-1 text-xs' : 'space-y-2 rounded-lg border bg-white p-4 text-sm'">
        <p class="font-semibold">{{ task.action }}</p>
        <p v-if="!compact">{{ task.branch }} · {{ task.reference }} <span v-if="task.date">· {{ task.date }}</span></p>
        <p :class="task.ownership === 'unassigned' ? 'text-amber-800' : 'text-slate-700'">
            {{ task.ownership === 'mine' ? 'Action available to you' : task.ownership === 'waiting' ? 'Waiting for another responsible user' : 'No responsible user assigned' }}
        </p>
        <p v-if="task.roles?.length">Responsible roles: {{ task.roles.join(', ') }}</p>
        <p v-else>No responsible non-admin role assigned. Ask your administrator to check branch, supplier and role assignments.</p>
        <Link v-if="!task.roles?.length && task.assignment_url" :href="task.assignment_url" class="inline-block text-blue-700 underline">Review user assignments →</Link>
        <p v-if="task.deadline" :class="task.urgency === 'overdue' ? 'font-medium text-red-700' : ''">{{ task.urgency === 'overdue' ? 'Overdue — deadline:' : 'Deadline:' }} {{ task.deadline }} (Manila)</p>
        <p v-if="task.waiting_hours != null">Waiting: {{ task.waiting_hours }} hours</p>
        <p v-if="task.blocked" class="font-medium text-red-700">{{ task.rule }}</p>
        <details v-else-if="!compact"><summary class="cursor-pointer">Business rule</summary><p class="mt-1">{{ task.rule }}</p></details>
        <Link v-if="task.url" :href="task.url" class="inline-block font-medium text-blue-700 underline">{{ task.can_act && !task.blocked ? 'Open task' : 'View workflow' }} →</Link>
    </div>
</template>
