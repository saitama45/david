<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import { router } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { ref, computed } from "vue"; // Ensure ref and computed are imported
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter, // <-- Add this
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog"; // Add these imports

const { backButton } = useBackButton(route("mass-orders.index"));
const statusBadgeColor = (status) => {
    // Add this check for null or undefined status
    if (!status) {
        return "bg-gray-400 text-white"; // Or choose another appropriate default class
    }
    switch (status.toUpperCase()) {
        case "APPROVED":
            return "bg-green-500 text-white";
        case "RECEIVED":
            return "bg-green-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        case "COMMITED":
            return "bg-blue-500 text-white";
        case "REJECTED":
            return "bg-red-400 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
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

    const committedItems = itemsArray.filter(item => item && item.committed_by);
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
        const committer = item.committed_by;
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

const copyOrderAndCreateAnother = (id) => {
    router.get(route("mass-orders.create"), { orderId: id });
};

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
    <Layout
        heading="Order Details"
        :hasButton="true"
        buttonName="Copy Order and Create Another"
        :handleClick="() => copyOrderAndCreateAnother(order.id)"
    >
        <DivFlexCol class="gap-3">
            <Card class="p-5 grid sm:grid-cols-4 gap-5">
                <InputContainer>
                    <LabelXS>Encoder: </LabelXS>
                    <SpanBold
                        >{{ order.encoder?.first_name }}
                        {{ order.encoder?.last_name }}</SpanBold
                    >
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Number: </LabelXS>
                    <SpanBold>{{ order.order_number }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Date: </LabelXS>
                    <SpanBold>{{ order.order_date }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Approver: </LabelXS>
                    <SpanBold v-if="order.approver"
                        >{{ order.approver?.first_name }}
                        {{ order.approver?.last_name }}</SpanBold
                    >
                    <SpanBold v-if="!order.approver">N/a</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Variant: </LabelXS>
                    <SpanBold>{{ order.variant?.toUpperCase() }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Approval Action Date: </LabelXS>
                    <SpanBold>{{
                        order.approval_action_date
                            ? order.approval_action_date
                            : "N/a"
                    }}</SpanBold>
                </InputContainer>

                <InputContainer v-if="committedUsersInfo">
                    <LabelXS>Committer(s): </LabelXS>
                    <SpanBold>{{ committedUsersInfo.formattedDisplay }}</SpanBold>
                </InputContainer>

                <InputContainer>
                    <LabelXS>Order Status: </LabelXS>
                    <Badge
                        class="w-fit"
                        :class="statusBadgeColor(order.order_status)"
                        >{{
                            order.order_status?.toUpperCase().replace("_", " ")
                        }}</Badge
                    >
                </InputContainer>
            </Card>

            <TableContainer>
                <TableHeader>
                    <SpanBold class="sm:text-normal text-xs"
                        >Ordered Items</SpanBold
                    >
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH> Item Code </TH>
                        <TH> Name </TH>
                        <TH>Base UOM</TH>
                        <TH>UOM</TH>
                        <TH> Ordered</TH>
                        <TH> Comitted</TH>
                        <TH> Delivered</TH>
                        <TH> Received</TH>
                        <TH>
                            <DivFlexCol>
                                Variance
                                <LabelXS>(Ordered vs Committed)</LabelXS>
                            </DivFlexCol>
                        </TH>
                        <TH>
                            <DivFlexCol>
                                Variance
                                <LabelXS>(Committed vs Received)</LabelXS>
                            </DivFlexCol>
                        </TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="orderItem in orderedItems" :key="orderItem.id">
                            <TD>{{ orderItem.supplier_item?.ItemCode ?? 'N/a' }}</TD>
                            <TD>{{ orderItem.supplier_item?.item_name ?? 'N/a' }}</TD>
                            <TD>{{ orderItem.supplier_item?.sap_master_file?.BaseUOM ?? 'N/a' }}</TD>
                            <TD>{{ orderItem.uom ?? 'N/a' }}</TD>
                            <TD>{{ orderItem.quantity_ordered }}</TD>
                            <TD>{{ order.order_status?.toUpperCase() === 'APPROVED' ? 0 : orderItem.quantity_commited }}</TD>
                            <TD>{{ orderItem.quantity_received }}</TD>
                            <TD>{{ orderItem.quantity_received }}</TD>
                            <TD>{{ Math.abs(orderItem.quantity_approved - (order.order_status?.toUpperCase() === 'APPROVED' ? 0 : orderItem.quantity_commited)) }}</TD>
                            <TD>{{ Math.abs((order.order_status?.toUpperCase() === 'APPROVED' ? 0 : orderItem.quantity_commited) - orderItem.quantity_received) }}</TD>
                        </tr>
                    </TableBody>
                </Table>

                <MobileTableContainer>
                    <MobileTableRow
                        v-for="orderItem in orderedItems"
                        :key="orderItem.id"
                    >
                        <MobileTableHeading
                            :title="`${orderItem.supplier_item?.item_name ?? 'N/a'} (${orderItem.supplier_item?.ItemCode ?? 'N/a'})`"
                        >
                        </MobileTableHeading>
                        <LabelXS>Base UOM: {{ orderItem.supplier_item?.sap_master_file?.BaseUOM ?? 'N/a' }}</LabelXS>
                        <LabelXS>UOM: {{ orderItem.uom ?? 'N/a' }}</LabelXS>
                        <LabelXS>Ordered: {{ orderItem.quantity_ordered }}</LabelXS>
                        <LabelXS
                            >Committed: {{ order.order_status?.toUpperCase() === 'APPROVED' ? 0 : orderItem.quantity_commited }}</LabelXS
                        >
                        <LabelXS
                            >Received: {{ orderItem.quantity_received }}</LabelXS
                        >
                    </MobileTableRow>
                </MobileTableContainer>
            </TableContainer>

            <TableContainer>
                <TableHeader>
                    <SpanBold class="sm:text-normal text-xs"
                        >Delivery Receipts</SpanBold
                    >
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>Id</TH>
                        <TH>Number</TH>
                        <TH>Remarks</TH>
                        <TH>Created at</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="receipt in order.delivery_receipts" :key="receipt.id">
                            <TD>{{ receipt.id }}</TD>
                            <TD>{{ receipt.delivery_receipt_number }}</TD>
                            <TD>{{ receipt.remarks }}</TD>
                            <TD>{{ receipt.created_at }}</TD>
                        </tr>
                    </TableBody>
                </Table>

                <MobileTableContainer>
                    <MobileTableRow
                        v-for="receipt in order.delivery_receipts"
                        :key="receipt.id"
                    >
                        <MobileTableHeading
                            :title="`${receipt.delivery_receipt_number}`"
                        >
                        </MobileTableHeading>
                        <LabelXS>Remarks: {{ receipt.remarks ?? "N/a" }}</LabelXS>
                        <LabelXS>Created at: {{ receipt.created_at }}</LabelXS>
                    </MobileTableRow>
                    <SpanBold v-if="order.delivery_receipts.length < 1"
                        >None</SpanBold
                    >
                </MobileTableContainer>
            </TableContainer>



            <Card class="p-5">
                <InputContainer class="col-span-4">
                    <LabelXS>Image Attachments: </LabelXS>
                    <DivFlexCenter
                        class="gap-4 overflow-auto overflow-x-auto scrollbar-thin scrollbar-track-gray-100 scrollbar-thumb-gray-300 hover:scrollbar-thumb-gray-400"
                    >
                        <div
                            v-for="image in images"
                            :key="image.id"
                            class="relative"
                        >
                            <a :href="image.image_url" target="_blank" rel="noopener noreferrer">
                                <img
                                    :src="image.image_url"
                                    class="size-24 min-w-24 cursor-pointer hover:opacity-80 transition-opacity"
                                />
                            </a>
                        </div>
                    </DivFlexCenter>
                    <SpanBold v-if="images.length < 1">None</SpanBold>
                </InputContainer>
            </Card>

            <TableContainer>
                <CardTitle class="sm:text-normal text-xs"
                    >Receive Dates History</CardTitle
                >
                <Table>
                    <TableHead>
                        <TH> Id </TH>
                        <TH> Item </TH>
                        <TH> Item Code </TH>
                        <TH> Quantity Received</TH>
                        <TH> Received At</TH>
                        <TH> Status</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr
                            v-for="history in receiveDatesHistory"
                            :key="history.id"
                        >
                            <TD>{{ history.id }}</TD>
                            <TD>{{ history.store_order_item?.supplier_item?.item_name ?? 'N/a' }}</TD>
                            <TD>{{ history.store_order_item?.supplier_item?.ItemCode ?? 'N/a' }}</TD>
                            <TD>{{ history.quantity_received }}</TD>
                            <TD>{{
                                dayjs(history.received_date).format(
                                    "MMMM D, YYYY h:mm A"
                                )
                            }}</TD>
                            <TD>{{ history.status }}</TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <ShowButton
                                        @click="openViewModalForm(history.id)"
                                    />
                                </DivFlexCenter>
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
                            :title="`${history.store_order_item?.supplier_item?.item_name ?? 'N/a'} (${history.store_order_item?.supplier_item?.ItemCode ?? 'N/a'})`"
                        >
                        </MobileTableHeading>
                        <LabelXS>UOM: {{ history.store_order_item?.supplier_item?.uom ?? 'N/a' }}</LabelXS>
                        <LabelXS
                            >Received: {{ history.quantity_received }}</LabelXS
                        >
                        <LabelXS
                            >Status: {{ history.status?.toUpperCase() }}</LabelXS
                        >
                        <SpanBold v-if="receiveDatesHistory.length < 1"
                            >None</SpanBold
                        >
                    </MobileTableRow>
                </MobileTableContainer>
            </TableContainer>
        </DivFlexCol>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>

        <Dialog v-model:open="isViewModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Received Item Details</DialogTitle>
                </DialogHeader>
                <section v-if="selectedItem" class="grid sm:grid-cols-2 gap-5">
                    <InputContainer>
                        <LabelXS>Item Code</LabelXS>
                        <SpanBold>{{ selectedItem.store_order_item?.supplier_item?.ItemCode ?? 'N/a' }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Item Name</LabelXS>
                        <SpanBold>{{ selectedItem.store_order_item?.supplier_item?.item_name ?? 'N/a' }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>UOM</LabelXS>
                        <SpanBold>{{ selectedItem.store_order_item?.supplier_item?.uom ?? 'N/a' }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Received By</LabelXS>
                        <SpanBold
                            >{{ selectedItem.received_by_user?.first_name }}
                            {{ selectedItem.received_by_user?.last_name }}</SpanBold
                        >
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Quantity Received</LabelXS>
                        <SpanBold>{{
                            selectedItem.quantity_received
                        }}</SpanBold>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Received At</LabelXS>
                        <SpanBold>{{ selectedItem.received_date }}</SpanBold>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Expiry Date</LabelXS>
                        <SpanBold>{{ selectedItem.expiry_date ?? 'N/a' }}</SpanBold>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Status</LabelXS>
                        <SpanBold>{{ selectedItem.status ?? 'N/a' }}</SpanBold>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Remarks</LabelXS>
                        <SpanBold>{{ selectedItem.remarks ?? "N/a" }}</SpanBold>
                    </InputContainer>
                </section>
            </DialogContent>
        </Dialog>

    </Layout>
</template>
