<script setup>
import { router, useForm, usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { useAuth } from "@/composables/useAuth";
import { useToast } from "primevue/usetoast";
import { ref, computed, watch, onMounted, onUnmounted } from "vue";
import Dialog from "primevue/dialog";

const { hasAccess } = useAuth();
const toast = useToast();
const page = usePage();

const props = defineProps({
    templates: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    filter: {
        type: String,
        default: "all",
    },
});

const search = ref(props.filters.search);
const statusFilter = ref(props.filter || "all");
const isImportModalVisible = ref(false);
const isOverwriteConfirmVisible = ref(false);
const isLoading = ref(false);

const skippedItems = ref([]);

const exportRoute = computed(() =>
    route("month-end-count-templates.export", {
        search: search.value,
        filter: statusFilter.value,
    })
);

watch(
    search,
    throttle(function (value) {
        router.get(
            route("month-end-count-templates.index"),
            { search: value, filter: statusFilter.value },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

const changeFilter = (value) => {
    statusFilter.value = value;
    router.get(
        route("month-end-count-templates.index"),
        { filter: value, search: search.value },
        {
            preserveState: true,
            replace: true,
        }
    );
};

// Import functionality
const openImportModal = () => {
    isImportModalVisible.value = true;
};

const closeImportModal = () => {
    isImportModalVisible.value = false;
    isOverwriteConfirmVisible.value = false;
    importForm.reset();
};

const importForm = useForm({
    file: null,
});

// Uploading a template replaces the whole MEC list, so make the user
// acknowledge that missing items get deactivated before anything is sent.
const requestImportConfirmation = () => {
    if (!importForm.file) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Please select a file to import.",
            life: 3000,
        });
        return;
    }

    isOverwriteConfirmVisible.value = true;
};

const cancelOverwriteConfirmation = () => {
    isOverwriteConfirmVisible.value = false;
};

const confirmOverwriteImport = () => {
    isOverwriteConfirmVisible.value = false;
    handleImport();
};

const handleImport = () => {
    if (!importForm.file) {
        return;
    }

    isLoading.value = true;
    importForm.post(route('month-end-count-templates.import'), {
        // The redirect re-mounts this page, and onMounted reports the result from the flash.
        onSuccess: () => {
            closeImportModal();
        },
        onError: (errors) => {
            closeImportModal();
            toast.add({
                severity: "error",
                summary: "Import Error",
                detail: errors.file || page.props.flash.error || 'Failed to import file.',
                life: 3000,
            });
        },
        onFinish: () => {
            isLoading.value = false;
        }
    });
};

const formatSkippedItemsMessage = (items) => {
    if (!items || items.length === 0) return '';
    let message = 'The following rows were skipped during import:\n\n';
    items.forEach(item => {
        message += `- Row ${item.row_number}: (Item Code: ${item.item_code || 'N/A'}, UOM: ${item.uom || 'N/A'}) - Reason: ${item.reason}\n`;
    });
    return message;
};

const downloadSkippedItems = () => {
    if (skippedItems.value.length === 0) return;
    
    const content = formatSkippedItemsMessage(skippedItems.value);
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `skipped_template_items_${new Date().toISOString().split('T')[0]}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
};


onMounted(() => {
    if (page.props.flash) {
        if (page.props.flash.skippedItems && page.props.flash.skippedItems.length > 0) {
            // Every skipped row is listed on the page with its reason, however many there are.
            skippedItems.value = page.props.flash.skippedItems;
            toast.add({
                severity: "warn",
                summary: "Import Completed with Warnings",
                detail: `${page.props.flash.success || ''} The rows not imported are listed on the page with the reason for each.`.trim(),
                life: 8000,
            });
        } else if (page.props.flash.success) {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: page.props.flash.success,
                life: 3000,
            });
        } else if (page.props.flash.error) {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: page.props.flash.error,
                life: 3000,
            });
        }
    }
});


// Delete functionality using composable
import { useReferenceDelete } from "@/composables/useReferenceDelete";
const { deleteModel } = useReferenceDelete();

const handleClick = () => {
    router.get("/month-end-count-templates/create");
};

const formatDateTime = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    }).format(date);
};
</script>

<template>
    <Layout
        heading="Month End Count Templates"
        :hasButton="hasAccess('create month end count templates')"
        buttonName="Create New Template"
        :handleClick="handleClick"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <!-- Rows of the last upload that were not imported, each with its reason -->
        <div v-if="skippedItems.length > 0" class="bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 rounded mb-4" role="alert">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <strong class="font-bold">{{ skippedItems.length }} rows of the file were not imported.</strong>
                    <span class="block text-sm">Correct them in the file and upload it again.</span>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        @click="downloadSkippedItems"
                        class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-1 px-2 rounded text-sm"
                    >
                        Download List
                    </button>
                    <button @click="skippedItems = []" class="text-amber-700 hover:text-amber-900 text-sm font-bold px-2" aria-label="Close">
                        Close
                    </button>
                </div>
            </div>
            <div class="mt-2 max-h-64 overflow-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left border-b border-amber-300">
                            <th class="py-1 pr-3">Row</th>
                            <th class="py-1 pr-3">Item Code</th>
                            <th class="py-1 pr-3">Bulk UOM</th>
                            <th class="py-1">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in skippedItems" :key="item.row_number" class="border-b border-amber-100 align-top">
                            <td class="py-1 pr-3">{{ item.row_number }}</td>
                            <td class="py-1 pr-3">{{ item.item_code || 'N/A' }}</td>
                            <td class="py-1 pr-3">{{ item.uom || 'N/A' }}</td>
                            <td class="py-1">{{ item.reason }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <template #header-actions v-if="hasAccess('import month end count templates')">
            <Button
                @click="openImportModal"
                class="sm:text-normal text-xs"
            >
                Upload
            </Button>
        </template>
        <FilterTab>
            <FilterTabButton
                label="All"
                filter="all"
                :currentFilter="statusFilter"
                @click="changeFilter('all')"
            />
            <FilterTabButton
                label="Active"
                filter="is_active"
                :currentFilter="statusFilter"
                @click="changeFilter('is_active')"
            />
            <FilterTabButton
                label="Inactive"
                filter="inactive"
                :currentFilter="statusFilter"
                @click="changeFilter('inactive')"
            />
        </FilterTab>
        <TableContainer>
            <TableHeader>
                <div class="flex items-center justify-between w-full">
                    <SearchBar>
                        <Input
                            v-model="search"
                            class="pl-10"
                            placeholder="Search templates..."
                        />
                    </SearchBar>
                </div>
            </TableHeader>

            <Table class="sm:table hidden">
                <TableHead>
                    <TH>Id</TH>
                    <TH>Item Code</TH>
                    <TH>Item Name</TH>
                    <TH>Classification</TH>
                    <TH>Area</TH>
                    <TH>Category 2</TH>
                    <TH>Packaging</TH>
                    <TH>Conversion</TH>
                    <TH>Bulk UOM</TH>
                    <TH>Loose UOM</TH>
                    <TH>Status</TH>
                    <TH>Created By</TH>
                    <TH>Created At</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="template in templates.data">
                        <TD>{{ template.id }}</TD>
                        <TD>{{ template.item_code }}</TD>
                        <TD>{{ template.item_name }}</TD>
                        <TD>{{ template.category || 'N/A' }}</TD>
                        <TD>{{ template.area || 'N/A' }}</TD>
                        <TD>{{ template.category_2 || 'N/A' }}</TD>
                        <TD>{{ template.packaging_config || 'N/A' }}</TD>
                        <TD>{{ template.config || 'N/A' }}</TD>
                        <TD>{{ template.uom || 'N/A' }}</TD>
                        <TD>{{ template.loose_uom || 'N/A' }}</TD>
                        <TD>
                            <span
                                :class="template.is_active
                                    ? 'bg-green-100 text-green-800'
                                    : 'bg-red-100 text-red-800'"
                                class="px-2 py-1 rounded-full text-xs font-semibold"
                            >
                                {{ template.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </TD>
                        <TD>{{ template.created_by ? `${template.created_by.first_name} ${template.created_by.last_name}` : 'N/A' }}</TD>
                        <TD>{{ formatDateTime(template.created_at) }}</TD>
                        <TD>
                            <DivFlexCenter class="sm:gap-3">
                                
                                <EditButton
                                    v-if="hasAccess('edit month end count templates')"
                                    :isLink="true"
                                    :href="`/month-end-count-templates/edit/${template.id}`"
                                />
                                <DeleteButton
                                    @click="
                                        deleteModel(
                                            route('month-end-count-templates.destroy', template.id),
                                            'template'
                                        )
                                    "
                                />
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <DivFlexCol class="sm:hidden gap-3">
                <DivFlexCol
                    class="rounded-lg border min-h-20 p-3"
                    v-for="template in templates.data"
                >
                    <MobileTableHeading
                        :title="template.item_name || `Template #${template.id}`"
                    >
                        
                        <EditButton
                            v-if="hasAccess('edit month end count templates')"
                            :isLink="true"
                            :href="`/month-end-count-templates/edit/${template.id}`"
                        />
                        <DeleteButton
                            @click="
                                deleteModel(
                                    route('month-end-count-templates.destroy', template.id),
                                    'template'
                                )
                            "
                        />
                    </MobileTableHeading>
                    <LabelXS>Item Code: {{ template.item_code }}</LabelXS>
                    <LabelXS>Classification: {{ template.category || 'N/A' }}</LabelXS>
                    <LabelXS>Area: {{ template.area || 'N/A' }}</LabelXS>
                    <LabelXS>Category 2: {{ template.category_2 || 'N/A' }}</LabelXS>
                    <LabelXS>Packaging: {{ template.packaging_config || 'N/A' }}</LabelXS>
                    <LabelXS>Conversion: {{ template.config || 'N/A' }}</LabelXS>
                    <LabelXS>Bulk UOM: {{ template.uom || 'N/A' }}</LabelXS>
                    <LabelXS>Loose UOM: {{ template.loose_uom || 'N/A' }}</LabelXS>
                    <LabelXS>Status: {{ template.is_active ? 'Active' : 'Inactive' }}</LabelXS>
                    <LabelXS>Created By: {{ template.created_by ? `${template.created_by.first_name} ${template.created_by.last_name}` : 'N/A' }}</LabelXS>
                    <LabelXS>Created At: {{ formatDateTime(template.created_at) }}</LabelXS>
                </DivFlexCol>
            </DivFlexCol>

            <Pagination :data="templates" />
        </TableContainer>

        <!-- Import Modal -->
        <Dialog
            v-model:visible="isImportModalVisible"
            modal
            :style="{ width: '30rem' }"
        >
            <template #header>
                <DivFlexCol>
                    <SpanBold>Import Templates</SpanBold>
                    <LabelXS>Upload Excel file to import templates</LabelXS>
                </DivFlexCol>
            </template>

            <DivFlexCol>
                <InputContainer>
                    <input
                        type="file"
                        @input="importForm.file = $event.target.files[0]"
                        accept=".xlsx,.csv,.txt"
                        class="w-full p-2 border border-gray-300 rounded-md"
                    />
                    <FormError v-if="importForm.errors.file">
                        {{ importForm.errors.file }}
                    </FormError>
                </InputContainer>

                <DivFlexCenter class="justify-end mt-5 gap-3">
                    <Button
                        @click="closeImportModal"
                        variant="outline"
                        :disabled="isLoading"
                    >
                        Cancel
                    </Button>
                    <Button
                        @click="requestImportConfirmation"
                        :disabled="isLoading || !importForm.file"
                    >
                        <span v-if="isLoading">
                            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                        </span>
                        Import
                    </Button>
                </DivFlexCenter>
            </DivFlexCol>
        </Dialog>

        <!-- Overwrite Confirmation Modal -->
        <Dialog
            v-model:visible="isOverwriteConfirmVisible"
            modal
            :closable="!isLoading"
            :style="{ width: '32rem' }"
        >
            <template #header>
                <DivFlexCol>
                    <SpanBold>Overwrite Month End Count List?</SpanBold>
                    <LabelXS>This replaces the current list</LabelXS>
                </DivFlexCol>
            </template>

            <DivFlexCol>
                <p class="text-sm text-gray-600">
                    Uploading this template will overwrite the current MEC list.
                    Items not included in this file will be automatically
                    deactivated. Do you wish to proceed?
                </p>

                <DivFlexCenter class="justify-end mt-5 gap-3">
                    <Button
                        @click="cancelOverwriteConfirmation"
                        variant="outline"
                        :disabled="isLoading"
                    >
                        Cancel
                    </Button>
                    <Button
                        @click="confirmOverwriteImport"
                        :disabled="isLoading"
                    >
                        Proceed
                    </Button>
                </DivFlexCenter>
            </DivFlexCol>
        </Dialog>
    </Layout>
</template>
