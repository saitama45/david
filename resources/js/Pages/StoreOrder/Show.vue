<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import { router } from "@inertiajs/vue3";
import dayjs from "dayjs";

const { backButton } = useBackButton(route("store-orders.index"));
const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "APPROVED":
            return "bg-green-500 text-white";
        case "RECEIVED":
            return "bg-green-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        case "INCOMPLETE":
            return "bg-orange-500 text-white";
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
        type: Object,
    },
    receiveDatesHistory: {
        type: Object,
        required: true,
    },
    images: {
        type: Object,
        required: true,
    },
});

const copyOrderAndCreateAnother = (id) => {
    router.get("/store-orders/create", { orderId: id });
};

const isViewModalVisible = ref(false);
const selectedItem = ref();
const openViewModalForm = (id) => {
    const data = props.receiveDatesHistory;
    const existingItemIndex = data.findIndex((history) => history.id === id);
    const history = data[existingItemIndex];
    selectedItem.value = history;
    isViewModalVisible.value = true;
};

const selectedImage = ref(null);
const isEnlargedImageVisible = ref(false);

const enlargeImage = (image) => {
    selectedImage.value = image;
    isEnlargedImageVisible.value = true;
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
            <Card class="p-5 grid grid-cols-4 gap-5">
                <InputContainer>
                    <LabelXS>Encoder: </LabelXS>
                    <SpanBold
                        >{{ order.encoder.first_name }}
                        {{ order.encoder.last_name }}</SpanBold
                    >
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Number: </LabelXS>
                    <SpanBold>{{ order.order_number }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Date: </LabelXS>
                    <SpanBold>{{
                        dayjs(order.order_date).format("MMMM d, YYYY")
                    }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Request Status: </LabelXS>
                    <Badge
                        class="w-fit"
                        :class="statusBadgeColor(order.order_request_status)"
                        >{{ order.order_request_status.toUpperCase() }}</Badge
                    >
                </InputContainer>
                <InputContainer>
                    <LabelXS>Approver: </LabelXS>
                    <SpanBold v-if="order.approver"
                        >{{ order.approver.first_name }}
                        {{ order.approver.last_name }}</SpanBold
                    >
                    <SpanBold v-if="!order.approver">N/a</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Receiving Status: </LabelXS>
                    <Badge
                        class="w-fit"
                        :class="statusBadgeColor(order.order_status)"
                        >{{
                            order.order_status.toUpperCase().replace("_", " ")
                        }}</Badge
                    >
                    <SpanBold>{{}}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Variant: </LabelXS>
                    <SpanBold>{{ order.variant.toUpperCase() }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Approval Action Date: </LabelXS>
                    <SpanBold>{{
                        order.approval_action_date
                            ? dayjs(order.approval_action_date).format(
                                  "MMMM d, YYYY"
                              )
                            : "N/a"
                    }}</SpanBold>
                </InputContainer>
            </Card>

            <TableContainer class="h-fit">
                <!-- <DivFlexCenter class="justify-end">
                    <Button
                        class="bg-blue-500 hover:bg-blue-300"
                        @click="copyOrderAndCreateAnother(order.id)"
                    >
                        Copy Order and Create Another
                    </Button>
                </DivFlexCenter> -->

                <!-- <TableHeader>
                    <SearchBar>
                        <Input class="pl-10" placeholder="Search..." />
                    </SearchBar>
                </TableHeader> -->
                <TableHeader>
                    <SpanBold>Ordered Items</SpanBold>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH> Item Code </TH>
                        <TH> Name </TH>
                        <TH> Unit </TH>
                        <TH> Ordered</TH>
                        <TH> Approved</TH>
                        <TH> Received</TH>
                        <TH> Approval Rate</TH>
                        <TH> Total Cost </TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="order in orderedItems" :key="order.id">
                            <TD>{{
                                order.product_inventory.inventory_code
                            }}</TD>
                            <TD>{{ order.product_inventory.name }}</TD>
                            <TD>{{
                                order.product_inventory.unit_of_measurement.name
                            }}</TD>
                            <TD>{{ order.quantity_ordered }}</TD>
                            <TD>{{ order.quantity_approved }}</TD>
                            <TD>{{ order.quantity_received }}</TD>
                            <TD
                                >{{
                                    parseFloat(
                                        (order.quantity_approved /
                                            order.quantity_ordered) *
                                            100
                                    ).toFixed(0, 2)
                                }}%</TD
                            >
                            <TD>{{ order.total_cost }}</TD>
                        </tr>
                    </TableBody>
                </Table>
            </TableContainer>

            <TableContainer>
                <TableHeader>
                    <SpanBold>Delivery Receipts</SpanBold>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>Id</TH>
                        <TH>Number</TH>
                        <TH>Remarks</TH>
                        <TH>Created at</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="receipt in order.delivery_receipts">
                            <TD>{{ receipt.id }}</TD>
                            <TD>{{ receipt.delivery_receipt_number }}</TD>
                            <TD>{{ receipt.remarks }}</TD>
                            <TD>{{
                                dayjs(receipt.created_at).format("MMMM D, YYYY")
                            }}</TD>
                        </tr>
                    </TableBody>
                </Table>
            </TableContainer>

            <TableContainer>
                <TableHeader>
                    <SpanBold>Remarks</SpanBold>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>Id</TH>
                        <TH>Remarks By</TH>
                        <TH>Action</TH>
                        <TH>Remarks</TH>
                        <TH>Created At</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="remarks in order.store_order_remarks">
                            <TD>{{ remarks.id }}</TD>
                            <TD
                                >{{ remarks.user.first_name }}
                                {{ remarks.user.last_name }}</TD
                            >
                            <TD>
                                {{ remarks.action.toUpperCase() }}
                            </TD>
                            <TD>{{ remarks.remarks }}</TD>
                            <TD>{{
                                dayjs(remarks.created_at).format("MMMM D, YYYY")
                            }}</TD>
                        </tr>
                    </TableBody>
                </Table>
            </TableContainer>

            <Card class="p-5">
                <InputContainer class="col-span-4">
                    <LabelXS>Image Attachments: </LabelXS>
                    <DivFlexCenter class="gap-4">
                        <div
                            v-for="image in images"
                            :key="image.id"
                            class="relative"
                        >
                            <img
                                :src="image.image_url"
                                class="size-24 cursor-pointer hover:opacity-80 transition-opacity"
                                @click="enlargeImage(image)"
                            />
                        </div>
                    </DivFlexCenter>
                    <SpanBold v-if="images.length < 1">None</SpanBold>
                </InputContainer>
            </Card>

            <TableContainer>
                <CardTitle>Receive Dates History</CardTitle>
                <Table>
                    <TableHead>
                        <TH> Id </TH>
                        <TH> Item </TH>
                        <TH> Item Code </TH>
                        <!-- <TH> Received By </TH> -->
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
                            <TD>{{
                                history.store_order_item.product_inventory.name
                            }}</TD>
                            <TD>{{
                                history.store_order_item.product_inventory
                                    .inventory_code
                            }}</TD>
                            <!-- <TD>
                                {{ history.receiver.first_name }}
                                {{ history.receiver.last_name }}
                            </TD> -->
                            <TD>{{ history.quantity_received }}</TD>
                            <TD>{{
                                dayjs(history.received_date).format(
                                    "MMMM D, YYYY"
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
            </TableContainer>
        </DivFlexCol>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>

        <!-- View Modal -->
        <Dialog v-model:open="isViewModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Received Item Details</DialogTitle>
                </DialogHeader>
                <section class="grid grid-cols-2 gap-5">
                    <InputContainer>
                        <LabelXS>Item</LabelXS>
                        <SpanBold
                            >{{
                                selectedItem.store_order_item.product_inventory
                                    .name
                            }}
                            ({{
                                selectedItem.store_order_item.product_inventory
                                    .inventory_code
                            }})</SpanBold
                        >
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Received By</LabelXS>
                        <SpanBold
                            >{{ selectedItem.receiver.first_name }}
                            {{ selectedItem.receiver.last_name }}</SpanBold
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
                        <SpanBold>{{ selectedItem.expiry_date }}</SpanBold>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Is approved?</LabelXS>
                        <SpanBold>{{
                            selectedItem.is_approved ? "Yes" : "No"
                        }}</SpanBold>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Remarks</LabelXS>
                        <SpanBold>{{ selectedItem.remarks ?? "N/a" }}</SpanBold>
                    </InputContainer>
                </section>
            </DialogContent>
        </Dialog>

        <!-- Image Viewer -->
        <Dialog v-model:open="isEnlargedImageVisible">
            <DialogContent
                class="sm:max-w-[90vw] h-[90vh] p-0 flex items-center justify-center"
            >
                <button
                    @click="isEnlargedImageVisible = false"
                    class="absolute right-4 top-4 rounded-sm ring-offset-background transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 bg-white/80 p-2"
                ></button>
                <img
                    v-if="selectedImage"
                    :src="selectedImage.image_url"
                    class="max-h-full max-w-full object-contain"
                    alt="Enlarged image"
                />
            </DialogContent>
        </Dialog>
    </Layout>
</template>
