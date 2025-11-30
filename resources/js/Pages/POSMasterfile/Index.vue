<script setup>
import { useSearch } from "@/Composables/useSearch";
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { useAuth } from "@/Composables/useAuth";
import { useReferenceDelete } from "@/Composables/useReferenceDelete";
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';

const toast = useToast();
const confirm = useConfirm();
const page = usePage();

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
});

const handleClick = () => {
    router.get(route("POSMasterfile.create"));
};

let filter = ref(page.props.filter || "all");

const { search } = useSearch("POSMasterfile.index");

const changeFilter = (currentFilter) => {
    filter.value = currentFilter;
};

watch(filter, function (value) {
    router.get(
        route("POSMasterfile.index"),
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
    route("POSMasterfile.export", {
        search: search.value,
        filter: filter.value,
    })
);

const isImportModalVisible = ref(false);
const skippedItems = ref([]);
const persistentSkippedItemsMessage = ref('');

const formatSkippedItemsMessage = (items) => {
    if (!items || items.length === 0) {
        return '';
    }

    let message = 'The following items were skipped during import due to validation issues:\n\n';
    items.forEach(item => {
        message += `- POS Code: ${item.pos_code || 'N/A'}, Description: ${item.pos_description || 'N/A'}, Reason: ${item.reason}\n`;
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
    a.download = `pos_masterfile_skipped_items_${new Date().toISOString().split('T')[0]}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
};

const importForm = useForm({
    products_file: null,
});

const importFile = () => {
    isLoading.value = true;
    importForm.post(route("POSMasterfile.import"), {
        onSuccess: () => {
            isLoading.value = false;
            isImportModalVisible.value = false;

            if (page.props.flash && page.props.flash.skippedItems && page.props.flash.skippedItems.length > 0) {
                skippedItems.value = page.props.flash.skippedItems;
                
                if (skippedItems.value.length <= 15) {
                    persistentSkippedItemsMessage.value = formatSkippedItemsMessage(skippedItems.value);
                } else {
                    persistentSkippedItemsMessage.value = '';
                }
                
                toast.add({
                    severity: "warn",
                    summary: "Import Completed with Warnings",
                    detail: `${skippedItems.value.length} items were skipped. Download the report for details.`, 
                    life: 5000,
                });
            } else if (page.props.flash && page.props.flash.success) {
                persistentSkippedItemsMessage.value = '';
                skippedItems.value = [];
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: page.props.flash.success,
                    life: 3000,
                });
            } else if (page.props.flash && page.props.flash.warning) {
                persistentSkippedItemsMessage.value = '';
                skippedItems.value = page.props.flash.skippedItems || [];
                 toast.add({
                    severity: "warn",
                    summary: "Import Warning",
                    detail: page.props.flash.warning,
                    life: 5000,
                });
            }
        },
        onError: (e) => {
            isLoading.value = false;
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occurred during import. Please ensure you are using the correct file format.",
                life: 3000,
            });
        },
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

const openFormModal = () => {
    isImportModalVisible.value = true;
};

const closeFormModal = () => {
    isImportModalVisible.value = false;
};

const handleEscapeKey = (event) => {
    if (event.key === 'Escape' && isImportModalVisible.value) {
        closeFormModal();
    }
};

const handleBackdropClick = (event) => {
    if (event.target === event.currentTarget) {
        closeFormModal();
    }
};

const isLoading = ref(false);

onMounted(() => {
    if (page.props.flash && page.props.flash.skippedItems && page.props.flash.skippedItems.length > 0) {
        skippedItems.value = page.props.flash.skippedItems;
        
        if (skippedItems.value.length <= 15) {
            persistentSkippedItemsMessage.value = formatSkippedItemsMessage(skippedItems.value);
        } else {
            persistentSkippedItemsMessage.value = '';
        }
        
        if(page.props.flash.warning) {
            toast.add({
                severity: "warn",
                summary: "Import Completed with Warnings",
                detail: page.props.flash.warning,
                life: 5000,
            });
        }
    } else if (page.props.flash && page.props.flash.success) {
        toast.add({
            severity: "success",
            summary: "Success",
            detail: page.props.flash.success,
            life: 3000,
        });
    } else if (page.props.flash && page.props.flash.error) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: page.props.flash.error,
            life: 3000,
        });
    }
    document.addEventListener('keydown', handleEscapeKey);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscapeKey);
});
</script>

<template>
    <Layout
        heading="POSMasterfile List"
        :hasButton="hasAccess('create new POSMasterfile items')"
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

        <!-- Persistent Skipped Items Message -->
        <div v-if="persistentSkippedItemsMessage && skippedItems.length <= 15" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <strong class="font-bold">Import Warnings:</strong>
            <span class="block sm:inline whitespace-pre-line">{{ persistentSkippedItemsMessage }}</span>
            <span class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer" @click="persistentSkippedItemsMessage = ''">
                <svg class="fill-current h-6 w-6 text-red-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
            </span>
        </div>
        
        <!-- Download button -->
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
                    <li>Item already exists in the database</li>
                    <li>Missing required values like POS Code or Description</li>
                    <li>Duplicate items within the import file</li>
                    <li>Invalid data format</li>
                </ul>
            </p>
        </div>

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
                    <TH>POS Name</TH>
                    <TH>Category</TH>
                    <TH>SubCategory</TH>
                    <TH>SRP</TH>
                    <TH>Delivery Price</TH>
                    <TH>Table Vibe Price</TH>
                    <TH>Active</TH>
                    <TH>Action</TH>
                </TableHead>

                <TableBody>
                    <tr v-for="item in items.data" :key="item.id">
                        <TD>{{ item.id }}</TD>
                        <TD>{{ item.POSCode }}</TD>
                        <TD>{{ item.POSDescription }}</TD>
                        <TD>{{ item.POSName }}</TD>
                        <TD>{{ item.Category }}</TD>
                        <TD>{{ item.SubCategory }}</TD>
                        <TD>{{ item.SRP }}</TD>
                        <TD>{{ item.DeliveryPrice }}</TD>
                        <TD>{{ item.TableVibePrice }}</TD>
                        <TD>{{ Number(item.is_active) ? 'Yes' : 'No' }}</TD>
                        <TD class="flex items-center gap-2">
                            <ShowButton
                                v-if="hasAccess('view item')"
                                :isLink="true"
                                :href="route('POSMasterfile.show', item.id)"
                            />
                            <EditButton
                                v-if="hasAccess('edit items')"
                                :isLink="true"
                                :href="route('POSMasterfile.edit', item.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route('POSMasterfile.destroy', item.id),
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
                        :title="`${item.POSDescription} (${item.POSCode})`" >
                        <ShowButton
                            v-if="hasAccess('view item')"
                            :isLink="true"
                            :href="route('POSMasterfile.show', item.id)" />
                        <EditButton
                            v-if="hasAccess('edit items')"
                            :isLink="true"
                            :href="route('POSMasterfile.edit', item.id)"
                        />
                        <DeleteButton
                            @click="
                                deleteModel(
                                    route('POSMasterfile.destroy', item.id),
                                    'POSMasterfile Item'
                                )
                            "
                        />
                    </MobileTableHeading>
                    <LabelXS>POS Code: {{ item.POSCode }}</LabelXS>
                    <LabelXS>POS Desc: {{ item.POSDescription }}</LabelXS>
                    <LabelXS>POS Name: {{ item.POSName }}</LabelXS>
                    <LabelXS>Category: {{ item.Category }}</LabelXS>
                    <LabelXS>SubCategory: {{ item.SubCategory }}</LabelXS>
                    <LabelXS>SRP: {{ item.SRP }}</LabelXS>
                    <LabelXS>Delivery Price: {{ item.DeliveryPrice }}</LabelXS>
                    <LabelXS>Table Vibe Price: {{ item.TableVibePrice }}</LabelXS>
                    <LabelXS>Active: {{ Number(item.is_active) ? 'Yes' : 'No' }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="items" />
        </TableContainer>

        <!-- Custom Modal Dialog -->
        <div
            v-if="isImportModalVisible"
            class="fixed inset-0 z-50 flex items-center justify-center"
            @click="handleBackdropClick"
        >
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

            <!-- Modal Content -->
            <div class="relative z-10 w-full max-w-md mx-4 bg-white rounded-lg shadow-xl border border-gray-200 p-6 transform transition-all">
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Import Products</h2>
                        <p class="text-sm text-gray-600 mt-1">Import the excel file of the products.</p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="closeFormModal"
                        class="h-8 w-8 p-0 hover:bg-gray-100"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </Button>
                </div>

                <!-- Form Content -->
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
                                    :href="route('excel.POSMasterfile-template')"
                                    >Click to download</a
                                >
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Footer -->
                <div class="mt-6 flex justify-end">
                    <Button
                        :disabled="isLoading"
                        @click="importFile"
                        type="submit"
                        class="gap-2"
                    >
                        Proceed
                        <span><Loading v-if="isLoading" /></span>
                    </Button>
                </div>
            </div>
        </div>
    </Layout>
</template>
