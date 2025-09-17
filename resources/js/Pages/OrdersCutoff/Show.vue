<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useAuth } from "@/Composables/useAuth";

const props = defineProps({
    ordersCutoff: Object,
});

const { hasAccess } = useAuth();

const formatDays = (dayString) => {
    if (!dayString) return 'N/A';
    return dayString.replace(/,/g, ', ');
};

const formatDay = (dayNumber) => {
    const days = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };
    return days[dayNumber] || 'N/A';
};

const formatTime = (timeString) => {
    if (!timeString) return '';
    const [hours, minutes] = timeString.split(':');
    let h = parseInt(hours, 10);
    const ampm = h >= 12 ? 'P.M.' : 'A.M.';
    h = h % 12;
    h = h ? h : 12; // the hour '0' should be '12'
    return `${h}:${minutes} ${ampm}`;
};

</script>

<template>
    <Head :title="`Cutoff: ${ordersCutoff.ordering_template}`" />

    <Layout :heading="`Cutoff: ${ordersCutoff.ordering_template}`">
        <div class="p-4 bg-white shadow-md rounded-lg">
            <div class="max-w-2xl mx-auto">
                <div class="border-t border-gray-100">
                    <dl class="divide-y divide-gray-100">
                        <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
                            <dt class="text-sm font-medium leading-6 text-gray-900">Ordering Template</dt>
                            <dd class="mt-1 text-sm leading-6 text-gray-700 sm:col-span-2 sm:mt-0">{{ ordersCutoff.ordering_template }}</dd>
                        </div>

                        <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
                            <dt class="text-sm font-medium leading-6 text-gray-900">Cutoff 1</dt>
                            <dd class="mt-1 text-sm leading-6 text-gray-700 sm:col-span-2 sm:mt-0">{{ formatDay(ordersCutoff.cutoff_1_day) }} at {{ formatTime(ordersCutoff.cutoff_1_time) }}</dd>
                        </div>

                        <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
                            <dt class="text-sm font-medium leading-6 text-gray-900">Days Covered 1</dt>
                            <dd class="mt-1 text-sm leading-6 text-gray-700 sm:col-span-2 sm:mt-0">{{ formatDays(ordersCutoff.days_covered_1) }}</dd>
                        </div>

                        <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
                            <dt class="text-sm font-medium leading-6 text-gray-900">Cutoff 2</dt>
                            <dd class="mt-1 text-sm leading-6 text-gray-700 sm:col-span-2 sm:mt-0">
                                {{ ordersCutoff.cutoff_2_day ? `${formatDay(ordersCutoff.cutoff_2_day)} at ${formatTime(ordersCutoff.cutoff_2_time)}` : 'N/A' }}
                            </dd>
                        </div>

                         <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
                            <dt class="text-sm font-medium leading-6 text-gray-900">Days Covered 2</dt>
                            <dd class="mt-1 text-sm leading-6 text-gray-700 sm:col-span-2 sm:mt-0">{{ formatDays(ordersCutoff.days_covered_2) }}</dd>
                        </div>
                    </dl>
                </div>
                 <div class="mt-6 flex items-center justify-end">
                    <Link
                        :href="route('orders-cutoff.index')"
                        class="inline-flex items-center justify-center px-6 py-2 border border-gray-300 text-base font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                        Back to list
                    </Link>
                </div>
            </div>
        </div>
    </Layout>
</template>