<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import { router } from "@inertiajs/vue3";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import { ref, computed } from "vue";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";

// Extend dayjs with plugins
dayjs.extend(utc);
dayjs.extend(timezone);
dayjs.tz.setDefault("Asia/Manila");

const { backButton } = useBackButton(route("mass-orders.index"));

const getStatusClass = (status) => {
    switch (status?.toLowerCase()) {
        case "approved":
        case "received":
            return "bg-green-100 text-green-800 border-green-200";
        case "pending":
            return "bg-yellow-100 text-yellow-800 border-yellow-200";
        case "committed":
            return "bg-blue-100 text-blue-800 border-blue-200";
        case "rejected":
        case "cancelled":
            return "bg-red-100 text-red-800 border-red-200";
        default:
            return "bg-gray-100 text-gray-800 border-gray-200";
    }
};

const isReceived = (status) => {
    return ['approved', 'received'].includes(status?.toLowerCase());
};

const shouldDisplayCommittedQuantity = (status) => {
    const lowerStatus = status?.toLowerCase();
    return lowerStatus === 'committed' || lowerStatus === 'approved' || lowerStatus === 'received';
};

const props = defineProps({
    order: {
        type: Object,
    },
    orderedItems: {
        type: Array,
    },
    receiveDatesHistory: {
        type: Array,
        required: true,
    },
    images: {
        type: Object,
        required: true,
    },
    canViewCost: {
        type: Boolean,
        default: false,
    }
});

// Computed property to get committed users information
const committedUsersInfo = computed(() => {
    if (!props.orderedItems || typeof props.orderedItems !== 'object') {
        return {
            hasCommittedItems: false,
            uniqueCommitters: [],
            formattedDisplay: 'N/a',
            totalCommittedItems: 0,
            totalItems: 0,
        };
    }

    const itemsArray = Array.isArray(props.orderedItems) ? props.orderedItems : Object.values(props.orderedItems);
    const totalItems = itemsArray.length;

    if (totalItems === 0) {
        return {
            hasCommittedItems: false,
            uniqueCommitters: [],
            formattedDisplay: 'N/a',
            totalCommittedItems: 0,
            totalItems: 0
        };
    }

    const committedItems = itemsArray.filter(item => item && (item.committed_by || item.committedBy));
    const totalCommittedItems = committedItems.length;

    if (totalCommittedItems === 0) {
        return {
            hasCommittedItems: false,
            uniqueCommitters: [],
            formattedDisplay: 'N/a',
            totalCommittedItems,
            totalItems
        };
    }

    const uniqueCommitters = committedItems.reduce((acc, item) => {
        const committer = item.committed_by || item.committedBy;
        if (committer && !acc.find(user => user.id === committer.id)) {
            acc.push({
                id: committer.id,
                name: `${committer.first_name} ${committer.last_name}`.trim()
            });
        }
        return acc;
    }, []);

    let formattedDisplay = 'N/a';

    if (totalCommittedItems > 0 && totalCommittedItems < totalItems) {
        if (uniqueCommitters.length > 0) {
            formattedDisplay = uniqueCommitters[0].name;
        }
    } else {
        if (uniqueCommitters.length === 1) {
            formattedDisplay = uniqueCommitters[0].name;
        } else if (uniqueCommitters.length === 2) {
            formattedDisplay = uniqueCommitters.map(u => u.name).join(', ');
        } else if (uniqueCommitters.length > 2) {
            formattedDisplay = `${uniqueCommitters[0].name} (+${uniqueCommitters.length - 1} more)`;
        }
    }

        return {

            hasCommittedItems: true,

            uniqueCommitters,

            formattedDisplay,

            totalCommittedItems,

            totalItems

        };

    });

    

    const shouldShowCommitterInfo = computed(() => {

    

        const lowerStatus = props.order.order_status?.toLowerCase();

    

        const isRelevantStatus = lowerStatus === 'committed' || lowerStatus === 'received';

    

        return committedUsersInfo.value.hasCommittedItems && isRelevantStatus;

    

    });

    

    const isViewModalVisible = ref(false);
const selectedItem = ref(null); // Initialize with null for clarity
const openViewModalForm = (id) => {
    const data = props.receiveDatesHistory;
    // Use find for direct access, safer than findIndex then direct access
    const history = data.find((item) => item.id === id);
    selectedItem.value = history;
    isViewModalVisible.value = true;
};
</script>

<template>
    <Layout heading="Order Details">
        <div class="space-y-6">
            <!-- Order Information Card -->
            <Card class="overflow-hidden bg-white shadow-sm border border-gray-200 rounded-xl">
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Order Info -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Order Info</h3>
                            <div class="space-y-3">
                                <div>
                                    <span class="text-xs text-gray-400 block">Order Number</span>
                                    <span class="font-medium text-gray-900">{{ order.order_number }}</span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-400 block">Order Date</span>
                                    <span class="font-medium text-gray-900">{{ order.order_date }}</span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-400 block">Variant</span>
                                    <span class="font-medium text-gray-900">{{ order.variant?.toUpperCase() }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Status Info -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Status</h3>
                            <div class="space-y-3">
                                <div>
                                    <span class="text-xs text-gray-400 block mb-1">Current Status</span>
                                    <span :class="['px-2.5 py-0.5 rounded-full text-xs font-medium border', getStatusClass(order.order_status)]">
                                        {{ order.order_status?.toUpperCase().replace("_", " ") }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-400 block">Approval Date</span>
                                    <span class="font-medium text-gray-900">{{ order.approval_action_date || 'N/A' }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Personnel -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Personnel</h3>
                            <div class="space-y-3">
                                <div>
                                    <span class="text-xs text-gray-400 block">Encoder</span>
                                    <div class="flex items-center gap-2">
                                        <div class="size-6 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 text-xs font-bold">
                                            {{ order.encoder?.first_name?.[0] }}
                                        </div>
                                        <span class="font-medium text-gray-900">{{ order.encoder?.first_name }} {{ order.encoder?.last_name }}</span>
                                    </div>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-400 block">Approver</span>
                                    <div class="flex items-center gap-2">
                                        <div v-if="order.approver" class="size-6 rounded-full bg-purple-100 flex items-center justify-center text-purple-600 text-xs font-bold">
                                            {{ order.approver?.first_name?.[0] }}
                                        </div>
                                        <span class="font-medium text-gray-900">
                                            {{ order.approver ? `${order.approver.first_name} ${order.approver.last_name}` : 'N/A' }}
                                        </span>
                                    </div>
                                </div>
                                <div v-if="shouldShowCommitterInfo">
                                    <span class="text-xs text-gray-400 block">Committer(s)</span>
                                    <span class="font-medium text-gray-900">{{ committedUsersInfo.formattedDisplay }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Summary -->
                        <div class="space-y-4 flex flex-col justify-between">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Summary</h3>
                            <div class="p-3 bg-gray-50 rounded-lg border border-gray-100">
                                <div class="flex justify-between items-center mb-1">
                                    <span class="text-xs text-gray-500">Items Ordered</span>
                                    <span class="font-bold text-gray-900">{{ orderedItems?.length || 0 }}</span>
                                </div>
                                <div class="flex justify-between items-center mb-1">
                                    <span class="text-xs text-gray-500">Delivery Receipts</span>
                                    <span class="font-bold text-gray-900">{{ order.delivery_receipts?.length || 0 }}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-xs text-gray-500">Receiving Records</span>
                                    <span class="font-bold text-gray-900">{{ receiveDatesHistory?.length || 0 }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Delivery Receipts -->
                <TableContainer>
                    <TableHeader>
                        <CardTitle class="text-lg font-semibold text-gray-800">Delivery Receipts</CardTitle>
                    </TableHeader>
                    <Table>
                        <TableHead>
                            <TH>Number</TH>
                            <TH>Remarks</TH>
                            <TH>Created</TH>
                        </TableHead>
                        <TableBody>
                            <tr v-if="order.delivery_receipts?.length === 0">
                                <td colspan="3" class="text-center py-6 text-gray-500 italic text-sm">No delivery receipts added yet.</td>
                            </tr>
                            <tr v-for="receipt in order.delivery_receipts" :key="receipt.id" class="hover:bg-gray-50 transition-colors">
                                <TD class="font-medium">{{ receipt.delivery_receipt_number }}</TD>
                                <TD class="text-gray-600 truncate max-w-[150px]">{{ receipt.remarks || '-' }}</TD>
                                <TD class="text-xs text-gray-500">
                                    {{ dayjs.utc(receipt.created_at).tz("Asia/Manila").format("MMM D, YYYY") }}
                                </TD>
                            </tr>
                        </TableBody>
                    </Table>
                    
                    <MobileTableContainer>
                        <MobileTableRow v-for="receipt in order.delivery_receipts" :key="receipt.id">
                            <MobileTableHeading :title="receipt.delivery_receipt_number" />
                            <div class="grid grid-cols-2 gap-2 text-sm mt-2">
                                <div class="flex flex-col">
                                    <span class="text-xs text-gray-500">Remarks</span>
                                    <span class="font-medium">{{ receipt.remarks || "N/A" }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-xs text-gray-500">Created</span>
                                    <span>{{ dayjs.utc(receipt.created_at).tz("Asia/Manila").format("MMM D, YYYY") }}</span>
                                </div>
                            </div>
                        </MobileTableRow>
                        <div v-if="order.delivery_receipts?.length === 0" class="p-4 text-center text-gray-500 italic">None</div>
                    </MobileTableContainer>
                </TableContainer>

                <!-- Image Attachments -->
                <Card class="bg-white shadow-sm border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                        <CardTitle class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            Image Attachments
                            <span class="px-2 py-0.5 rounded-full bg-gray-200 text-gray-600 text-xs">{{ images?.length || 0 }}</span>
                        </CardTitle>
                    </div>
                    <div class="p-6">
                        <div v-if="images?.length > 0" class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                            <div v-for="image in images" :key="image.id" class="group relative aspect-square bg-gray-100 rounded-lg border border-gray-200 overflow-hidden">
                                <a :href="image.image_url" target="_blank" rel="noopener noreferrer" class="block w-full h-full">
                                    <img :src="image.image_url" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105" />
                                </a>
                            </div>
                        </div>
                        <div v-else class="text-center py-8 bg-gray-50 rounded-lg border border-dashed border-gray-300">
                            <div class="flex flex-col items-center justify-center text-gray-400">
                                <svg class="size-10 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                <span class="text-sm">No images attached</span>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>





            <!-- Receiving History Table -->
            <TableContainer>
                <TableHeader>
                    <CardTitle class="text-lg font-semibold text-gray-800">Receiving History <span class="text-sm font-normal text-gray-500">({{ receiveDatesHistory?.length || 0 }} records)</span></CardTitle>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>#</TH>
                        <TH>Item Code</TH>
                        <TH>Name</TH>
                        <TH>UOM Details</TH>
                        <TH class="text-center">Ordered</TH>
                        <TH v-if="false" class="text-center">Approved</TH>
                        <TH class="text-center">Committed</TH>
                        <TH class="text-center">Received</TH>
                        <TH class="text-center">Variance (Ordered vs Committed)</TH>
                        <TH class="text-center">Variance (Committed vs Received)</TH>
                        <TH>Received At</TH>
                        <TH class="text-center">Status</TH>
                        <TH>Remarks</TH>
                        <TH class="text-right">Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-if="receiveDatesHistory?.length === 0">
                            <td colspan="14" class="text-center py-8 text-gray-500 italic bg-gray-50/50">
                                No receiving history available.
                            </td>
                        </tr>
                        <tr
                            v-for="(history, index) in receiveDatesHistory"
                            :key="history.id"
                            class="hover:bg-gray-50 transition-colors duration-150"
                        >
                            <TD class="text-center font-mono text-gray-500">{{ index + 1 }}</TD>
                            <TD class="font-mono text-xs text-gray-600">{{ history.item_code ?? 'N/a' }}</TD>
                            <TD class="font-medium text-gray-800">{{ history.item_name ?? 'N/a' }}</TD>
                            <TD>
                                <div class="flex flex-col text-xs">
                                    <span class="text-gray-500">Base: <span class="text-gray-900 font-medium">{{ history.BaseUOM || '-' }}</span></span>
                                    <span class="text-gray-500">Order: <span class="text-gray-900 font-medium">{{ history.uom || '-' }}</span></span>
                                </div>
                            </TD>
                            <TD class="text-center font-mono">{{ history.quantity_ordered || 0 }}</TD>
                            <TD v-if="false" class="text-center font-mono">{{ history.quantity_approved || 0 }}</TD>
                            <TD class="text-center font-mono">{{ history.quantity_commited || 0 }}</TD>
                            <TD class="text-center font-mono font-bold text-blue-600">{{ history.quantity_received }}</TD>
                            <TD class="text-center font-mono">{{ Math.abs(history.variance_ordered_committed || 0) }}</TD>
                            <TD class="text-center font-mono">{{ Math.abs(history.variance_committed_received || 0) }}</TD>
                            <TD class="text-sm text-gray-600">
                                {{ dayjs(history.received_date).isValid() ? dayjs(history.received_date).tz("Asia/Manila").format("MMM D, YYYY h:mm A") : '' }}
                            </TD>
                            <TD class="text-center">
                                <span :class="[
                                    'px-2.5 py-0.5 text-xs font-semibold rounded-full border',
                                    getStatusClass(history.status)
                                ]">
                                    {{ history.status?.toLowerCase() === 'approved' ? 'RECEIVED' : history.status?.toUpperCase() }}
                                </span>
                            </TD>
                            <TD class="max-w-[200px] truncate text-sm text-gray-600" :title="history.remarks">{{ history.remarks || '-' }}</TD>
                            <TD>
                                <div class="flex justify-end gap-2">
                                    <ShowButton
                                        @click="openViewModalForm(history.id)"
                                        title="View Details"
                                    />
                                </div>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>

                <MobileTableContainer>
                    <MobileTableRow
                        v-for="history in receiveDatesHistory"
                        :key="history.id"
                    >
                        <MobileTableHeading
                            :title="`${history.item_name ?? 'N/a'}`"
                        >
                            <div class="flex gap-1">
                                <ShowButton
                                    class="size-8"
                                    @click="openViewModalForm(history.id)"
                                />
                            </div>
                        </MobileTableHeading>
                        <div class="grid grid-cols-2 gap-2 mt-2 text-sm">
                            <div class="flex flex-col">
                                <span class="text-xs text-gray-500">Item Code</span>
                                <span class="font-mono text-xs">{{ history.item_code ?? 'N/a' }}</span>
                            </div>
                            <div class="flex flex-col">
                                <span class="text-xs text-gray-500">Received</span>
                                <span class="font-bold">{{ history.quantity_received }}</span>
                            </div>
                            <div class="flex flex-col items-end">
                                <span class="text-xs text-gray-500 mb-1">Status</span>
                                <span :class="['px-2 py-0.5 rounded text-xs font-bold border', getStatusClass(history.status)]">
                                    {{ history.status?.toLowerCase() === 'approved' ? 'RECEIVED' : history.status?.toUpperCase() }}
                                </span>
                            </div>
                            <div class="col-span-2 flex flex-col" v-if="history.remarks">
                                <span class="text-xs text-gray-500">Remarks</span>
                                <span class="italic text-gray-600">{{ history.remarks }}</span>
                            </div>
                        </div>
                    </MobileTableRow>
                    <div v-if="receiveDatesHistory?.length < 1" class="p-4 text-center text-gray-500 italic">None</div>
                </MobileTableContainer>
            </TableContainer>
        </div>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>

        <Dialog v-model:open="isViewModalVisible">
            <DialogContent class="sm:max-w-[700px]">
                <DialogHeader>
                    <DialogTitle class="text-xl font-bold text-gray-900">Receiving Details</DialogTitle>
                    <DialogDescription class="text-gray-600">
                        View detailed information about this receiving record
                    </DialogDescription>
                </DialogHeader>
                <div v-if="selectedItem" class="space-y-6">
                    <!-- Item Information -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3">Item Information</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <span class="text-xs text-gray-500 block">Item Code</span>
                                <span class="font-medium text-gray-900">{{ selectedItem.item_code ?? 'N/a' }}</span>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500 block">Item Name</span>
                                <span class="font-medium text-gray-900">{{ selectedItem.item_name ?? 'N/a' }}</span>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500 block">Base UOM</span>
                                <span class="font-medium text-gray-900">{{ selectedItem.BaseUOM ?? 'N/a' }}</span>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500 block">Order UOM</span>
                                <span class="font-medium text-gray-900">{{ selectedItem.uom ?? 'N/a' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Quantities -->
                    <div class="bg-blue-50 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3">Quantities</h4>
                        <div class="grid grid-cols-4 gap-4">
                            <div class="text-center">
                                <span class="text-xs text-gray-500 block">Ordered</span>
                                <span class="font-bold text-lg text-gray-900">{{ selectedItem.quantity_ordered || 0 }}</span>
                            </div>
                            <div class="text-center">
                                <span class="text-xs text-gray-500 block">Approved</span>
                                <span class="font-bold text-lg text-gray-900">{{ selectedItem.quantity_approved || 0 }}</span>
                            </div>
                            <div class="text-center">
                                <span class="text-xs text-gray-500 block">Committed</span>
                                <span class="font-bold text-lg text-gray-900">{{ selectedItem.quantity_commited || 0 }}</span>
                            </div>
                            <div class="text-center">
                                <span class="text-xs text-gray-500 block">Received</span>
                                <span class="font-bold text-lg text-blue-600">{{ selectedItem.quantity_received }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Receiving Details -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <span class="text-xs text-gray-500 block">Received By</span>
                            <div class="flex items-center gap-2 mt-1">
                                <div class="size-6 rounded-full bg-green-100 flex items-center justify-center text-green-600 text-xs font-bold">
                                    {{ selectedItem.received_by_first_name?.[0] }}
                                </div>
                                <span class="font-medium text-gray-900">
                                    {{ selectedItem.received_by_first_name }} {{ selectedItem.received_by_last_name }}
                                </span>
                            </div>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 block">Received At</span>
                            <span class="font-medium text-gray-900">{{ dayjs(selectedItem.received_date).format('MMM D, YYYY h:mm A') }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 block">Expiry Date</span>
                            <span class="font-medium text-gray-900">{{ selectedItem.expiry_date || 'N/a' }}</span>
                        </div>
                                                    <div>
                                                        <span class="text-xs text-gray-500 block">Status</span>
                                                        <span :class="['px-2.5 py-0.5 rounded-full text-xs font-medium border inline-block mt-1', getStatusClass(selectedItem.status)]">
                                                            {{ selectedItem.status?.toLowerCase() === 'approved' ? 'RECEIVED' : selectedItem.status?.toUpperCase() || 'N/a' }}
                                                        </span>
                                                    </div>                        <div class="col-span-2" v-if="selectedItem.remarks">
                            <span class="text-xs text-gray-500 block">Remarks</span>
                            <span class="font-medium text-gray-900">{{ selectedItem.remarks }}</span>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

    </Layout>
</template>
