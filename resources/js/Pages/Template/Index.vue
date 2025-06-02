<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
const toast = useToast();
const isUpdateModalOpen = ref(false);

const form = useForm({
    file: null,
    file_name: null,
});
const isLoading = ref(false);
const openUpdateModal = (file_name) => {
    isUpdateModalOpen.value = true;
    form.file_name = file_name;
};

const updateTemplate = () => {
    isLoading.value = true;
    form.post(route("templates.store"), {
        onSuccess: () => {
            isUpdateModalOpen.value = false;
            form.reset();
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Updated Successfully.",
                life: 3000,
            });
        },
        onError: (e) => {
            toast.add({
                severity: "error",
                summary: "Success",
                detail: "An error occured while trying to updated the template.",
                life: 3000,
            });
        },
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

const files = [
    {
        template: "GSI ORDER",
        name: "GSI ORDER TEMPLATE",
        file_name: "gsi_order_template.xlsx",
    },
    {
        template: "GSI OT ORDER",
        name: "GSI OT ORDER TEMPLATE",
        file_name: "gsi_ot_order_template.xlsx",
    },
    {
        template: "PUL ORDER",
        name: "PUL ORDER TEMPLATE",
        file_name: "pul_order_template.xlsx",
    },
    {
        template: "FRUITS AND VEGGIES (SOUTH) ORDER",
        name: "FRUITS AND VEGGIES (SOUTH) ORDER TEMPLATE",
        file_name: "fruits-and-vegetables-south-template.xlsx",
    },
    {
        template: "FRUITS AND VEGGIES (MM) ORDER",
        name: "FRUITS AND VEGGIES (MM) ORDER TEMPLATE",
        file_name: "fruits-and-vegetables-mm-template.xlsx",
    },
    {
        template: "WIP",
        name: "WIP TEMPLATE",
        file_name: "wip_list_template.xlsx",
    },
    {
        template: "WIP INGREDIENTS TEMPLATE",
        name: "WIP INGREDIENTS TEMPLATE",
        file_name: "wip_ingredients_template.xlsx",
    },
    {
        template: "BOM",
        name: "BOM TEMPLATE",
        file_name: "bom_list_template.xlsx",
    },
    {
        template: "BOM INGREDIENTS TEMPLATE",
        name: "BOM INGREDIENTS TEMPLATE",
        file_name: "bom_ingredients_template.xlsx",
    }
];
</script>

<template>
    <Layout heading="Templates">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Template</TH>
                    <TH>Name</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="(file, index) in files" :key="index">
                        <TD>{{ file.template }}</TD>
                        <TD>{{ file.name }}</TD>
                        <TD>
                            <button
                                @click="() => openUpdateModal(file.file_name)"
                            >
                                <Pencil class="size-5" />
                            </button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>

        <Dialog v-model:open="isUpdateModalOpen">
            <DialogContent class="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Update Template</DialogTitle>
                    <DialogDescription>
                        Input all important fields.
                    </DialogDescription>
                </DialogHeader>

                <InputContainer>
                    <LabelXS>New File</LabelXS>
                    <Input
                        @input="form.file = $event.target.files[0]"
                        type="file"
                    />
                    <FormError>{{ form.errors.file }}</FormError>
                </InputContainer>

                <DialogFooter>
                    <Button :disabled="isLoading" @click="updateTemplate">{{
                        isLoading ? "Updating..." : "Update"
                    }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
