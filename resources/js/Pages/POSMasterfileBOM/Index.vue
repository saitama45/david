<script setup>
import { useSearch } from "@/Composables/useSearch";
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { useAuth } from "@/Composables/useAuth";
import { useReferenceDelete } from "@/Composables/useReferenceDelete";
import { ref, computed, watch } from 'vue'; // Import necessary Vue functions
import Toast from 'primevue/toast'; // Ensure Toast component is imported

const toast = useToast();
const confirm = useConfirm();

const props = defineProps({
    boms: {
        type: Object,
        required: true,
    },
});

// Removed handleClick as 'Create New BOM' button is being removed
// const handleClick = () => {
//     router.get(route("pos-bom.create"));
// };

let filter = ref(usePage().props.filter || "all");

const { search } = useSearch("pos-bom.index"); // Changed to pos-bom.index

const changeFilter = (currentFilter) => {
    filter.value = currentFilter;
    router.get(
        route("pos-bom.index"), // Changed to pos-bom.index
        { filter: currentFilter, search: search.value },
        {
            preserveState: true,
            replace: true,
        }
    );
};

watch(filter, function (value) {
    router.get(
        route("pos-bom.index"), // Changed to pos-bom.index
        { filter: value, search: search.value },
        {
            preserveState: true,
            replace: true,
        }
    );
});

const { hasAccess } = useAuth();

const { deleteModel } = useReferenceDelete();

const exportRoute = computed(() =>
    route("pos-bom.export", { // Changed to pos-bom.export
        search: search.value,
        filter: filter.value,
    })
);

const isImportModalVisible = ref(false);

const importForm = useForm({
    pos_bom_file: null, // Changed to pos_bom_file for BOM import
});

const importFile = () => {
    isLoading.value = true;
    importForm.post(route("pos-bom.import"), { // Changed route to pos-bom.import
        onSuccess: () => {
            // Toast will be handled by the watch(importSummary) below, as per SupplierItems/Index.vue
            isLoading.value = false;
            isImportModalVisible.value = false;
        },
        onError: (e) => {
            isLoading.value = false;
            console.error('Import Error:', e);
            toast.add({
                severity: "error",
                summary: "Import Error",
                detail: e.pos_bom_file || "An error occurred while trying to update POS BOMs. Please make sure that you are using the correct format.", // Changed message and error key
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
    // Assuming you will create a route for downloading skipped BOM import logs
    return route('pos-bom.downloadSkippedImportLog'); 
});

watch(importSummary, (newValue) => {
    if (newValue) {
        let detailMessage = `Processed: ${newValue.processed_count}. `;
        if (newValue.skipped_empty_keys_count > 0) {
            detailMessage += `Skipped (Empty Keys): ${newValue.skipped_empty_keys_count}. `;
        }
        // NEW: Add specific skipped counts for POS and SAP Masterfile validation
        if (newValue.skipped_pos_masterfile_validation_count > 0) {
            detailMessage += `Skipped (POS Masterfile Validation): ${newValue.skipped_pos_masterfile_validation_count}. `;
        }
        if (newValue.skipped_sap_masterfile_validation_count > 0) {
            detailMessage += `Skipped (SAP Masterfile Validation): ${newValue.skipped_sap_masterfile_validation_count}. `;
        }
        // Note: SupplierItems had skipped_unauthorized_count, POS BOM might not need it unless you implement specific user-based POS BOM permissions.
        // If you add it, ensure it's returned by your POSMasterfileBOMImport.

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
        heading="BOM List"
        :hasButton="false" 
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
                <p>Skipped (POS Masterfile Validation): {{ importSummary.skipped_pos_masterfile_validation_count }}</p>
                <p>Skipped (SAP Masterfile Validation): {{ importSummary.skipped_sap_masterfile_validation_count }}</p>
                <p v-if="importSummary.skipped_unauthorized_count !== undefined">Skipped (Unauthorized): {{ importSummary.skipped_unauthorized_count }}</p>
            </div>

            <div v-if="hasSkippedDetails" class="mt-4 p-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700">
                <p>Some rows were skipped during the import due to validation errors or missing masterfile data.</p>
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
                    <TH>Id</TH>
                    <TH>POS Code</TH>
                    <TH>POS Desc</TH>
                    <TH>Assembly</TH>
                    <TH>Item Code</TH>
                    <TH>Item Desc</TH>
                    <TH>Rec Percent</TH>
                    <TH>Recipe Qty</TH>
                    <TH>Recipe UOM</TH>
                    <TH>BOM Qty</TH>
                    <TH>BOM UOM</TH>
                    <TH>Unit Cost</TH>
                    <TH>Total Cost</TH>
                    <TH>Action</TH>
                </TableHead>

                <TableBody>
                    <tr v-for="bom in boms.data" :key="bom.id">
                        <TD>{{ bom.id }}</TD>
                        <TD>{{ bom.POSCode }}</TD>
                        <TD>{{ bom.POSDescription }}</TD>
                        <TD>{{ bom.Assembly }}</TD>
                        <TD>{{ bom.ItemCode }}</TD>
                        <TD>{{ bom.ItemDescription }}</TD>
                        <TD>{{ Number(bom.RecPercent * 100).toFixed(2) }}%</TD> <!-- Display as percentage -->
                        <TD>{{ bom.RecipeQty }}</TD>
                        <TD>{{ bom.RecipeUOM }}</TD>
                        <TD>{{ bom.BOMQty }}</TD>
                        <TD>{{ bom.BOMUOM }}</TD>
                        <TD>{{ bom.UnitCost }}</TD>
                        <TD>{{ bom.TotalCost }}</TD>
                        <TD class="flex items-center gap-2">
                            <ShowButton
                                v-if="hasAccess('view POSMasterfile BOM')"
                                :isLink="true"
                                :href="route('pos-bom.show', bom.id)"
                            />
                            <EditButton
                                v-if="hasAccess('edit POSMasterfile BOM')"
                                :isLink="true"
                                :href="route('pos-bom.edit', bom.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route('pos-bom.destroy', bom.id),
                                        'POSMasterfile BOM Item'
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="bom in boms.data" :key="bom.id">
                    <MobileTableHeading
                        :title="`${bom.POSDescription} (${bom.POSCode})`" >
                        <ShowButton
                            v-if="hasAccess('view POSMasterfile BOM')"
                            :isLink="true"
                            :href="route('pos-bom.show', bom.id)" />
                        <EditButton
                            v-if="hasAccess('edit POSMasterfile BOM')"
                            :isLink="true"
                            :href="route('pos-bom.edit', bom.id)"
                        />
                        <DeleteButton
                            @click="
                                deleteModel(
                                    route('pos-bom.destroy', bom.id),
                                    'POSMasterfile BOM Item'
                                )
                            "
                        />
                    </MobileTableHeading>
                    <LabelXS>POS Code: {{ bom.POSCode }}</LabelXS>
                    <LabelXS>Item Code: {{ bom.ItemCode }}</LabelXS>
                    <LabelXS>Assembly: {{ bom.Assembly }}</LabelXS>
                    <LabelXS>Rec Percent: {{ Number(bom.RecPercent * 100).toFixed(2) }}%</LabelXS> <!-- Display as percentage -->
                    <LabelXS>Recipe Qty: {{ bom.RecipeQty }} {{ bom.RecipeUOM }}</LabelXS>
                    <LabelXS>BOM Qty: {{ bom.BOMQty }} {{ bom.BOMUOM }}</LabelXS>
                    <LabelXS>Unit Cost: {{ bom.UnitCost }}</LabelXS>
                    <LabelXS>Total Cost: {{ bom.TotalCost }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="boms" />
        </TableContainer>

        <Dialog v-model:open="isImportModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Import POS BOMs</DialogTitle>
                    <DialogDescription>
                        Import the excel file of the POS BOMs.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-5">
                    <div class="flex flex-col space-y-1">
                        <Input
                            type="file"
                            @input="
                                importForm.pos_bom_file =
                                    $event.target.files[0]
                            "
                        />
                        <FormError>{{
                            importForm.errors.pos_bom_file
                        }}</FormError>
                    </div>
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs"
                            >Accepted POS BOMs File Format</Label
                        >
                        <ul>
                            <li class="text-xs">
                                <a
                                    class="text-blue-500 underline"
                                    :href="route('excel.pos-bom-template')"
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
