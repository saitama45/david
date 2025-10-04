<script setup>
import { Head, router } from "@inertiajs/vue3";

const props = defineProps({
    variant: {
        type: String,
        default: null
    },
    date_from: {
        type: String,
        default: null
    },
    date_to: {
        type: String,
        default: null
    }
});

const goBack = () => {
    router.get(route('dts-mass-orders.index'));
};

const formatDisplayDate = (dateString) => {
    if (!dateString) return 'Not Selected';
    try {
        const [year, month, day] = dateString.split('-');
        const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
        const monthName = monthNames[parseInt(month, 10) - 1];
        return `${monthName} ${parseInt(day, 10)}, ${year}`;
    } catch (e) {
        return dateString;
    }
};

</script>

<template>
    <Head title="Create DTS Mass Order" />

    <Layout :heading="`Create DTS Mass Order - ${props.variant || 'No Variant Selected'}`">
        <TableContainer>
            <TableHeader>
                <Button @click="goBack" variant="outline">
                    Back to DTS Mass Orders
                </Button>
            </TableHeader>

            <div class="bg-white border rounded-md shadow-sm p-8">
                <div class="text-center space-y-4">
                    <h3 class="text-xl font-semibold text-gray-700">Create DTS Mass Order</h3>
                    <div class="space-y-2">
                        <p class="text-lg font-medium text-blue-600">Variant: {{ props.variant || 'Not Selected' }}</p>
                        <p class="text-md font-medium text-gray-700">
                            Date Range: {{ formatDisplayDate(props.date_from) }} - {{ formatDisplayDate(props.date_to) }}
                        </p>
                    </div>
                    <p class="text-gray-600 mt-4">This feature is under development.</p>
                </div>
            </div>
        </TableContainer>
    </Layout>
</template>
