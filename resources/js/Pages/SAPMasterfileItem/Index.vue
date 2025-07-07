<script setup>
import { useSearch } from "@/Composables/useSearch";
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { useAuth } from "@/Composables/useAuth";
import { useReferenceDelete } from "@/Composables/useReferenceDelete";

const toast = useToast();

const confirm = useConfirm();

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
});


const handleClick = () => {
    router.get(route("items.create"));
};



let filter = ref(usePage().props.filter || "all");

const { search } = useSearch("items.index");

const changeFilter = (currentFilter) => {
    filter.value = currentFilter;
};

watch(filter, function (value) {
    router.get(
        route("items.index"),
        { filter: value },
        {
            preserveState: true,
            replace: true,
        }
    );
});



const { hasAccess } = useAuth();

const { deleteModel } = useReferenceDelete();

const exportRoute = computed(() =>
    route("items.export", {
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
    importForm.post(route("items.import"), {
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
        heading="SAPMasterfile List"
        :hasButton="hasAccess('create new SAPMasterfile items')"
        buttonName="Create New Item"
        :handleClick="handleClick"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <FilterTab>
            <FilterTabButton
                label="All"
                filter="all"
                :currentFilter="filter"
                @click="changeFilter('all')"
            />
            <FilterTabButton
                label="Active"
                filter="is_active"
                :currentFilter="filter"
                @click="changeFilter('is_active')"
            />
            <FilterTabButton
                label="InActive"
                filter="inactive"
                :currentFilter="filter"
                @click="changeFilter('inactive')"
            />
        </FilterTab>
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
                    <TH>Alternate UOM</TH>
                    <TH>Active</TH>
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
                        <TD>{{ Number(item.is_active) ? 'Yes' : 'No' }}</TD> <TD class="flex items-center gap-2"></TD>
                        <TD class="flex items-center gap-2">
                            <ShowButton
                                v-if="hasAccess('view item')"
                                :isLink="true"
                                :href="route('items.show', item.id)"
                            />
                            <EditButton
                                v-if="hasAccess('edit items')"
                                :isLink="true"
                                :href="route('items.edit', item.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route('items.destroy', item.id),
                                        'SAP Masterfile Item'
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="item in items.data" :key="item.id">
                    <MobileTableHeading
                        :title="`${item.ItemDescription} (${item.ItemNo})`" >
                        <ShowButton
                            v-if="hasAccess('view item')"
                            :isLink="true"
                            :href="route('items.show', item.id)" />
                        <EditButton
                            v-if="hasAccess('edit items')"
                            :isLink="true"
                            :href="route('items.edit', item.id)"
                        />
                        <DeleteButton
                            @click="
                                deleteModel(
                                    route('items.destroy', item.id),
                                    'SAP Masterfile Item' // Changed label
                                )
                            "
                        />
                    </MobileTableHeading>
                    <LabelXS>Item No: {{ item.ItemNo }}</LabelXS>
                    <LabelXS>Base UOM: {{ item.BaseUOM }}</LabelXS>
                    <LabelXS>Base Qty: {{ item.BaseQty }}</LabelXS>
                    <LabelXS>Alt UOM: {{ item.AltUOM }}</LabelXS>
                    <LabelXS>Alt Qty: {{ item.AltQty }}</LabelXS>
                    <LabelXS>Active: {{ Number(item.is_active) ? 'Yes' : 'No' }}</LabelXS>
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
                                    :href="route('excel.sapmasterfile-template')"
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
