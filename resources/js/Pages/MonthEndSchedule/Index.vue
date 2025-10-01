<script setup>
import { useForm, Head, router } from '@inertiajs/vue3';
import { useConfirm } from "primevue/useconfirm";
import { CalendarCheck2, Trash2 } from 'lucide-vue-next';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    schedules: { type: Object, required: true },
    // branches: { type: Object, required: true }, // No longer needed
});

const confirm = useConfirm();

const form = useForm({
    year: new Date().getFullYear(), // Default to current year
});

const submit = () => {
    form.post(route('month-end-schedules.store'), {
        onSuccess: () => form.reset(),
    });
};

const deleteSchedule = (scheduleId) => {
    confirm.require({
        message: 'Are you sure you want to delete this schedule entry? This will only delete this specific month\'s entry.',
        header: 'Confirmation',
        icon: 'pi pi-exclamation-triangle',
        accept: () => {
            router.delete(route('month-end-schedules.destroy', scheduleId), {
                preserveScroll: true,
            });
        },
    });
};

const formatDisplayDate = (dateString) => {
    if (!dateString) return 'N/A';
    // Create a Date object from the ISO string. This will be in UTC.
    const date = new Date(dateString);

    // Format to Manila timezone (UTC+8)
    const options = {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZone: 'Asia/Manila' // Explicitly set timezone
    };
    return date.toLocaleDateString('en-US', options);
};

const getMonthName = (monthNumber) => {
    const date = new Date();
    date.setMonth(monthNumber - 1); // Month is 0-indexed
    return date.toLocaleString('en-US', { month: 'long' });
};

</script>

<template>
    <Head title="Month End Schedules" />

    <Layout heading="Month End Schedules">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- Create Schedule Form -->
            <div class="md:col-span-1">
                <div class="bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-lg font-semibold border-b pb-2 mb-4">Generate Schedules for Year</h2>
                    <form @submit.prevent="submit">
                        <div class="space-y-4">
                            <div>
                                <Label for="year">Year</Label>
                                <Input
                                    id="year"
                                    type="number"
                                    v-model="form.year"
                                    min="2000"
                                    max="2099"
                                    class="w-full"
                                />
                                <InputError :message="form.errors.year" />
                            </div>
                            <div class="flex justify-end">
                                <Button type="submit" :disabled="form.processing">Generate Schedules</Button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Schedules List -->
            <div class="md:col-span-2">
                <TableContainer>
                    <Table>
                        <TableHead>
                            <TH>ID</TH>
                            <TH>Year</TH>
                            <TH>Month</TH>
                            <TH>Calculated Date</TH>
                            <TH>Status</TH>
                            <TH>Created By</TH>
                            <TH>Actions</TH>
                        </TableHead>
                        <TableBody>
                            <tr v-if="!schedules.data.length">
                                <td colspan="7" class="text-center py-4">No schedules found.</td>
                            </tr>
                            <tr v-for="schedule in schedules.data" :key="schedule.id">
                                <TD>{{ schedule.id }}</TD>
                                <TD>{{ schedule.year }}</TD>
                                <TD>{{ getMonthName(schedule.month) }}</TD>
                                <TD>{{ formatDisplayDate(schedule.calculated_date) }}</TD>
                                <TD>
                                    <Badge class="capitalize" :class="{
                                        'bg-yellow-500 text-white': schedule.status === 'pending',
                                        'bg-blue-500 text-white': schedule.status === 'processing',
                                        'bg-green-500 text-white': schedule.status === 'completed',
                                        'bg-teal-500 text-white': schedule.status === 'level1_approved',
                                    }">{{ schedule.status }}</Badge>
                                </TD>
                                <TD>{{ schedule.creator ? `${schedule.creator.first_name} ${schedule.creator.last_name}` : 'N/A' }}</TD>
                                <TD>
                                    <Button @click="deleteSchedule(schedule.id)" variant="destructive" size="icon">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </TD>
                            </tr>
                        </TableBody>
                    </Table>
                    <Pagination :data="schedules" />
                </TableContainer>
            </div>
        </div>
    </Layout>
</template>