<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
const toast = useToast();
const isUpdateModalOpen = ref(false);

const form = useForm({
    file: null,
});
const openUpdateModal = () => {
    isUpdateModalOpen.value = true;
};

const updateTemplate = () => {
    form.post(route("templates.store"), {
        onSuccess: () => {
            console.log("success");
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
    });
};
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
                    <tr>
                        <TD>GSI ORDER</TD>
                        <TD>GSI ORDER TEMPLATE </TD>
                        <TD>
                            <button @click="openUpdateModal">
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
                    <Button @click="updateTemplate">Update</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
