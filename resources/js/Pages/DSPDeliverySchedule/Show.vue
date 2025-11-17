<script setup>
import { defineProps, computed } from 'vue';
import BackButton from '@/Components/BackButton.vue';

const props = defineProps({
    supplier: {
        type: Object,
        required: true,
    },
    schedulesByDay: {
        type: Object,
        required: true,
    }
});

const heading = computed(() => `Delivery Schedule for ${props.supplier.name}`);

const hasSchedules = computed(() => Object.keys(props.schedulesByDay).length > 0);
</script>

<template>
    <Layout :heading="heading">
        <div class="p-4 sm:p-6 bg-white rounded-lg shadow">
            <div class="mb-6">
                <h3 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4">Supplier Details</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Supplier Name</p>
                        <p class="text-base text-gray-900">{{ supplier.name }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Supplier Code</p>
                        <p class="text-base text-gray-900">{{ supplier.supplier_code }}</p>
                    </div>
                </div>
            </div>

            <div>
                <h3 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4">Delivery Schedules</h3>
                <div v-if="hasSchedules" class="space-y-6">
                    <div v-for="(branches, dayName) in schedulesByDay" :key="dayName">
                        <h4 class="font-bold text-md text-gray-700 capitalize mb-2">{{ dayName.toLowerCase() }}</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                            <div v-for="branch in branches" :key="branch.id" class="p-3 border rounded-md bg-gray-50 text-sm">
                                <p class="font-semibold text-gray-800">{{ branch.name }}</p>
                                <p class="text-xs text-gray-500">{{ branch.branch_code }}</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else>
                    <p class="text-gray-500">No delivery schedules found for this supplier.</p>
                </div>
            </div>
        </div>
        <BackButton :href="route('dsp-delivery-schedules.index')" />
    </Layout>
</template>