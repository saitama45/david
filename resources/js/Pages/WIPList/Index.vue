<script setup>
import { router, useForm } from "@inertiajs/vue3";
import { useSearch } from "@/Composables/useSearch";

const { search } = useSearch("wip-list.index");
const isImportWipModalOpen = ref(true);
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

const importWipList = () => {
    isLoading.value = true;
    wipForm.post(route("wip-list.import-wip-list"), {
        onSuccess: () => {
            isImportWipModalOpen.value = false;
            wipForm.reset();
        },
        onError: (e) => {
            console.log(e);
        },
        onFinish: () => {
            isLoading.value = false;
        },
    });
};
</script>

<template>
    <Layout heading="WIP List" :hasButton="true" buttonName="Upload WIP">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Name</TH>
                    <TH>Remarks</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="wip in wips.data" :key="wip.id">
                        <TD>{{ wip.id }}</TD>
                        <TD>{{ wip.name }}</TD>
                        <TD>{{ wip.remarks ?? "none" }}</TD>
                        <TD>
                            <ShowButton />
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
                <LabelXS> Menu List </LabelXS>
                <Input
                    :disabled="isLoading"
                    type="file"
                    @input="wipForm.file = $event.target.files[0]"
                />
                <FormError>{{ wipForm.errors.file }}</FormError>
            </InputContainer>

            <DialogFooter>
                <Button :disabled="isLoading" @click="importWipList">{{
                    isLoading ? "Proccessing" : "Upload"
                }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
