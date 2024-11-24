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

const store = () => {
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
    data: {
        type: Object,
        required: true,
    },
});

const { search } = useSearch("categories.index");

const editCategoryDetails = (id) => {
    targetId.value = id;
    isEditModalVisible.value = true;
    const data = props.data.data.find((item) => item.id === id);
    form.name = data.name;
    form.remarks = data.remarks;
};
</script>

<template>
    <Layout heading="Suppliers">
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
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="data in data.data">
                        <TD>{{ data.id }}</TD>
                        <TD>{{ data.name }}</TD>
                        <TD>{{ data.supplier_code }}</TD>
                        <TD>
                            <Button
                                @click="editCategoryDetails(data.id)"
                                class="text-blue-500"
                                variant="link"
                            >
                                <Pencil class="size-6" />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

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
                        <Button @click="store" class="gap-2">
                            Save Changes
                            <span><Loading v-if="isLoading" /></span>
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
