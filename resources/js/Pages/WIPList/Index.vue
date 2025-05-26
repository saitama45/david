<script setup>
import { router, useForm } from "@inertiajs/vue3";
import { useSearch } from "@/Composables/useSearch";
import { useToast } from "primevue/usetoast";
const toast = useToast();
const { search } = useSearch("wip-list.index");
const isImportWipModalOpen = ref(false);
const isImportWipIngredientsModalOpen = ref(false);
const props = defineProps({
    wips: {
        type: Object,
        required: true,
    },
});

const wipForm = useForm({
    file: null,
});
const isLoading = ref(false);

const openImportWipModal = () => {
    isImportWipModalOpen.value = true;
};
const openImportWipIngredientsModal = () => {
    isImportWipIngredientsModalOpen.value = true;
};

const importWipList = () => {
    console.log("list");
    isLoading.value = true;
    wipForm.post(route("wip-list.import-wip-list"), {
        onSuccess: () => {
            isImportWipModalOpen.value = false;
            wipForm.reset();

            toast.add({
                severity: "success",
                summary: "Success",
                detail: "New WIPs Successfully Created",
                life: 3000,
            });
        },
        onError: (e) => {
            console.log(e);
        },
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

const importWipIngredientsList = () => {
    isLoading.value = true;
    wipForm.post(route("wip-list.import-wip-ingredients"), {
        onSuccess: () => {
            isImportWipIngredientsModalOpen.value = false;
            wipForm.reset();

            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Updated Successfully Created",
                life: 3000,
            });
        },
        onError: (errors) => {
            console.log('Import errors:', errors);
            
            // Handle validation errors (array of error messages)
            if (errors.validation_errors && Array.isArray(errors.validation_errors)) {
                // Show each validation error as separate toast
                errors.validation_errors.forEach((error, index) => {
                    setTimeout(() => {
                        toast.add({
                            severity: "error",
                            summary: "Validation Error",
                            detail: error,
                            life: 6000,
                        });
                    }, index * 100); // Stagger the toasts slightly
                });
            }
            // Handle single error message
            else if (errors.message) {
                toast.add({
                    severity: "error",
                    summary: "Import Failed",
                    detail: errors.message,
                    life: 5000,
                });
            }
            // Handle generic file upload errors
            else if (errors.file) {
                toast.add({
                    severity: "error",
                    summary: "File Error",
                    detail: errors.file,
                    life: 5000,
                });
            }
            // Fallback for any other errors
            else {
                toast.add({
                    severity: "error",
                    summary: "Import Failed",
                    detail: errors.validation_errors,
                    life: 10000,
                });
            }
        },
        onFinish: () => {
            isLoading.value = false;
        },
    });
};
</script>

<template>
    <Layout heading="WIP List">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar>

                <DivFlexCenter class="gap-2">
                    <Button @click="openImportWipIngredientsModal"
                        >Update WIP Ingredients</Button
                    >
                    <Button @click="openImportWipModal">Update List</Button>
                </DivFlexCenter>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>SAP Code</TH>
                    <TH>Name</TH>
                    <TH>Remarks</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="wip in wips.data" :key="wip.id">
                        <TD>{{ wip.id }}</TD>
                        <TD>{{ wip.sap_code }}</TD>
                        <TD>{{ wip.name }}</TD>
                        <TD>{{ wip.remarks ?? "none" }}</TD>
                        <TD>
                            <ShowButton
                                :isLink="true"
                                :href="route('wip-list.show', wip.id)"
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="wips" />
        </TableContainer>
    </Layout>

    <Dialog v-model:open="isImportWipModalOpen">
        <DialogContent class="sm:max-w-[600px]">
            <DialogHeader>
                <DialogTitle>Import WIP List</DialogTitle>
                <DialogDescription>
                    Import the excel file here.
                </DialogDescription>
            </DialogHeader>

            <InputContainer>
                <LabelXS> List </LabelXS>
                <Input
                    :disabled="isLoading"
                    type="file"
                    @input="wipForm.file = $event.target.files[0]"
                />
                <FormError>{{ wipForm.errors.file }}</FormError>
            </InputContainer>

            <InputContainer>
                <ul>
                    <li class="text-xs">
                        Template:
                        <a
                            class="text-blue-500 underline"
                            href="/excel/wip-list-template"
                            >Click to download</a
                        >
                    </li>
                </ul>
            </InputContainer>

            <DialogFooter>
                <Button :disabled="isLoading" @click="importWipList">{{
                    isLoading ? "Proccessing" : "Upload"
                }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="isImportWipIngredientsModalOpen">
        <DialogContent class="sm:max-w-[600px]">
            <DialogHeader>
                <DialogTitle>Import WIP Ingredients List</DialogTitle>
                <DialogDescription>
                    Import the excel file here.
                </DialogDescription>
            </DialogHeader>

            <InputContainer>
                <LabelXS> List </LabelXS>
                <Input
                    :disabled="isLoading"
                    type="file"
                    @input="wipForm.file = $event.target.files[0]"
                />
                <FormError>{{ wipForm.errors.file }}</FormError>
            </InputContainer>

            <InputContainer>
                <ul>
                    <li class="text-xs">
                        Template:
                        <a
                            class="text-blue-500 underline"
                            href="/excel/wip-ingredients-template"
                            >Click to download</a
                        >
                    </li>
                </ul>
            </InputContainer>
``
            <DialogFooter>
                <Button
                    :disabled="isLoading"
                    @click="importWipIngredientsList"
                    >{{ isLoading ? "Proccessing" : "Upload" }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>