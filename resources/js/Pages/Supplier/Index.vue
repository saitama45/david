<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { useSearch } from "@/Composables/useSearch";
import { router } from "@inertiajs/vue3";
import { useReferenceDelete } from "@/Composables/useReferenceDelete";
import { ref, computed } from 'vue'; // Ensure ref and computed are imported if not already

const isEditModalVisible = ref(false);

const toast = useToast();
const isLoading = ref(false);

const form = useForm({
    name: null,
    remarks: null,
    // Note: is_active is not part of this modal's form, as it's for name/remarks only.
    // The actual edit for is_active happens on the dedicated Edit.vue page.
});

const targetId = ref(null);

const update = () => {
    form.post(route("suppliers.update", targetId.value), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Supplier Details Updated Successfully.",
                life: 5000,
            });
            form.reset();
            isEditModalVisible.value = false;
        },
        onError: (errors) => {
            let errorMessage = "An error occurred while trying to update the supplier details.";
            if (Object.keys(errors).length > 0) {
                errorMessage = Object.values(errors).join(', ');
            }
            toast.add({
                severity: "error",
                summary: "Error",
                detail: errorMessage,
                life: 5000,
            });
        }
    });
};

const props = defineProps({
    data: {
        type: Object,
        required: true,
    },
});

const { search } = useSearch("suppliers.index");

const editCategoryDetails = (id) => {
    targetId.value = id;
    isEditModalVisible.value = true;
    const data = props.data.data.find((item) => item.id === id);
    form.name = data.name;
    form.remarks = data.remarks;
    // No need to set form.is_active here as this modal doesn't handle it
};

const createNewSupplier = () => {
    router.visit(route("suppliers.create"));
};

const { deleteModel } = useReferenceDelete();

const exportRoute = computed(() =>
    route("suppliers.export", { search: search.value })
);
</script>

<template>
    <Layout
        heading="Suppliers"
        :hasButton="true"
        buttonName="Create New Supplier"
        :handleClick="createNewSupplier"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
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
                    <TH> Supplier Code</TH>
                    <TH> Remarks</TH>
                    <TH> Status</TH> <!-- New Table Header for Status -->
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="data in data.data" :key="data.id">
                        <TD>{{ data.id }}</TD>
                        <TD>{{ data.name }}</TD>
                        <TD>{{ data.supplier_code }}</TD>
                        <TD>{{ data.remarks ?? "N/a" }}</TD>
                        <TD>
                            <!-- Display "Active" if is_active is 1, "Inactive" otherwise -->
                            {{ Number(data.is_active) === 1 ? 'Active' : 'Inactive' }}
                        </TD>
                        <TD>
                            <DivFlexCenter>
                                <EditButton
                                    :isLink="true"
                                    :href="route('suppliers.edit', data.id)"
                                />
                                <DeleteButton
                                    @click="
                                        deleteModel(
                                            route('suppliers.destroy', data.id),
                                            'Supplier'
                                        )
                                    "
                                />
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="data in data.data" :key="data.id">
                    <MobileTableHeading :title="data.name">
                        <EditButton
                            :isLink="true"
                            class="size-5"
                            :href="route('suppliers.edit', data.id)"
                        />
                    </MobileTableHeading>
                    <LabelXS>Supplier Code: {{ data.supplier_code }}</LabelXS>
                    <!-- Display Status for Mobile View -->
                    <LabelXS>Status: {{ Number(data.is_active) === 1 ? 'Active' : 'Inactive' }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="data" />
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
    </Layout>
</template>
