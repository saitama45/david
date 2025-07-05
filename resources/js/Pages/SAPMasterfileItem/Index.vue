<script setup>
import { useSearch } from "@/Composables/useSearch";
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";

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
import { useReferenceDelete } from "@/Composables/useReferenceDelete";
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
                        <TD>{{ item.AlternateUOM }}</TD>
                        <TD>{{ item.AlternateQty }}</TD>
                        <TD class="flex items-center gap-2">
                            <ShowButton
                                v-if="hasAccess('view item')"
                                :isLink="true"
                                :href="`sapitems-list/show/${item.inventory_code}`"
                            />
                            <EditButton
                                v-if="hasAccess('edit items')"
                                :isLink="true"
                                :href="route('sapitems.edit', item.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route('sapitems.destroy', item.id),
                                        'Product'
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
                        <ShowButton
                            v-if="hasAccess('view item')"
                            :isLink="true"
                            :href="`items-list/show/${item.inventory_code}`"
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
                                    'Product'
                                )
                            "
                        />
                    </MobileTableHeading>
                    <LabelXS>UOM: {{ item.unit_of_measurement.name }}</LabelXS>
                    <LabelXS>Cost: {{ item.cost }}</LabelXS>
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
