<script setup>
import { router, useForm, usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { useAuth } from "@/Composables/useAuth";
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
});

let filter = ref(props.filters.search);
const search = ref(filter.value);
const isImportModalVisible = ref(false);
const isLoading = ref(false);

const skippedItems = ref([]);
const persistentSkippedItemsMessage = ref('');

const exportRoute = computed(() =>
    route("month-end-count-templates.export", { search: search.value })
);

watch(
    search,
    throttle(function (value) {
        router.get(
            route("month-end-count-templates.index"),
            { search: value },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

// Import functionality
const openImportModal = () => {
    isImportModalVisible.value = true;
};

const closeImportModal = () => {
    isImportModalVisible.value = false;
    importForm.reset();
};

const importForm = useForm({
    file: null,
});

const handleImport = () => {
    if (!importForm.file) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Please select a file to import.",
            life: 3000,
        });
        return;
    }

    isLoading.value = true;
    importForm.post(route('month-end-count-templates.import'), {
        onSuccess: () => {
            closeImportModal();
            const flash = page.props.flash;
            if (flash && flash.skippedItems && flash.skippedItems.length > 0) {
                skippedItems.value = flash.skippedItems;
                if (skippedItems.value.length <= 15) {
                    persistentSkippedItemsMessage.value = formatSkippedItemsMessage(skippedItems.value);
                } else {
                    persistentSkippedItemsMessage.value = '';
                }
                toast.add({
                    severity: "warn",
                    summary: "Import Completed with Warnings",
                    detail: `${skippedItems.value.length} rows were skipped. Download the report for details.`,
                    life: 5000,
                });
            } else if (flash && flash.success) {
                persistentSkippedItemsMessage.value = '';
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: flash.success || "Templates imported successfully.",
                    life: 3000,
                });
            } else if (flash && flash.error) {
                persistentSkippedItemsMessage.value = '';
                 toast.add({
                    severity: "error",
                    summary: "Import Error",
                    detail: flash.error,
                    life: 5000,
                });
            }
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
            skippedItems.value = page.props.flash.skippedItems;
             if (skippedItems.value.length <= 15) {
                persistentSkippedItemsMessage.value = formatSkippedItemsMessage(skippedItems.value);
            } else {
                persistentSkippedItemsMessage.value = '';
            }
            toast.add({
                severity: "warn",
                summary: "Import Completed with Warnings",
                detail: `${skippedItems.value.length} rows were skipped during the last import. Download the report for details.`,
                life: 5000,
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
import { useReferenceDelete } from "@/Composables/useReferenceDelete";
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
        <!-- Persistent Skipped Items Message (only shown for 15 or fewer items) -->
        <div v-if="persistentSkippedItemsMessage && skippedItems.length <= 15" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <strong class="font-bold">Import Warnings:</strong>
            <span class="block sm:inline whitespace-pre-line">{{ persistentSkippedItemsMessage }}</span>
            
            <span class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer" @click="persistentSkippedItemsMessage = ''">
                <svg class="fill-current h-6 w-6 text-red-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
            </span>
        </div>

        <!-- Download button (always shown when there are skipped items) -->
        <div v-if="skippedItems.length > 0" class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-4" role="alert">
            <strong class="font-bold">Import Summary:</strong>
            <span class="block sm:inline"> {{ skippedItems.length }} items were skipped during import.</span>
            
            <button 
                @click="downloadSkippedItems"
                class="mt-2 bg-blue-500 hover:bg-blue-700 text-white font-bold py-1 px-2 rounded text-sm"
            >
                Download Skipped Items Report
            </button>

            <p class="text-sm mt-2">
                Common reasons for skipped items:
                <ul class="list-disc list-inside text-sm ml-4">
                    <li>Item already exists in the database with no changes</li>
                    <li>Missing Item Code or Item Name</li>
                    <li>Invalid data format</li>
                </ul>
            </p>
        </div>

        <template #header-actions v-if="hasAccess('import month end count templates')">
            <Button
                @click="openImportModal"
                class="sm:text-normal text-xs"
            >
                Upload
            </Button>
        </template>
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
                    <TH>Category 1</TH>
                    <TH>Area</TH>
                    <TH>Category 2</TH>
                    <TH>Packaging</TH>
                    <TH>Conversion</TH>
                    <TH>Bulk UOM</TH>
                    <TH>Loose UOM</TH>
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
                    <LabelXS>Category 1: {{ template.category || 'N/A' }}</LabelXS>
                    <LabelXS>Area: {{ template.area || 'N/A' }}</LabelXS>
                    <LabelXS>Category 2: {{ template.category_2 || 'N/A' }}</LabelXS>
                    <LabelXS>Packaging: {{ template.packaging_config || 'N/A' }}</LabelXS>
                    <LabelXS>Conversion: {{ template.config || 'N/A' }}</LabelXS>
                    <LabelXS>Bulk UOM: {{ template.uom || 'N/A' }}</LabelXS>
                    <LabelXS>Loose UOM: {{ template.loose_uom || 'N/A' }}</LabelXS>
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
                        @click="handleImport"
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
    </Layout>
</template>