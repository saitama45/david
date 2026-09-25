<script setup>
import { useSearch } from "@/composables/useSearch";
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { useAuth } from "@/composables/useAuth";
import { useReferenceDelete } from "@/composables/useReferenceDelete";
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';

const toast = useToast();
const confirm = useConfirm();
const page = usePage();

const props = defineProps({
    boms: {
        type: Object,
        required: true,
    },
    pendingDuplicates: {
        type: Array,
        default: () => [],
    },
});

// Rows the import held back: same POS Code, Item Code, BOM UOM and Assembly as an
// existing line, only a different BOM Qty. They are added only once the user allows them.
const selectedDuplicates = ref([]);
const isResolvingDuplicates = ref(false);

watch(() => props.pendingDuplicates, (rows) => {
    const ids = rows.map((row) => row.id);
    selectedDuplicates.value = selectedDuplicates.value.filter((id) => ids.includes(id));
});

const allDuplicatesSelected = computed({
    get: () => props.pendingDuplicates.length > 0 && selectedDuplicates.value.length === props.pendingDuplicates.length,
    set: (checked) => {
        selectedDuplicates.value = checked ? props.pendingDuplicates.map((row) => row.id) : [];
    },
});

const formatQty = (qty) => Number(qty).toLocaleString(undefined, { maximumFractionDigits: 7 });

const resolveDuplicates = (action, ids) => {
    if (ids.length === 0) return;
    const allow = action === 'allow';

    confirm.require({
        header: allow ? 'Allow repeated BOM lines?' : 'Dismiss repeated BOM lines?',
        message: allow
            ? `Add ${ids.length} line(s) next to the existing line for the same item. Every allowed line is deducted separately on each sale, so only allow them if the recipe really uses the item more than once.`
            : `Drop ${ids.length} line(s) from the review list without saving them.`,
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: allow ? 'Allow' : 'Dismiss',
        rejectLabel: 'Cancel',
        acceptClass: allow ? 'p-button-warning' : 'p-button-secondary',
        accept: () => {
            isResolvingDuplicates.value = true;
            router.post(route(`pos-bom.duplicates.${action}`), { ids }, {
                preserveScroll: true,
                onSuccess: () => {
                    const message = page.props.flash?.success || page.props.flash?.warning;
                    if (message) {
                        toast.add({ severity: page.props.flash?.success ? 'success' : 'warn', summary: allow ? 'Lines allowed' : 'Rows dismissed', detail: message, life: 4000 });
                    }
                },
                onFinish: () => {
                    isResolvingDuplicates.value = false;
                },
            });
        },
    });
};

let filter = ref(page.props.filter || "all");

const { search } = useSearch("pos-bom.index");

const changeFilter = (currentFilter) => {
    filter.value = currentFilter;
};

watch(filter, function (value) {
    router.get(
        route("pos-bom.index"),
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
    route("pos-bom.export", {
        search: search.value,
        filter: filter.value,
    })
);

const isImportModalVisible = ref(false);
const skippedItems = ref([]);
const importedItemsCount = ref(0);
const persistentSkippedItemsMessage = ref('');
const isLoading = ref(false);

const formatSkippedItemsMessage = (items) => {
    if (!items || items.length === 0) {
        return '';
    }

    let message = 'The following items were skipped during import due to validation issues:\n\n';
    items.forEach(item => {
        message += `- POS Code: ${item.pos_code || 'N/A'}, Item Code: ${item.item_code || 'N/A'}, Assembly: ${item.assembly || 'N/A'}, Reason: ${item.reason}\n`;
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
    a.download = `pos_bom_skipped_items_${new Date().toISOString().split('T')[0]}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
};

const importForm = useForm({
    pos_bom_file: null,
});

const importFile = () => {
    isLoading.value = true;
    importForm.post(route("pos-bom.import"), {
        onSuccess: () => {
            isLoading.value = false;
            isImportModalVisible.value = false;

            if (page.props.flash) {
                if (page.props.flash.skippedItems) {
                    skippedItems.value = page.props.flash.skippedItems;
                } else {
                    skippedItems.value = [];
                }

                if (page.props.flash.success_count) {
                    importedItemsCount.value = page.props.flash.success_count;
                } else {
                    importedItemsCount.value = 0;
                }

                if (skippedItems.value.length > 0) {
                    if (skippedItems.value.length <= 15) {
                        persistentSkippedItemsMessage.value = formatSkippedItemsMessage(skippedItems.value);
                    } else {
                        persistentSkippedItemsMessage.value = '';
                    }
                    
                    toast.add({
                        severity: "warn",
                        summary: "Import Completed with Warnings",
                        detail: page.props.flash.warning || `${skippedItems.value.length} items were skipped.`, 
                        life: 5000,
                    });
                } else if (page.props.flash.success) {
                    // Removed toast.add for success as per user request to use a static box
                    persistentSkippedItemsMessage.value = '';
                    skippedItems.value = [];
                } else if (page.props.flash.warning) {
                    persistentSkippedItemsMessage.value = '';
                    skippedItems.value = [];
                     toast.add({
                        severity: "warn",
                        summary: "Import Warning",
                        detail: page.props.flash.warning,
                        life: 5000,
                    });
                }
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
            importForm.reset('pos_bom_file');
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

onMounted(() => {
    if (page.props.flash) {
        if (page.props.flash.skippedItems) {
            skippedItems.value = page.props.flash.skippedItems;
        }
        
        if (page.props.flash.success_count) {
            importedItemsCount.value = page.props.flash.success_count;
        }

        if (skippedItems.value.length > 0 && skippedItems.value.length <= 15) {
            persistentSkippedItemsMessage.value = formatSkippedItemsMessage(skippedItems.value);
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
        // Success toast removed to use static green box
    } else if (page.props.flash && page.props.flash.error) {
    }
    document.addEventListener('keydown', handleEscapeKey);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscapeKey);
});
</script>

<template>
    <Layout
        heading="BOM List"
        :hasButton="false" 
        :handleClick="null" 
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <!-- Success Message Box -->
        <div v-if="importedItemsCount > 0 && skippedItems.length === 0" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <strong class="font-bold">Success!</strong>
            <span class="block sm:inline"> {{ importedItemsCount }} items were successfully imported.</span>
            <span class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer" @click="importedItemsCount = 0">
                <svg class="fill-current h-6 w-6 text-green-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
            </span>
        </div>

        <!-- Persistent Skipped Items Message -->
        <div v-if="persistentSkippedItemsMessage && skippedItems.length <= 15" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4" role="alert">
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
            <span v-if="importedItemsCount > 0" class="block sm:inline ml-2"> {{ importedItemsCount }} items were successfully imported.</span>
            
            <button 
                @click="downloadSkippedItems"
                class="mt-2 bg-blue-500 hover:bg-blue-700 text-white font-bold py-1 px-2 rounded text-sm"
            >
                Download Skipped Items Report
            </button>

            <p class="text-sm mt-2">
                Common reasons for skipped items:
                <ul class="list-disc list-inside text-sm ml-4">
                    <li>POS Code, Item Code, or Assembly is missing</li>
                    <li>POS Code not found in POS Masterfile</li>
                    <li>Item Code not found in SAP Masterfile</li>
                    <li>Duplicate items within the import file</li>
                </ul>
            </p>
        </div>

        <!-- Rows held back by the import for review -->
        <div v-if="pendingDuplicates.length > 0" class="bg-amber-50 border border-amber-400 rounded-lg p-4 mb-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-amber-900">
                        Needs review: {{ pendingDuplicates.length }} repeated BOM line(s) were not imported
                    </h3>
                    <p class="text-sm text-amber-800 mt-1">
                        Each row has the same POS Code, Item Code, Assembly and BOM UOM as an existing line; only the BOM Qty differs.
                        Allow the ones the recipe really needs, or dismiss them.
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button
                        class="bg-amber-600 hover:bg-amber-700"
                        :disabled="selectedDuplicates.length === 0 || isResolvingDuplicates"
                        @click="resolveDuplicates('allow', selectedDuplicates)"
                    >
                        Allow selected ({{ selectedDuplicates.length }})
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="selectedDuplicates.length === 0 || isResolvingDuplicates"
                        @click="resolveDuplicates('dismiss', selectedDuplicates)"
                    >
                        Dismiss selected
                    </Button>
                </div>
            </div>

            <div class="mt-3 overflow-x-auto max-h-96 overflow-y-auto bg-white rounded border border-amber-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-amber-100 text-amber-900 sticky top-0">
                        <tr>
                            <th class="px-3 py-2 text-left">
                                <input type="checkbox" v-model="allDuplicatesSelected" aria-label="Select all repeated lines" />
                            </th>
                            <th class="px-3 py-2 text-left">POS Code</th>
                            <th class="px-3 py-2 text-left">POS Desc</th>
                            <th class="px-3 py-2 text-left">Assembly</th>
                            <th class="px-3 py-2 text-left">Item Code</th>
                            <th class="px-3 py-2 text-left">Item Desc</th>
                            <th class="px-3 py-2 text-right">BOM Qty (new)</th>
                            <th class="px-3 py-2 text-right">BOM Qty already saved</th>
                            <th class="px-3 py-2 text-left">BOM UOM</th>
                            <th class="px-3 py-2 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in pendingDuplicates" :key="item.id" class="border-t border-amber-100">
                            <td class="px-3 py-2">
                                <input type="checkbox" :value="item.id" v-model="selectedDuplicates" :aria-label="`Select ${item.row.ItemCode}`" />
                            </td>
                            <td class="px-3 py-2">{{ item.row.POSCode }}</td>
                            <td class="px-3 py-2">{{ item.row.POSDescription }}</td>
                            <td class="px-3 py-2">{{ item.row.Assembly }}</td>
                            <td class="px-3 py-2">{{ item.row.ItemCode }}</td>
                            <td class="px-3 py-2">{{ item.row.ItemDescription }}</td>
                            <td class="px-3 py-2 text-right font-semibold">{{ formatQty(item.row.BOMQty) }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ item.existing_qtys.map(formatQty).join(', ') }}</td>
                            <td class="px-3 py-2">{{ item.row.BOMUOM }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <button
                                    class="text-amber-700 hover:underline font-medium mr-3"
                                    :disabled="isResolvingDuplicates"
                                    @click="resolveDuplicates('allow', [item.id])"
                                >Allow</button>
                                <button
                                    class="text-gray-600 hover:underline"
                                    :disabled="isResolvingDuplicates"
                                    @click="resolveDuplicates('dismiss', [item.id])"
                                >Dismiss</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
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
                        <TD>{{ Number(bom.RecPercent * 100).toFixed(2) }}%</TD>
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
                    <LabelXS>Rec Percent: {{ Number(bom.RecPercent * 100).toFixed(2) }}%</LabelXS>
                    <LabelXS>Recipe Qty: {{ bom.RecipeQty }} {{ bom.RecipeUOM }}</LabelXS>
                    <LabelXS>BOM Qty: {{ bom.BOMQty }} {{ bom.BOMUOM }}</LabelXS>
                    <LabelXS>Unit Cost: {{ bom.UnitCost }}</LabelXS>
                    <LabelXS>Total Cost: {{ bom.TotalCost }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="boms" />
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
                        <h2 class="text-lg font-semibold text-gray-900">Import POS BOMs</h2>
                        <p class="text-sm text-gray-600 mt-1">Import the excel file of the POS BOMs.</p>
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
