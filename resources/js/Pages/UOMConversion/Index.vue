<script setup>
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";

const toast = useToast();

const confirm = useConfirm();
const isUpdateModalOpen = ref(true);
const importForm = useForm({
    file: null,
});

const importData = () => {
    importForm.post(route("uom-conversions.import"), {
        forceFormData: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Data Imported Successfully.",
                life: 5000,
            });
            importForm.reset();
            isUpdateModalOpen.value = false;
        },
    });
};
</script>

<template>
    <Layout
        heading="Unit of Measurements Conversion"
        :hasButton="true"
        buttonName="Import Data"
    >
    </Layout>

    <Dialog v-model:open="isUpdateModalOpen">
        <DialogContent class="sm:max-w-[425px]">
            <DialogHeader>
                <DialogTitle>Import Data</DialogTitle>
                <DialogDescription>
                    Input all important fields.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-5">
                <div class="flex flex-col space-y-1">
                    <Input
                        type="file"
                        @input="importForm.file = $event.target.files[0]"
                    />
                    <FormError>{{ importForm.errors.file }}</FormError>
                </div>
            </div>

            <DialogFooter>
                <Button @click="importData" type="submit" class="gap-2">
                    Proceed
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
