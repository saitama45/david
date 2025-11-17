<script setup>
import { router } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { useAuth } from "@/Composables/useAuth";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useToast } from "primevue/usetoast";
import { useConfirm } from "primevue/useconfirm";
import { ref, computed, watch } from "vue";
import Dialog from "primevue/dialog";

const { hasAccess } = useAuth();
const toast = useToast();
const confirm = useConfirm();

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
const selectedFile = ref(null);

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
    selectedFile.value = null;
};

const handleFileSelect = (event) => {
    selectedFile.value = event.target.files[0];
};

const handleImport = () => {
    if (!selectedFile.value) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Please select a file to import.",
            life: 3000,
        });
        return;
    }

    const formData = new FormData();
    formData.append('file', selectedFile.value);

    isLoading.value = true;

    router.post(route('month-end-count-templates.import'), formData, {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Templates imported successfully.",
                life: 3000,
            });
            closeImportModal();
            isLoading.value = false;
        },
        onError: (errors) => {
            toast.add({
                severity: "error",
                summary: "Import Error",
                detail: errors.file || 'Failed to import file.',
                life: 3000,
            });
            isLoading.value = false;
        },
        onFinish: () => {
            isLoading.value = false;
        }
    });
};

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
                        @change="handleFileSelect"
                        accept=".xlsx,.csv,.txt"
                        class="w-full p-2 border border-gray-300 rounded-md"
                    />
                    <FormError v-if="selectedFile">
                        Selected: {{ selectedFile.name }}
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
                        :disabled="isLoading || !selectedFile"
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