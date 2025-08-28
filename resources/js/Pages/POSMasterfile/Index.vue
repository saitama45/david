<script setup>
import { useSearch } from "@/Composables/useSearch";
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { useAuth } from "@/Composables/useAuth";
import { useReferenceDelete } from "@/Composables/useReferenceDelete";
import { ref, watch, computed, onMounted } from 'vue';

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
                    <TH>Category</TH>
                    <TH>SubCategory</TH>
                    <TH>SRP</TH>
                    <TH>Active</TH>
                    <TH>Action</TH>
                </TableHead>

                <TableBody>
                    <tr v-for="item in items.data" :key="item.id">
                        <TD>{{ item.id }}</TD>
                        <TD>{{ item.POSCode }}</TD> <!-- Changed from item.ItemCode -->
                        <TD>{{ item.POSDescription }}</TD> <!-- Changed from item.ItemDescription -->
                        <TD>{{ item.Category }}</TD>
                        <TD>{{ item.SubCategory }}</TD>
                        <TD>{{ item.SRP }}</TD>
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
                    <LabelXS>Category: {{ item.Category }}</LabelXS>
                    <LabelXS>SubCategory: {{ item.SubCategory }}</LabelXS>
                    <LabelXS>SRP: {{ item.SRP }}</LabelXS>
                    <LabelXS>Active: {{ Number(item.is_active) ? 'Yes' : 'No' }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="items" />
        </TableContainer>

        <!-- Import Products Dialog -->
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
                                    :href="route('excel.POSMasterfile-template')"
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