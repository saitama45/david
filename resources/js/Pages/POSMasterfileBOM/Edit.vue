<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import MultiSelect from "primevue/multiselect"; // Keep if MultiSelect is used elsewhere, otherwise remove
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { ref, watch } from 'vue'; // Ensure ref and watch are imported
import { useBackButton } from "@/Composables/useBackButton"; // Import useBackButton

const { backButton } = useBackButton(route("pos-bom.index")); // Set back button route

const isImportModalVisible = ref(false); // Consider moving import logic to Index.vue

const importForm = useForm({
    pos_bom_file: null, // Changed to pos_bom_file for BOM import
});

const importFile = () => {
    isLoading.value = true;
    importForm.post(route("pos-bom.import"), { // Changed route to pos-bom.import
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "POS BOMs Updated Successfully.", // Changed detail message for import
                life: 3000,
            });
            isLoading.value = false;
            isImportModalVisible.value = false; // Close modal on success
        },
        onError: (e) => {
            isLoading.value = false;
            console.error("Import Error:", e); // Log the error for debugging
            toast.add({
                severity: "error",
                summary: "Error",
                detail: e.pos_bom_file || "An error occurred while trying to import. Please check file format and logs.", // Changed message and error key
                life: 3000,
            });
        },
        onFinish: () => {
            isLoading.value = false;
        },
    });
};
const toast = useToast();
const confirm = useConfirm();

const props = defineProps({
    bom: { // Changed prop name from 'item' to 'bom'
        type: Object,
        required: true,
    },
});

const isLoading = ref(false); // For import functionality

watch(isImportModalVisible, (value) => {
    if (!value) {
        importForm.reset();
        importForm.clearErrors();
        isLoading.value = false;
    }
});

const bom = props.bom; // Changed variable name from 'item' to 'bom'

// Initialize the form with existing BOM data
const form = useForm({
    POSCode: bom.POSCode ?? null,
    POSDescription: bom.POSDescription ?? '',
    Assembly: bom.Assembly ?? '',
    ItemCode: bom.ItemCode ?? null,
    ItemDescription: bom.ItemDescription ?? '',
    RecPercent: bom.RecPercent ?? 0,
    RecipeQty: bom.RecipeQty ?? 0,
    RecipeUOM: bom.RecipeUOM ?? '',
    BOMQty: bom.BOMQty ?? 0,
    BOMUOM: bom.BOMUOM ?? '',
    UnitCost: bom.UnitCost ?? 0,
    TotalCost: bom.TotalCost ?? 0,
    // created_by and updated_by are handled by the backend, not part of the form for update
});

const handleUpdate = () => {
    confirm.require({
        message: "Are you sure you want to update this POS BOM?", // Changed message
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "success",
        },
        accept: () => {
            // Use form.put for update operations
            form.put(route("pos-bom.update", bom.id), { // Changed route to pos-bom.update and variable to bom.id
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "POS BOM Successfully Updated", // Changed message
                        life: 3000,
                    });
                },
                onError: (e) => {
                    console.error("Update Error:", e); // Log the error for debugging
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "Failed to update POS BOM. Please check the form data.", // Changed message
                        life: 3000,
                    });
                },
            });
        },
    });
};

// No activeStatuses needed as POSMasterfileBOM doesn't have is_active
// const activeStatuses = ref([
//     { label: "Active", value: 1 },
//     { label: "Inactive", value: 0 },
// ]);
</script>

<template>
    <Layout heading="Edit POSMasterfile BOM Details">
        <Card>
            <CardHeader>
                <CardTitle>POSMasterfile BOM Details</CardTitle>
                <CardDescription>Input all the important fields</CardDescription>
            </CardHeader>
            <CardContent class="grid sm:grid-cols-2 gap-5">
                <InputContainer>
                    <Label>POS Code</Label>
                    <Input v-model="form.POSCode" />
                    <FormError>{{ form.errors.POSCode }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>POS Description</Label>
                    <Input v-model="form.POSDescription" />
                    <FormError>{{ form.errors.POSDescription }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Assembly</Label>
                    <Input v-model="form.Assembly" />
                    <FormError>{{ form.errors.Assembly }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Item Code</Label>
                    <Input v-model="form.ItemCode" />
                    <FormError>{{ form.errors.ItemCode }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Item Description</Label>
                    <Input v-model="form.ItemDescription" />
                    <FormError>{{ form.errors.ItemDescription }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Rec Percent</Label>
                    <Input type="number" v-model="form.RecPercent" step="0.0001" />
                    <FormError>{{ form.errors.RecPercent }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Recipe Quantity</Label>
                    <Input type="number" v-model="form.RecipeQty" step="0.0001" />
                    <FormError>{{ form.errors.RecipeQty }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Recipe UOM</Label>
                    <Input v-model="form.RecipeUOM" />
                    <FormError>{{ form.errors.RecipeUOM }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>BOM Quantity</Label>
                    <Input type="number" v-model="form.BOMQty" step="0.0001" />
                    <FormError>{{ form.errors.BOMQty }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>BOM UOM</Label>
                    <Input v-model="form.BOMUOM" />
                    <FormError>{{ form.errors.BOMUOM }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Unit Cost</Label>
                    <Input type="number" v-model="form.UnitCost" step="0.0001" />
                    <FormError>{{ form.errors.UnitCost }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Total Cost</Label>
                    <Input type="number" v-model="form.TotalCost" step="0.0001" />
                    <FormError>{{ form.errors.TotalCost }}</FormError>
                </InputContainer>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <Button @click="backButton">Back</Button>
                <Button @click="handleUpdate">Update</Button>
            </CardFooter>
        </Card>
        
        <!-- Import Modal (kept for consistency with SupplierItems Edit, though typically found on Index) -->
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

