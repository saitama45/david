<script setup>
import { useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import axios from "axios";
import { useToast } from "primevue/usetoast";
import { useConfirm } from "primevue/useconfirm";

const toast = useToast();
const confirm = useConfirm();

const form = useForm({
    POSCode: "",
    Assembly: "",
    ItemCode: "",
    RecPercent: 0,
    RecipeQty: 0,
    RecipeUOM: "",
    BOMQty: null,
    BOMUOM: "",
    UnitCost: 0,
    TotalCost: 0,
    allow_repeat: false,
});

// Looked up as the codes are typed, so the user sees what the code is and which units
// the ingredient can be deducted in before saving.
const posItem = ref(null);
const posChecked = ref(false);
const sapItem = ref(null);
const sapChecked = ref(false);

const lookup = async (params) => (await axios.get(route("pos-bom.lookup"), { params })).data;

let posTimer = null;
watch(() => form.POSCode, (code) => {
    clearTimeout(posTimer);
    posChecked.value = false;
    posItem.value = null;
    if (!code?.trim()) return;

    posTimer = setTimeout(async () => {
        try {
            const data = await lookup({ pos_code: code.trim() });
            if (code !== form.POSCode) return;
            posItem.value = data.pos;
            posChecked.value = true;
        } catch (error) {
            // The server checks the code again on save.
        }
    }, 400);
});

let sapTimer = null;
watch(() => form.ItemCode, (code) => {
    clearTimeout(sapTimer);
    sapChecked.value = false;
    sapItem.value = null;
    form.BOMUOM = "";
    if (!code?.trim()) return;

    sapTimer = setTimeout(async () => {
        try {
            const data = await lookup({ item_code: code.trim() });
            if (code !== form.ItemCode) return;
            sapItem.value = data.item;
            sapChecked.value = true;
            if (data.item?.units.length === 1) form.BOMUOM = data.item.units[0];
        } catch (error) {
            // The server checks the code again on save.
        }
    }, 400);
});

const unitOptions = computed(() =>
    (sapItem.value?.units || []).map((unit) => ({ label: unit, value: unit }))
);

const submit = () => {
    // The list page shows no message for a single new line, so this form toasts its own.
    form.post(route("pos-bom.store"), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "BOM line created.",
                life: 3000,
            });
        },
        onError: (errors) => {
            if (errors.repeat) {
                confirmRepeat(errors.repeat);
                return;
            }

            toast.add({
                severity: "error",
                summary: "Error",
                detail: "Please check the highlighted fields.",
                life: 3000,
            });
        },
    });
};

const handleCreate = () => {
    form.allow_repeat = false;
    submit();
};

// The recipe already uses this item in this unit. A second line is allowed, but only on
// purpose: every line is deducted separately on each sale.
const confirmRepeat = (message) => {
    confirm.require({
        header: "Add a repeated BOM line?",
        message: `${message} Adding this line makes the recipe use the item more than once, and every line is deducted separately on each sale. Add it only if the recipe really needs it.`,
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Add the line",
            severity: "warn",
        },
        accept: () => {
            form.allow_repeat = true;
            submit();
        },
    });
};
</script>

<template>
    <Layout heading="Create BOM Line">
        <Card>
            <CardHeader>
                <CardTitle>BOM Line Details</CardTitle>
                <CardDescription>
                    One line says how much of an ingredient one sale of a POS item uses.
                    A line is the POS Code, Item Code, BOM UOM and Assembly together.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid sm:grid-cols-2 gap-5">
                <InputContainer>
                    <Label>POS Code</Label>
                    <Input v-model="form.POSCode" placeholder="The item sold at the POS" />
                    <span v-if="posItem" class="text-xs text-green-700">{{ posItem.description }}</span>
                    <span v-else-if="posChecked" class="text-xs text-red-600">
                        This POS Code is not in the POS Masterlist.
                    </span>
                    <FormError>{{ form.errors.POSCode }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Assembly</Label>
                    <Input v-model="form.Assembly" placeholder="Optional, e.g. Adobo Rice" />
                    <span class="text-xs text-gray-500">
                        The prepared component this ingredient goes into. Leave blank if there is none.
                    </span>
                    <FormError>{{ form.errors.Assembly }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Item Code</Label>
                    <Input v-model="form.ItemCode" placeholder="The ingredient, from the SAP Masterlist" />
                    <span v-if="sapItem" class="text-xs text-green-700">{{ sapItem.description }}</span>
                    <span v-else-if="sapChecked" class="text-xs text-red-600">
                        This Item Code is not in the SAP Masterlist.
                    </span>
                    <FormError>{{ form.errors.ItemCode }}</FormError>
                </InputContainer>

                <InputContainer>
                    <LabelXS>BOM UOM</LabelXS>
                    <Select
                        v-model="form.BOMUOM"
                        :options="unitOptions"
                        optionLabel="label"
                        optionValue="value"
                        :placeholder="sapItem ? 'Select a unit' : 'Type the Item Code first'"
                        :disabled="!sapItem"
                    />
                    <span class="text-xs text-gray-500">
                        The unit the BOM Qty is in. Only the units SAP has for the item can be used.
                    </span>
                    <FormError>{{ form.errors.BOMUOM }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>BOM Qty</Label>
                    <Input v-model="form.BOMQty" type="number" min="0" step="any" placeholder="Deducted per sale" />
                    <span class="text-xs text-gray-500">
                        How much is deducted from stock for each sale. Must be more than zero.
                    </span>
                    <FormError>{{ form.errors.BOMQty }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Rec Percent</Label>
                    <Input v-model="form.RecPercent" type="number" min="0" step="any" />
                    <FormError>{{ form.errors.RecPercent }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Recipe Quantity</Label>
                    <Input v-model="form.RecipeQty" type="number" min="0" step="any" />
                    <FormError>{{ form.errors.RecipeQty }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Recipe UOM</Label>
                    <Input v-model="form.RecipeUOM" />
                    <FormError>{{ form.errors.RecipeUOM }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Unit Cost</Label>
                    <Input v-model="form.UnitCost" type="number" min="0" step="any" />
                    <FormError>{{ form.errors.UnitCost }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Total Cost</Label>
                    <Input v-model="form.TotalCost" type="number" min="0" step="any" />
                    <FormError>{{ form.errors.TotalCost }}</FormError>
                </InputContainer>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <BackButton />
                <Button :disabled="form.processing" @click="handleCreate">
                    Create
                </Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
