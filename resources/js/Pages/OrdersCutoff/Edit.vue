<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import Toast from 'primevue/toast';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';

const props = defineProps({
    ordersCutoff: Object,
    variants: Array,
});

const toast = useToast();
const showConfirmDialog = ref(false);

const form = useForm({
    _method: 'PUT',
    ordering_template: props.ordersCutoff.ordering_template,
    cutoff_1_day: props.ordersCutoff.cutoff_1_day,
    cutoff_1_time: props.ordersCutoff.cutoff_1_time ? props.ordersCutoff.cutoff_1_time.substring(0, 5) : null,
    days_covered_1: props.ordersCutoff.days_covered_1 || [],
    cutoff_2_day: props.ordersCutoff.cutoff_2_day,
    cutoff_2_time: props.ordersCutoff.cutoff_2_time ? props.ordersCutoff.cutoff_2_time.substring(0, 5) : null,
    days_covered_2: props.ordersCutoff.days_covered_2 || [],
});

const groupedVariants = computed(() => {
    const supplierPrefixes = ['GSI-B', 'GSI-P', 'PUL-O'];
    const suppliers = [];
    const others = [];

    const sortedVariants = [...props.variants].sort();

    for (const variant of sortedVariants) {
        const isSupplierVariant = supplierPrefixes.some(prefix => variant.startsWith(prefix));

        if (isSupplierVariant) {
            suppliers.push({
                value: variant,
                label: variant,
            });
        } else {
            others.push({
                value: variant,
                label: variant,
            });
        }
    }

    return { suppliers, others };
});

const daysOfWeek = [
    { id: 1, name: 'Monday' },
    { id: 2, name: 'Tuesday' },
    { id: 3, name: 'Wednesday' },
    { id: 4, name: 'Thursday' },
    { id: 5, name: 'Friday' },
    { id: 6, name: 'Saturday' },
    { id: 7, name: 'Sunday' },
];

const submit = () => {
    if (form.processing) return;
    showConfirmDialog.value = false;

    form.post(route('orders-cutoff.update', props.ordersCutoff.id), {
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Success!',
                detail: 'Order cutoff has been updated.',
                life: 3000
            });
        },
    });
};

</script>

<template>
    <Head title="Edit Order Cutoff" />

    <Layout heading="Edit Order Cutoff">
        <Toast />
        <Dialog v-model:visible="showConfirmDialog" modal header="Confirmation" :style="{ width: '25rem' }">
            <div class="flex items-center">
                <i class="pi pi-exclamation-triangle mr-3" style="font-size: 2rem"></i>
                <span>Are you sure you want to update this schedule?</span>
            </div>
            <template #footer>
                <Button label="Cancel" severity="secondary" @click="showConfirmDialog = false" autofocus></Button>
                <Button label="Update" severity="info" @click="submit" :loading="form.processing"></Button>
            </template>
        </Dialog>

        <div class="p-4 bg-white shadow-md rounded-lg">
            <div class="max-w-2xl mx-auto">
                <form @submit.prevent class="space-y-8">
                    <!-- Ordering Template -->
                    <div>
                        <label for="ordering_template" class="block text-sm font-medium leading-6 text-gray-900">Ordering Template (Variant)</label>
                        <select id="ordering_template" v-model="form.ordering_template" class="mt-2 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6">
                            <option disabled value="">Please select one</option>
                            
                            <optgroup label="Suppliers" v-if="groupedVariants.suppliers.length > 0">
                                <option v-for="variant in groupedVariants.suppliers" :key="variant.value" :value="variant.value">
                                    {{ variant.label }}
                                </option>
                            </optgroup>

                            <optgroup label="Others" v-if="groupedVariants.others.length > 0">
                                <option v-for="variant in groupedVariants.others" :key="variant.value" :value="variant.value">
                                    {{ variant.label }}
                                </option>
                            </optgroup>
                        </select>
                        <p v-if="form.errors.ordering_template" class="mt-2 text-sm text-red-600">{{ form.errors.ordering_template }}</p>
                    </div>

                    <!-- Cutoff 1 -->
                    <div class="border-t border-gray-200 pt-8">
                        <h2 class="text-base font-semibold text-gray-900">Cutoff 1</h2>
                        <div class="mt-6 grid grid-cols-1 gap-x-6 gap-y-8 sm:grid-cols-6">
                            <div class="sm:col-span-3">
                                <label for="cutoff_1_day" class="block text-sm font-medium leading-6 text-gray-900">Day</label>
                                <select id="cutoff_1_day" v-model="form.cutoff_1_day" class="mt-2 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6">
                                    <option v-for="day in daysOfWeek" :key="day.id" :value="day.id">{{ day.name }}</option>
                                </select>
                                <p v-if="form.errors.cutoff_1_day" class="mt-2 text-sm text-red-600">{{ form.errors.cutoff_1_day }}</p>
                            </div>
                            <div class="sm:col-span-3">
                                <label for="cutoff_1_time" class="block text-sm font-medium leading-6 text-gray-900">Time</label>
                                <input type="time" id="cutoff_1_time" v-model="form.cutoff_1_time" class="mt-2 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6">
                                <p v-if="form.errors.cutoff_1_time" class="mt-2 text-sm text-red-600">{{ form.errors.cutoff_1_time }}</p>
                            </div>
                            <div class="sm:col-span-6">
                                <label class="block text-sm font-medium leading-6 text-gray-900">Days Covered</label>
                                <fieldset class="mt-2">
                                    <div class="space-y-2 sm:flex sm:items-center sm:space-x-4 sm:space-y-0">
                                        <div v-for="day in daysOfWeek" :key="day.id" class="flex items-center">
                                            <input :id="`day_1_${day.id}`" :value="day.id" v-model="form.days_covered_1" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600">
                                            <label :for="`day_1_${day.id}`" class="ml-2 block text-sm text-gray-900">{{ day.name }}</label>
                                        </div>
                                    </div>
                                    <p v-if="form.errors.days_covered_1" class="mt-2 text-sm text-red-600">{{ form.errors.days_covered_1 }}</p>
                                </fieldset>
                            </div>
                        </div>
                    </div>

                    <!-- Cutoff 2 -->
                    <div class="border-t border-gray-200 pt-8">
                        <h2 class="text-base font-semibold text-gray-900">Cutoff 2 (Optional)</h2>
                        <div class="mt-6 grid grid-cols-1 gap-x-6 gap-y-8 sm:grid-cols-6">
                            <div class="sm:col-span-3">
                                <label for="cutoff_2_day" class="block text-sm font-medium leading-6 text-gray-900">Day</label>
                                <select id="cutoff_2_day" v-model="form.cutoff_2_day" class="mt-2 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6">
                                    <option :value="null">N/A</option>
                                    <option v-for="day in daysOfWeek" :key="day.id" :value="day.id">{{ day.name }}</option>
                                </select>
                                <p v-if="form.errors.cutoff_2_day" class="mt-2 text-sm text-red-600">{{ form.errors.cutoff_2_day }}</p>
                            </div>
                            <div class="sm:col-span-3">
                                <label for="cutoff_2_time" class="block text-sm font-medium leading-6 text-gray-900">Time</label>
                                <input type="time" id="cutoff_2_time" v-model="form.cutoff_2_time" class="mt-2 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6">
                                <p v-if="form.errors.cutoff_2_time" class="mt-2 text-sm text-red-600">{{ form.errors.cutoff_2_time }}</p>
                            </div>
                            <div class="sm:col-span-6">
                                <label class="block text-sm font-medium leading-6 text-gray-900">Days Covered</label>
                                <fieldset class="mt-2">
                                    <div class="space-y-2 sm:flex sm:items-center sm:space-x-4 sm:space-y-0">
                                        <div v-for="day in daysOfWeek" :key="day.id" class="flex items-center">
                                            <input :id="`day_2_${day.id}`" :value="day.id" v-model="form.days_covered_2" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600">
                                            <label :for="`day_2_${day.id}`" class="ml-2 block text-sm text-gray-900">{{ day.name }}</label>
                                        </div>
                                    </div>
                                    <p v-if="form.errors.days_covered_2" class="mt-2 text-sm text-red-600">{{ form.errors.days_covered_2 }}</p>
                                </fieldset>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="border-t border-gray-200 pt-8 flex items-center justify-between">
                        <Link
                            :href="route('orders-cutoff.index')"
                            class="inline-flex items-center justify-center px-6 py-2 border border-gray-300 text-base font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            Cancel
                        </Link>
                        <button
                            @click="showConfirmDialog = true"
                            type="button"
                            :disabled="form.processing"
                            class="inline-flex items-center justify-center px-6 py-2 border border-transparent text-base font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                        >
                            Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Layout>
</template>