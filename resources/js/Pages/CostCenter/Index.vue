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
    form.post(route("cost-centers.update", targetId.value), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Cost Center Details Updated Successfully.",
                life: 5000,
            });
            form.reset();
            isEditModalVisible.value = false;
        },
    });
};

const props = defineProps({
    costCenters: {
        type: Object,
        required: true,
    },
});

const { search } = useSearch("cost-centers.index");

const editCategoryDetails = (id) => {
    targetId.value = id;
    isEditModalVisible.value = true;
    const data = props.costCenters.data.find((item) => item.id === id);
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
const exportRoute = computed(() => {
    route("cost-centers.export", { search: search.value });
});
const { deleteModel } = useReferenceDelete();
</script>

<template>
    <Layout
        heading="Cost Centers"
        :hasButton="true"
        :handleClick="openCreateModal"
        buttonName="Create New Cost Center"
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
                    <tr v-for="costCenter in costCenters.data">
                        <TD>{{ costCenter.id }}</TD>
                        <TD>{{ costCenter.name }}</TD>
                        <TD>{{ costCenter.remarks ?? "N/a" }}</TD>
                        <TD>
                            <EditButton
                                @click="editCategoryDetails(costCenter.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route(
                                            'cost-centers.destroy',
                                            costCenter.id
                                        ),
                                        'Cost Center'
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="costCenter in costCenters.data">
                    <MobileTableHeading :title="costCenter.name">
                        <EditButton
                            @click="editCategoryDetails(costCenter.id)"
                        />
                    </MobileTableHeading>
                    <LabelXS
                        >Remarks: {{ costCenter.remarks ?? "N/a" }}</LabelXS
                    >
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="costCenters" />
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
            title="Create Cost Center"
            :form="form"
            :isLoading="isLoading"
            :handleCreate="
                () => store(route('cost-centers.store'), form, 'Cost Center')
            "
        />
    </Layout>
</template>
