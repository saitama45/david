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
const page = usePage(); // Get the page object

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
const persistentSkippedItemsMessage = ref(''); // New ref for persistent message

const formatSkippedItemsMessage = (items) => {
    if (!items || items.length === 0) {
        return '';
    }

    let message = 'The following items were skipped during import due to validation issues:\n\n';
    items.forEach(item => {
        // Use 'pos_code' and 'pos_description' as keys based on POSMasterfileImport's addSkippedItem
        message += `- POS Code: ${item.pos_code || 'N/A'}, Description: ${item.pos_description || 'N/A'}, Reason: ${item.reason}\n`;
    });
    return message;
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

            // Debugging log: Check what's in page.props.flash.skippedItems
            console.log('page.props.flash.skippedItems on success:', page.props.flash.skippedItems);

            if (page.props.flash && page.props.flash.skippedItems && page.props.flash.skippedItems.length > 0) {
                skippedItems.value = page.props.flash.skippedItems;
                persistentSkippedItemsMessage.value = formatSkippedItemsMessage(skippedItems.value);
                toast.add({
                    severity: "warn",
                    summary: "Import Completed with Warnings",
                    detail: `Some products were skipped during import. See persistent message for details.`,
                    life: 5000,
                });
            } else {
                persistentSkippedItemsMessage.value = ''; // Clear message if no skipped items
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: "Products Updated Successfully.",
                    life: 3000,
                });
            }
        },
        onError: (e) => {
            isLoading.value = false;
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occurred while trying to update products. Please make sure that you are using the correct format.",
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
    // Debugging log: Check what's in page.props.flash.skippedItems on mount
    console.log('page.props.flash.skippedItems on mounted:', page.props.flash.skippedItems);

    if (page.props.flash && page.props.flash.skippedItems && page.props.flash.skippedItems.length > 0) {
        skippedItems.value = page.props.flash.skippedItems;
        persistentSkippedItemsMessage.value = formatSkippedItemsMessage(skippedItems.value);
        toast.add({
            severity: "warn",
            summary: "Import Completed with Warnings",
            detail: `Some products were skipped during the last import. See persistent message for details.`,
            life: 5000,
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
        <div v-if="persistentSkippedItemsMessage" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <strong class="font-bold">Import Warnings:</strong>
            <span class="block sm:inline whitespace-pre-line">{{ persistentSkippedItemsMessage }}</span>
            <span class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer" @click="persistentSkippedItemsMessage = ''">
                <svg class="fill-current h-6 w-6 text-red-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
            </span>
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
                    <TH>POS Code</TH> <!-- Changed from Item Code -->
                    <TH>POS Desc</TH> <!-- Changed from Item Desc -->
                    <TH>POS Name</TH> <!-- New POS Name column -->
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
                        <TD>{{ item.POSCode }}</TD> <!-- Changed from item.ItemCode -->
                        <TD>{{ item.POSDescription }}</TD> <!-- Changed from item.ItemDescription -->
                        <TD>{{ item.POSName }}</TD> <!-- New POS Name data -->
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
                        :title="`${item.POSDescription} (${item.POSCode})`" > <!-- Changed from ItemDescription and ItemCode -->
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
                    <LabelXS>POS Code: {{ item.POSCode }}</LabelXS> <!-- Changed from Item Code -->
                    <LabelXS>POS Desc: {{ item.POSDescription }}</LabelXS> <!-- Changed from Item Desc -->
                    <LabelXS>POS Name: {{ item.POSName }}</LabelXS> <!-- New POS Name display -->
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