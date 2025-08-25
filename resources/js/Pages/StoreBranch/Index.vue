<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { useSearch } from "@/Composables/useSearch";
import { router } from "@inertiajs/vue3";
import { useReferenceDelete } from "@/Composables/useReferenceDelete";
import { ref, computed } from 'vue'; // Explicitly import ref and computed

const isEditModalVisible = ref(false);

const toast = useToast();
const isLoading = ref(false);

const form = useForm({
    name: null,
    remarks: null,
});

const targetId = ref(null);

const store = () => {
    form.post(route("branches.update", targetId.value), {
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

const { search } = useSearch("branches.index");

// Use named route for editCategoryDetails
const editCategoryDetails = (id) => {
    router.get(route("branches.edit", id)); // Already correct
};

// FIX: Use named route for viewDetails
const viewDetails = (id) => {
    router.get(route("branches.show", id)); // Changed to use the named route
};

// FIX: Use named route for createNewStoreBranch
const createNewStoreBranch = () => {
    router.get(route("branches.create")); // Changed to use the named route
};

const { deleteModel } = useReferenceDelete();

const exportRoute = computed(() =>
    route("branches.export", { search: search.value })
);
</script>

<template>
    <Layout
        heading="Store Branches"
        :hasButton="true"
        :handleClick="createNewStoreBranch"
        buttonName="Create New Store Branch"
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
                    <TH> Branch Code</TH>
                    <TH> Location Code</TH> <!-- Added Location Code -->
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="branch in data.data" :key="branch.id">
                        <TD>{{ branch.id }}</TD>
                        <TD>{{ branch.name }}</TD>
                        <TD>{{ branch.branch_code }}</TD>
                        <TD>{{ branch.location_code ?? "N/a" }}</TD> <!-- Added Location Code -->
                        <TD>
                            <ShowButton @click="viewDetails(branch.id)" />
                            <EditButton
                                @click="editCategoryDetails(branch.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route(
                                            'branches.destroy',
                                            branch.id
                                        ),
                                        'Branch'
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <MobileTableContainer>
                <MobileTableRow v-for="branch in data.data" :key="branch.id">
                    <MobileTableHeading :title="branch.name">
                        <ShowButton @click="viewDetails(branch.id)" />
                        <EditButton @click="editCategoryDetails(branch.id)" />
                    </MobileTableHeading>
                    <LabelXS>{{ branch.branch_code }}</LabelXS>
                    <LabelXS>{{ branch.location_code ?? "N/a" }}</LabelXS> <!-- Added Location Code for mobile -->
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
