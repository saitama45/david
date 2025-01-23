<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
const confirm = useConfirm();

import { useSearch } from "@/Composables/useSearch";

import { useToast } from "primevue/usetoast";

const isEditModalVisible = ref(false);

const toast = useToast();
const isLoading = ref(false);

const form = useForm({
    name: null,
    remarks: null,
});

const targetId = ref(null);

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
});

const { search } = useSearch("unit-of-measurements.index");
const update = () => {
    form.post(route("unit-of-measurements.update", targetId.value), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "UOM Details Updated Successfully.",
                life: 5000,
            });
            form.reset();
            isEditModalVisible.value = false;
        },
    });
};
const editCategoryDetails = (id) => {
    targetId.value = id;
    isEditModalVisible.value = true;
    const data = props.items.data.find((item) => item.id === id);
    form.name = data.name;
    form.remarks = data.remarks;
};

const deleteModel = (id) => {
    confirm.require({
        message: "Are you sure you want to delete this uom?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "danger",
        },
        accept: () => {
            router.delete(route("unit-of-measurements.destroy", id), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "UOM Deleted Successfully.",
                        life: 5000,
                    });
                },
                onError: (errors) => {
                    console.log(errors);
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail:
                            errors.message ||
                            "An error occurred while deleting the uom.",
                        life: 5000,
                    });
                },
            });
        },
    });
};

import { useReferenceStore } from "@/Composables/useReferenceStore";
const { isCreateModalVisible, openCreateModal, store } = useReferenceStore();
</script>

<template>
    <Layout
        heading="Unit of Measurements"
        :hasButton="true"
        :handleClick="openCreateModal"
        buttonName="Create New UOM"
    >
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        v-model="search"
                        placeholder="Search..."
                    />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH> Id </TH>
                    <TH> Name</TH>
                    <TH> Remarks</TH>
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.id }}</TD>
                        <TD>{{ item.name }}</TD>
                        <TD>{{ item.remarks ?? "N/a" }}</TD>
                        <TD>
                            <EditButton @click="editCategoryDetails(item.id)" />
                            <DeleteButton @click="deleteModel(item.id)" />
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <Pagination :data="items" />
        </TableContainer>

        <Dialog v-model:open="isEditModalVisible">
            <DialogContent class="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Edit Category Details</DialogTitle>
                    <DialogDescription>
                        Input all important fields.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-5">
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs">Name</Label>
                        <Input v-model="form.name" />
                        <FormError>{{ form.errors.name }}</FormError>
                    </div>
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs">Remarks</Label>
                        <Textarea v-model="form.remarks" />
                        <FormError>{{ form.errors.remarks }}</FormError>
                    </div>
                    <div class="flex justify-end">
                        <Button @click="update" class="gap-2">
                            Save Changes
                            <span><Loading v-if="isLoading" /></span>
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Create Modal -->
        <CreateReferenceModal
            v-model:isCreateModalVisible="isCreateModalVisible"
            title="Create UOM"
            :form="form"
            :isLoading="isLoading"
            :handleCreate="
                () =>
                    store(
                        route('unit-of-measurements.store'),
                        form,
                        'Unit of Measurement'
                    )
            "
        />
    </Layout>
</template>
