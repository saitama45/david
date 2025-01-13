<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { useSearch } from "@/Composables/useSearch";
import { router } from "@inertiajs/vue3";
import { useReferenceDelete } from "@/Composables/useReferenceDelete";

const isEditModalVisible = ref(false);

const toast = useToast();
const isLoading = ref(false);

const form = useForm({
    name: null,
    remarks: null,
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
};

const createNewSupplier = () => {
    router.visit(route("suppliers.create"));
};

const { deleteModel } = useReferenceDelete();
</script>

<template>
    <Layout
        heading="Suppliers"
        :hasButton="true"
        buttonName="Create New Supplier"
        :handleClick="createNewSupplier"
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
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="data in data.data">
                        <TD>{{ data.id }}</TD>
                        <TD>{{ data.name }}</TD>
                        <TD>{{ data.supplier_code }}</TD>
                        <TD>{{ data.remarks ?? "N/a" }}</TD>
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
                <MobileTableRow v-for="data in data.data">
                    <MobileTableHeading :title="data.name">
                        <EditButton
                            :isLink="true"
                            class="size-5"
                            :href="route('suppliers.edit', data.id)"
                        />
                    </MobileTableHeading>
                    <LabelXS>Supplier Code: {{ data.supplier_code }}</LabelXS>
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
