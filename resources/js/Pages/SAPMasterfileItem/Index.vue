<script setup>
import { useSearch } from "@/Composables/useSearch";
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { useReferenceDelete } from "@/Composables/useReferenceDelete";


const toast = useToast();

const confirm = useConfirm();

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
});

import { router } from "@inertiajs/vue3";

const handleClick = () => {
    router.get(route("sapitems.create"));
};

import { usePage } from "@inertiajs/vue3";

let filter = ref(usePage().props.filter || "all");

const { search } = useSearch("sapitems.index");

const isEditModalVisible = ref(false);

const editCategoryDetails = (id) => {
    router.get(`/sapitems-list/edit/${id}`);
};

const viewDetails = (id) => {
    router.get(`/sapitems-list/show/${id}`);
};

const changeFilter = (currentFilter) => {
    filter.value = currentFilter;
};

watch(filter, function (value) {
    router.get(
        route("sapitems.index"),
        { filter: value },
        {
            preserveState: true,
            replace: true,
        }
    );
});

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

const { deleteModel } = useReferenceDelete();

const exportRoute = computed(() =>
    route("sapitems.export", {
        search: search.value,
        filter: filter.value,
    })
);

const isImportModalVisible = ref(false);

const importForm = useForm({
    products_file: null,
});

const importFile = () => {
    isLoading.value = true;
    importForm.post(route("sapitems.import"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Products Updated Successfully.",
                life: 3000,
            });
            isLoading.value = false;
            isImportModalVisible.value = false;
        },
        onError: (e) => {
            isLoading.value = false;
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occured while trying to update products. Please make sure that you are using the correct format.",
                life: 3000,
            });
        },
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

const openFormModal = () => {
    return (isImportModalVisible.value = true);
};

const isLoading = ref(false);
</script>

<template>
    <Layout
        heading="SAP Masterfile Items List"
        :hasButton="hasAccess('create new items')"
        buttonName="Create New Item"
        :handleClick="handleClick"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <!-- <FilterTab>
            <FilterTabButton
                label="All"
                filter="all"
                :currentFilter="filter"
                @click="changeFilter('all')"
            />
            <FilterTabButton
                label="With Cost"
                filter="with_cost"
                :currentFilter="filter"
                @click="changeFilter('with_cost')"
            />
            <FilterTabButton
                label="Without Cost"
                filter="without_cost"
                :currentFilter="filter"
                @click="changeFilter('without_cost')"
            />
        </FilterTab> -->
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        v-model="search"
                        placeholder="Search..."
                    />
                </SearchBar>
                <Button @click="openFormModal">Update List</Button>
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
                            <ShowButton @click="viewDetails(branch.id)" />
                            <EditButton
                                @click="editCategoryDetails(branch.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route(
                                            'store-branches.destroy',
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
                <MobileTableRow v-for="item in items.data">
                    <MobileTableHeading
                        :title="`${item.name} (${item.inventory_code})`"
                    >
                        <ShowButton @click="viewDetails(branch.id)" />
                        <EditButton @click="editCategoryDetails(branch.id)" />
                    </MobileTableHeading>
                    <!-- <LabelXS>UOM: {{ item.unit_of_measurement.name }}</LabelXS>
                    <LabelXS>Cost: {{ item.cost }}</LabelXS> -->
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="items" />
        </TableContainer>

        <Dialog v-model:open="isImportModalVisible">
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
