<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { useSearch } from "@/Composables/useSearch";

const isEditModalVisible = ref(false);

const toast = useToast();
const isLoading = ref(false);

const form = useForm({
    name: null,
    remarks: null,
});

const targetId = ref(null);

const update = () => {
    form.post(route("categories.update", targetId.value), {
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

const { search } = useSearch("categories.index");

const editCategoryDetails = (id) => {
    console.log(id);
    targetId.value = id;
    isEditModalVisible.value = true;
    const data = props.categories.data.find((item) => item.id === id);
    form.name = data.name;
    form.remarks = data.remarks;
};

import { useReferenceStore } from "@/Composables/useReferenceStore";
const { isCreateModalVisible, openCreateModal, store } = useReferenceStore();
</script>

<template>
    <Layout
        heading="Product Categories"
        :hasButton="true"
        :handleClick="openCreateModal"
        buttonName="Create New Category"
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
                            <button @click="editCategoryDetails(category.id)">
                                <Pencil class="size-5" />
                            </button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <Pagination :data="categories" />
        </TableContainer>

        <!-- Edit Modal -->
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
        <Dialog v-model:open="isCreateModalVisible">
            <DialogContent class="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Create Product Category</DialogTitle>
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
                        <Button
                            @click="
                                store(
                                    route('categories.store'),
                                    form,
                                    'Category'
                                )
                            "
                            class="gap-2"
                        >
                            Create
                            <span v-if="isLoading"><Loading /></span>
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
