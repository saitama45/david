<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { useSearch } from "@/Composables/useSearch";
import { router } from "@inertiajs/vue3";

const isEditModalVisible = ref(false);

const toast = useToast();
const isLoading = ref(false);

const form = useForm({
    name: null,
    remarks: null,
});

const targetId = ref(null);

const store = () => {
    form.post(route("store-branches.update", targetId.value), {
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

const { search } = useSearch("store-branches.index");

const editCategoryDetails = (id) => {
    router.get(`/store-branches/edit/${id}`);
};

const viewDetails = (id) => {
    router.get(`/store-branches/show/${id}`);
};

const createNewStoreBranch = () => {
    router.get("/store-branches/create");
};
</script>

<template>
    <Layout
        heading="Store Branches"
        :hasButton="true"
        :handleClick="createNewStoreBranch"
        buttonName="Create New Store Branch"
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
                    <TH> Branch Code</TH>
                    <TH> Brand Name</TH>
                    <TH> Brand Code</TH>
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="branch in data.data">
                        <TD>{{ branch.id }}</TD>
                        <TD>{{ branch.name }}</TD>
                        <TD>{{ branch.branch_code }}</TD>
                        <TD>{{ branch.brand_name ?? "N/a" }}</TD>
                        <TD>{{ branch.brand_code ?? "N/a" }}</TD>
                        <TD>
                            <button
                                @click="editCategoryDetails(branch.id)"
                                class="text-blue-500"
                            >
                                <Pencil class="size-5" />
                            </button>
                            <button @click="viewDetails(branch.id)">
                                <ShowButton />
                            </button>
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
