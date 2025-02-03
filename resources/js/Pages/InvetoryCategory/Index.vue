<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { useSearch } from "@/Composables/useSearch";
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
    form.post(route("inventory-categories.update", targetId.value), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Category Details Updated Successfully.",
                life: 5000,
            });
            form.reset();
            isEditModalVisible.value = false;
        },
    });
};

const props = defineProps({
    categories: {
        type: Object,
        required: true,
    },
});

const { search } = useSearch("inventory-categories.index");

const editCategoryDetails = (id) => {
    targetId.value = id;
    isEditModalVisible.value = true;
    const data = props.categories.data.find((item) => item.id === id);
    form.name = data.name;
    form.remarks = data.remarks;
};

import { useReferenceStore } from "@/Composables/useReferenceStore";
const { isCreateModalVisible, openCreateModal, store } = useReferenceStore();

watch(isEditModalVisible, (value) => {
    if (!value) {
        form.reset();
        form.clearErrors();
    }
});

const { deleteModel } = useReferenceDelete();
const exportRoute = computed(() => 
    route("inventory-categories.export", {
        search: search.value,
    })
);
</script>

<template>
    <Layout
        heading="Inventory Categories"
        :hasButton="true"
        buttonName="Create New Category"
        :handleClick="openCreateModal"
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
                    <TH> Remarks</TH>
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="category in categories.data">
                        <TD>{{ category.id }}</TD>
                        <TD>{{ category.name }}</TD>
                        <TD>{{ category.remarks ?? "N/a" }}</TD>
                        <TD>
                            <EditButton
                                @click="editCategoryDetails(category.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route(
                                            'inventory-categories.destroy',
                                            category.id
                                        ),
                                        'Menu Category'
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="category in categories.data">
                    <MobileTableHeading :title="category.name">
                        <EditButton @click="editCategoryDetails(category.id)" />
                    </MobileTableHeading>
                    <LabelXS>Remarks: {{ category.remarks ?? "N/a" }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="categories" />
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
            title="Create Menu Category"
            :form="form"
            :isLoading="isLoading"
            :handleCreate="
                () =>
                    store(
                        route('inventory-categories.store'),
                        form,
                        'Inventory Category'
                    )
            "
        />
    </Layout>
</template>
