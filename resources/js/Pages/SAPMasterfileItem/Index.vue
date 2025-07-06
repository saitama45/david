<script setup>

import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { useSearch } from "@/Composables/useSearch";
import { router } from "@inertiajs/vue3";
import { useReferenceDelete } from "@/Composables/useReferenceDelete";

const isEditModalVisible = ref(false);
const toast = useToast();
const isLoading = ref(false);


const form = useForm({
    name: null,
    remarks: null,
});

const targetId = ref(null);

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
});

const { deleteModel } = useReferenceDelete();

const { search } = useSearch("sapitems-list.index");

const editCategoryDetails = (id) => {
    router.get(`/sapitems-list/edit/${id}`);
};

const viewDetails = (id) => {
    router.get(`/sapitems-list/show/${id}`);
};

const createNewItem = () => {
    router.get("/sapitems-list/create");
};

const exportRoute = computed(() => 
    route("sapitems-list.export", { search: search.value })
);




</script>

<template>
    <Layout
        heading="SAP Masterfile Items List"
        :hasButton="true"
        :handleClick="createNewItem"
        buttonName="Create New Item"
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
                    <TH>Id</TH>
                    <TH>Item Number</TH>
                    <TH>Description</TH>
                    <TH>Base UOM</TH>
                    <TH>Base QTY</TH>
                    <TH>Alternate UOM</TH>
                    <TH>Base UOM</TH>
                    <TH>Action</TH>
                </TableHead>

                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.id }}</TD>
                        <TD>{{ item.ItemNo }}</TD>
                        <TD>{{ item.ItemDescription }}</TD>
                        <TD>{{ item.BaseUOM }}</TD>
                        <TD>{{ item.BaseQty }}</TD>
                        <TD>{{ item.AltUOM }}</TD>
                        <TD>{{ item.AltQty }}</TD>
                        <TD class="flex items-center gap-2">
                            <ShowButton @click="viewDetails(item.id)" />
                            <EditButton
                                @click="editCategoryDetails(item.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route(
                                            'sapitems-list.destroy',
                                            item.id
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
                <MobileTableRow v-for="item in items.data">
                    <MobileTableHeading
                        :title="`${item.name} (${item.inventory_code})`"
                    >
                        <ShowButton @click="viewDetails(item.id)" />
                        <EditButton @click="editCategoryDetails(item.id)" />
                    </MobileTableHeading>
                    <!-- <LabelXS>UOM: {{ item.unit_of_measurement.name }}</LabelXS>
                    <LabelXS>Cost: {{ item.cost }}</LabelXS> -->
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="items" />
        </TableContainer>

        <Dialog v-model:open="isEditModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Import Products</DialogTitle>
                    <DialogDescription>
                        Import the excel file of the products.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-5">
                    <div class="flex flex-col space-y-1">
                        <Input
                            type="file"
                            @input="
                                importForm.products_file =
                                    $event.target.files[0]
                            "
                        />
                        <FormError>{{
                            importForm.errors.products_file
                        }}</FormError>
                    </div>
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs"
                            >Accepted Products File Format</Label
                        >
                        <ul>
                            <li class="text-xs">
                                <a
                                    class="text-blue-500 underline"
                                    href="/excel/products-template"
                                    >Click to download</a
                                >
                            </li>
                        </ul>
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        :disabled="isLoading"
                        @click="importFile"
                        type="submit"
                        class="gap-2"
                    >
                        Proceed
                        <span><Loading v-if="isLoading" /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
