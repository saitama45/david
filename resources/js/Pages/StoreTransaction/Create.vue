<script setup>
import { useForm } from "@inertiajs/vue3";
const excelFileForm = useForm({
    store_transactions_file: null,
});
const isLoading = ref(false);
const isImportStoreTransactionModalOpen = ref(true);
const openImportStoreTransactionModal = () => {
    isImportStoreTransactionModalOpen.value = true;
};

watch(isImportStoreTransactionModalOpen, (value) => {
    if (!value) {
        isLoading.value = false;
    }
});
const importTransactions = () => {
    isLoading.value = true;
    excelFileForm.post(route("store-transactions.import"), {
        onSuccess: () => {
            isLoading.value = false;
            isImportStoreTransactionModalOpen.value = false;
        },
        onError: () => {
            isLoading.value = false;
        },
    });
};
</script>
<template>
    <Layout
        heading="Create Store Transactions"
        buttonName="Import Store Transactions"
        :hasButton="true"
        :handleClick="openImportStoreTransactionModal"
    >
        <section class="grid grid-cols-3 gap-3"></section>

        <Dialog v-model:open="isImportStoreTransactionModalOpen">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Import Store Transactions List</DialogTitle>
                    <DialogDescription>
                        Import the excel file here.
                    </DialogDescription>
                </DialogHeader>

                <InputContainer>
                    <LabelXS> Store Transactions List </LabelXS>
                    <Input
                        :disabled="isLoading"
                        type="file"
                        @input="
                            excelFileForm.store_transactions_file =
                                $event.target.files[0]
                        "
                    />
                    <FormError>{{
                        excelFileForm.errors.store_transactions_file
                    }}</FormError>
                </InputContainer>

                <DialogFooter>
                    <Button
                        :disabled="isLoading"
                        @click="importTransactions"
                        class="gap-2"
                    >
                        Proceed
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
