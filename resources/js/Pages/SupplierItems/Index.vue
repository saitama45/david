<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useSearch } from "@/Composables/useSearch";
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import Toast from 'primevue/toast';
import { router } from "@inertiajs/vue3";
import { useAuth } from "@/Composables/useAuth";
import { useReferenceDelete } from "@/Composables/useReferenceDelete";

const toast = useToast();
const confirm = useConfirm();

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
    assignedSupplierCodes: { // Prop passed from controller
        type: Array,
        default: () => [],
    }
});

// Removed handleClick as 'Create New Item' button is removed

let filter = ref(usePage().props.filter || "all");

const { search } = useSearch("SupplierItems.index");

const changeFilter = (currentFilter) => {
    filter.value = currentFilter; // Update filter ref
    router.get(
        route("SupplierItems.index"),
        { filter: currentFilter, search: search.value }, // Ensure search is preserved
        {
            preserveState: true,
            replace: true,
        }
    );
};

watch(filter, function (value) {
    router.get(
        route("SupplierItems.index"),
        { filter: value, search: search.value }, // Ensure search is preserved
        {
            preserveState: true,
            replace: true,
        }
    );
});

const { hasAccess } = useAuth();

const { deleteModel } = useReferenceDelete();

const exportRoute = computed(() =>
    route("SupplierItems.export", {
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
    importForm.post(route("SupplierItems.import"), {
        onSuccess: () => {
            // Toast will be handled by the watch(importSummary) below
            isLoading.value = false;
            isImportModalVisible.value = false;
        },
        onError: (e) => {
            isLoading.value = false;
            console.error('Import Error:', e);
            toast.add({
                severity: "error",
                summary: "Import Error",
                detail: e.products_file || "An error occurred while trying to update supplier items. Please make sure that you are using the correct format.",
                life: 5000,
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

const flash = computed(() => usePage().props.flash);

const importSummary = computed(() => flash.value.import_summary || null);

const hasSkippedDetails = computed(() => {
    return importSummary.value && importSummary.value.skipped_details_present;
});

const downloadLogLink = computed(() => {
    return route('SupplierItems.downloadSkippedImportLog');
});

watch(importSummary, (newValue) => {
    if (newValue) {
        let detailMessage = `Processed: ${newValue.processed_count}. `;
        if (newValue.skipped_empty_keys_count > 0) {
            detailMessage += `Skipped (Empty Keys): ${newValue.skipped_empty_keys_count}. `;
        }
        if (newValue.skipped_sap_validation_count > 0) {
            detailMessage += `Skipped (Validation): ${newValue.skipped_sap_validation_count}. `;
        }
        if (newValue.skipped_unauthorized_count > 0) {
            detailMessage += `Skipped (Unauthorized): ${newValue.skipped_unauthorized_count}. `;
        }

        toast.add({
            severity: newValue.skipped_details_present ? 'warn' : 'success',
            summary: newValue.skipped_details_present ? 'Import with Skips' : 'Import Successful',
            detail: detailMessage,
            life: 8000
        });
    }
}, { immediate: true });

</script>

<template>
    <Layout
        heading="Supplier Items List"
        :hasButton="false" 
        buttonName="Create New Item"
        :handleClick="null"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <Toast /> 

        <div v-if="importSummary" class="mb-6">
            <div v-if="importSummary.success_message" class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert">
                <p class="font-bold">{{ importSummary.success_message }}</p>
                <p>Processed: {{ importSummary.processed_count }}</p>
                <p>Skipped (Empty Keys): {{ importSummary.skipped_empty_keys_count }}</p>
                <p>Skipped (Validation): {{ importSummary.skipped_sap_validation_count }}</p>
                <p>Skipped (Unauthorized): {{ importSummary.skipped_unauthorized_count }}</p>
            </div>

            <div v-if="hasSkippedDetails" class="mt-4 p-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700">
                <p>Some rows were skipped during the import due to validation errors or unauthorized supplier codes.</p>
                <a :href="downloadLogLink" class="font-bold underline cursor-pointer hover:text-yellow-900">
                    Click here to download a log file with details
                </a>
            </div>
        </div>

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
                    <TH>Category</TH>
                    <TH>Brand</TH> 
                    <TH>Classification</TH> 
                    <TH>Item Code</TH>
                    <TH>Item Name</TH> <!-- Re-included Item Name header -->
                    <TH>Packaging Config</TH>
                    <TH>Unit</TH>
                    <TH>Cost</TH> 
                    <TH>SRP</TH> <!-- Added SRP header -->
                    <TH>Supplier Code</TH>
                    <TH>Active</TH>
                    <TH>Action</TH>
                </TableHead>

                <TableBody>
                    <tr v-for="item in items.data" :key="item.id">
                        <TD>{{ item.category }}</TD> 
                        <TD>{{ item.brand }}</TD> 
                        <TD>{{ item.classification }}</TD>
                        <TD>{{ item.ItemCode }}</TD>
                        <TD>{{ item.item_name }}</TD> <!-- Re-included Item Name data -->
                        <TD>{{ item.packaging_config }}</TD>
                        <TD>{{ item.uom }}</TD> 
                        <TD>{{ item.cost }}</TD>
                        <TD>{{ item.srp }}</TD> <!-- Display SRP -->
                        <TD>{{ item.SupplierCode }}</TD>
                        <TD>{{ Number(item.is_active) ? 'Yes' : 'No' }}</TD>
                        <TD class="flex items-center gap-2">
                            <!-- Access control is handled in the controller, but hasAccess can add another layer -->
                            <ShowButton
                                v-if="hasAccess('view item')"
                                :isLink="true"
                                :href="route('SupplierItems.show', item.id)"
                            />
                            <EditButton
                                v-if="hasAccess('edit items')"
                                :isLink="true"
                                :href="route('SupplierItems.edit', item.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route('SupplierItems.destroy', item.id),
                                        'Supplier Item'
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
                        :title="`${item.item_name} (${item.ItemCode})`" > <!-- Use ItemName and ItemCode -->
                        <ShowButton
                            v-if="hasAccess('view item')"
                            :isLink="true"
                            :href="route('SupplierItems.show', item.id)" />
                        <EditButton
                            v-if="hasAccess('edit items')"
                            :isLink="true"
                            :href="route('SupplierItems.edit', item.id)"
                        />
                        <DeleteButton
                            @click="
                                deleteModel(
                                    route('SupplierItems.destroy', item.id),
                                    'Supplier Item'
                                )
                            "
                        />
                    </MobileTableHeading>
                    <LabelXS>Item Code: {{ item.ItemCode }}</LabelXS>
                    <LabelXS>Item Name: {{ item.item_name }}</LabelXS> <!-- Re-included Item Name -->
                    <LabelXS>Supplier Code: {{ item.SupplierCode }}</LabelXS> 
                    <LabelXS>Category: {{ item.category }}</LabelXS> 
                    <LabelXS>Brand: {{ item.brand }}</LabelXS> 
                    <LabelXS>Classification: {{ item.classification }}</LabelXS> 
                    <LabelXS>Packaging Config: {{ item.packaging_config }}</LabelXS> 
                    <LabelXS>UOM: {{ item.uom }}</LabelXS> 
                    <LabelXS>Cost: {{ item.cost }}</LabelXS> 
                    <LabelXS>SRP: {{ item.srp }}</LabelXS> 
                    <LabelXS>Active: {{ Number(item.is_active) ? 'Yes' : 'No' }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="items" />
        </TableContainer>

        <Dialog v-model:open="isImportModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Import Supplier Items</DialogTitle>
                    <DialogDescription>
                        Import the Excel file of the supplier items.
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
                            >Accepted Supplier Items File Format</Label
                        >
                        <ul>
                            <li class="text-xs">
                                <a
                                    class="text-blue-500 underline"
                                    :href="route('excel.SupplierItems-template')"
                                    >Click to download template</a
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
