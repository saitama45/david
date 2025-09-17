<script setup>
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import { ref, watch, onUnmounted } from 'vue';
import { useAuth } from "@/Composables/useAuth";
import { useToast } from '@/Components/ui/toast/use-toast';
import { Toaster } from '@/Components/ui/toast';

const props = defineProps({
    ordersCutoffs: Object,
    filters: Object,
});

const page = usePage();
const { toast } = useToast();

const unsubscribe = router.on('finish', () => {
    if (page.props.flash.success) {
        toast({
            title: 'Success!',
            description: page.props.flash.success,
        });
    }
});

onUnmounted(() => {
    unsubscribe();
});

const { hasAccess } = useAuth();

const search = ref(props.filters?.search || '');

watch(search, (value) => {
    router.get(
        route("orders-cutoff.index", { search: value }),
        {},
        {
            preserveState: true,
            replace: true,
        }
    );
});

const formatDays = (dayString) => {
    return dayString || 'N/A';
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
    <Head title="Orders Cutoff" />

    <Layout heading="Orders Cutoff">
        <Toaster />

        <div class="flex justify-end mb-4">
            <Link v-if="hasAccess('create orders cutoff')" :href="route('orders-cutoff.create')" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                Add New Cutoff
            </Link>
        </div>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        id="search"
                        type="text"
                        v-model="search"
                        placeholder="Search by template..."
                        class="pl-10"
                    />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Ordering Template</TH>
                    <TH>Cutoff 1</TH>
                    <TH>Days Covered 1</TH>
                    <TH>Cutoff 2</TH>
                    <TH>Days Covered 2</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="cutoff in ordersCutoffs.data" :key="cutoff.id">
                        <TD>{{ cutoff.ordering_template }}</TD>
                        <TD>{{ formatDay(cutoff.cutoff_1_day) }} at {{ formatTime(cutoff.cutoff_1_time) }}</TD>
                        <TD>{{ formatDays(cutoff.days_covered_1) }}</TD>
                        <TD>
                            {{ cutoff.cutoff_2_day ? `${formatDay(cutoff.cutoff_2_day)} at ${formatTime(cutoff.cutoff_2_time)}` : 'N/A' }}
                        </TD>
                        <TD>{{ formatDays(cutoff.days_covered_2) }}</TD>
                        <TD>
                            <div class="flex items-center gap-2">
                                <ShowButton :isLink="true" :href="route('orders-cutoff.show', cutoff.id)" />
                                <EditButton v-if="hasAccess('edit orders cutoff')" :isLink="true" :href="route('orders-cutoff.edit', cutoff.id)" />
                            </div>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="ordersCutoffs" />
        </TableContainer>
    </Layout>
</template>