<script setup>
import { useSearch } from "@/Composables/useSearch";
import { router, useForm } from "@inertiajs/vue3";
const isImportBomModalOpen = ref(false);
const isImportBomIngredientsModalOpen = ref(false);
const bomForm = useForm({
    file: null,
});
const { search } = useSearch("menu-list.index");
const props = defineProps({
    menus: {
        type: Object,
        required: true,
    },
});

import { useToast } from "primevue/usetoast";
const toast = useToast();

const isLoading = ref(false);

const openImportBomModal = () => {
    isImportBomModalOpen.value = true;
};
const openImportBomIngredientsModal = () => {
    isImportBomIngredientsModalOpen.value = true;
};

const createNewMenu = () => {
    router.get(route("menu-list.create"));
};

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

import { useReferenceDelete } from "@/Composables/useReferenceDelete";
import { Button } from "@/Components/ui/button/index";
const { deleteModel } = useReferenceDelete();

const exportRoute = route("menu-list.export", {
    search: search.value,
});

const importBomList = () => {
    console.log("list");
    isLoading.value = true;
    bomForm.post(route("menu-list.import-bom-list"), {
        onSuccess: () => {
            isImportBomModalOpen.value = false;
            bomForm.reset();

            toast.add({
                severity: "success",
                summary: "Success",
                detail: "New BOMs Successfully Created",
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

const importBomIngredientsList = () => {
    isLoading.value = true;
    bomForm.post(route("menu-list.import-bom-ingredients"), {
        onSuccess: () => {
            isImportBomIngredientsModalOpen.value = false;
            bomForm.reset();

            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Updated Successfully Created",
                life: 3000,
            });
        },
        onError: (errors) => {
            console.log("Import errors:", errors);

            // Handle validation errors (array of error messages)
            if (
                errors.validation_errors &&
                Array.isArray(errors.validation_errors)
            ) {
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
    <Layout
        heading="BOM List"
        :hasButton="false"
        :handleClick="createNewMenu"
        buttonName="Create New BOM"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
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
                    <Button @click="openImportBomIngredientsModal"
                        >Update BOM Ingredients</Button
                    >
                    <Button @click="openImportBomModal">Update List</Button>
                </DivFlexCenter>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Product Id</TH>
                    <TH>Name</TH>
                    <TH>Ingredients Cost</TH>
                    <TH>Remarks</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="menu in menus.data" :key="menu.id">
                        <TD>{{ menu.id }}</TD>
                        <TD>{{ menu.product_id }}</TD>
                        <TD>{{ menu.name }}</TD>
                        <TD>{{ menu.total }}</TD>
                        <TD>{{ menu.remarks ?? "None" }}</TD>
                        <TD>
                            <DivFlexCenter class="gap-3">
                                <ShowButton
                                    v-if="hasAccess('view bom')"
                                    :isLink="true"
                                    :href="route('menu-list.show', menu.id)"
                                />
                                <EditButton
                                    v-if="hasAccess('edit bom')"
                                    :isLink="true"
                                    :href="route('menu-list.edit', menu.id)"
                                />
                                <DeleteButton
                                    @click="
                                        deleteModel(
                                            route('menu-list.destroy', menu.id),
                                            'Menu'
                                        )
                                    "
                                />
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="menu in menus.data" :key="menu.id">
                    <MobileTableHeading :title="`${menu.name}`">
                        <ShowButton
                            v-if="hasAccess('view menu')"
                            :isLink="true"
                            :href="route('menu-list.show', menu.id)"
                        />
                        <EditButton
                            v-if="hasAccess('edit menu')"
                            :isLink="true"
                            :href="route('menu-list.edit', menu.id)"
                        />
                        <DeleteButton
                            @click="
                                deleteModel(
                                    route('menu-list.destroy', menu.id),
                                    'Menu'
                                )
                            "
                        />
                    </MobileTableHeading>
                    <LabelXS>Category: {{ menu.category }}</LabelXS>
                    <LabelXS>Price: {{ menu.price }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="menus" />
        </TableContainer>
    </Layout>

    <Dialog v-model:open="isImportBomModalOpen">
        <DialogContent class="sm:max-w-[600px]">
            <DialogHeader>
                <DialogTitle>Import BOM List</DialogTitle>
                <DialogDescription>
                    Import the excel file here.
                </DialogDescription>
            </DialogHeader>

            <InputContainer>
                <LabelXS> List </LabelXS>
                <Input
                    :disabled="isLoading"
                    type="file"
                    @input="bomForm.file = $event.target.files[0]"
                />
                <FormError>{{ bomForm.errors.file }}</FormError>
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
                <Button :disabled="isLoading" @click="importBomList">{{
                    isLoading ? "Proccessing" : "Upload"
                }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="isImportBomIngredientsModalOpen">
        <DialogContent class="sm:max-w-[600px]">
            <DialogHeader>
                <DialogTitle>Import BOM Ingredients List</DialogTitle>
                <DialogDescription>
                    Import the excel file here.
                </DialogDescription>
            </DialogHeader>

            <InputContainer>
                <LabelXS> List </LabelXS>
                <Input
                    :disabled="isLoading"
                    type="file"
                    @input="bomForm.file = $event.target.files[0]"
                />
                <FormError>{{ bomForm.errors.file }}</FormError>
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
                    @click="importBomIngredientsList"
                    >{{ isLoading ? "Proccessing" : "Upload" }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
