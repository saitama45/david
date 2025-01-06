<script setup>
import { useBackButton } from "@/Composables/useBackButton";

import { useForm } from "@inertiajs/vue3";
const { backButton } = useBackButton(route("receiving-approvals.index"));

import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { X } from "lucide-vue-next";
const toast = useToast();
const confirm = useConfirm();

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
    items: {
        type: Object,
        required: true,
    },
    images: {
        type: Object,
        required: true,
    },
});

const selectedItems = ref([]);

const approveReceivedItemForm = useForm({
    id: null,
});
const approveAllItems = () => {
    approveReceivedItemForm.id = [];
    approveReceivedItemForm.id = props.items.map((item) => item.id);
    confirm.require({
        message: "Are you sure you want to approve all the items status?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "success",
        },
        accept: () => {
            approveReceivedItemForm.post(
                route("receiving-approvals.approve-received-item"),
                {
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Received Items Status Approved Successfully.",
                            life: 3000,
                        });
                    },
                }
            );
        },
    });
};

const approveSeletedItems = () => {
    approveReceivedItemForm.id = selectedItems.value;
    confirm.require({
        message: "Are you sure you want to approve the selected items status?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "success",
        },
        accept: () => {
            approveReceivedItemForm.post(
                route("receiving-approvals.approve-received-item"),
                {
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Received Item Status Approved Successfully.",
                            life: 3000,
                        });
                    },
                }
            );
        },
    });
};

const approveReceivedItem = (id) => {
    approveReceivedItemForm.id = id;
    confirm.require({
        message: "Are you sure you want to approve this received item status?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "success",
        },
        accept: () => {
            approveReceivedItemForm.post(
                route("receiving-approvals.approve-received-item"),
                {
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Received Item Status Approved Successfully.",
                            life: 3000,
                        });
                    },
                }
            );
        },
    });
};

const selectedImage = ref(null);
const isEnlargedImageVisible = ref(false);

const enlargeImage = (image) => {
    selectedImage.value = image;
    isEnlargedImageVisible.value = true;
};

const deleteImageForm = useForm({
    id: null,
});

const deleteImage = () => {
    confirm.require({
        message: "Are you sure you want to delete this image?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Remove",
            severity: "danger",
        },
        accept: () => {
            deleteImageForm.post(route("destroy"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Image deletd successfully.",
                        life: 5000,
                    });
                    isLoading.value = false;
                },
                onError: (err) => {
                    isLoading.value = false;
                    console.log(err);
                },
            });
        },
    });
};
</script>

<template>
    <Layout :heading="`Order Number ${order.order_number}`">
        <TableContainer v-if="items.length > 0">
            <TableHeader class="justify-between">
                <Button
                    v-if="selectedItems.length > 0"
                    @click="approveSeletedItems"
                    variant="outline"
                    >Approve Selected Items</Button
                >
                <Button class="bg-green-500" @click="approveAllItems"
                    >Approve All</Button
                >
            </TableHeader>
            <Table>
                <TableHead>
                    <TH> </TH>
                    <TH>Item</TH>
                    <TH>Inventory Code</TH>
                    <TH>Received Date</TH>
                    <TH>Quantity Received</TH>
                    <TH>Is Approved?</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items" :key="item.id">
                        <TD>
                            <Checkbox
                                v-model="selectedItems"
                                :value="item.id"
                                :inputId="`item-${item.id}`"
                            />
                        </TD>
                        <TD>{{
                            item.store_order_item.product_inventory.name
                        }}</TD>
                        <TD>{{
                            item.store_order_item.product_inventory
                                .inventory_code
                        }}</TD>
                        <TD>{{ item.received_date }}</TD>
                        <TD>{{ item.quantity_received }}</TD>
                        <TD>{{ item.is_approved == 1 ? "Yes" : "No" }}</TD>
                        <TD>
                            <Button
                                @click="approveReceivedItem(item.id)"
                                variant="link"
                                class="text-green-500 p-0"
                            >
                                Approve
                            </Button>
                        </TD>
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
                        <button
                            @click="
                                deleteImageForm.id = image.id;
                                deleteImage();
                            "
                            class="absolute -right-2 -top-2 text-white size-5 rounded-full bg-red-500"
                        >
                            <X class="size-5" />
                        </button>
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

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>

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
