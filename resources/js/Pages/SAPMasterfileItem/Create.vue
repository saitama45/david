<script setup>
import { useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import { useToast } from "primevue/usetoast";

const toast = useToast();

const props = defineProps({
    itemTypes: {
        type: Array,
        default: () => [],
    },
});

const itemTypeOptions = computed(() =>
    props.itemTypes.map((t) => ({ label: t.name, value: t.id }))
);

const activeStatuses = [
    { label: "Active", value: 1 },
    { label: "Inactive", value: 0 },
];

const form = useForm({
    ItemCode: "",
    ItemDescription: "",
    AltQty: 1,
    AltUOM: "",
    BaseQty: 1,
    BaseUOM: "",
    is_active: 1,
    sap_item_type_id: null,
});

const handleCreate = () => {
    // The list page toasts the server's success message; only errors are toasted here.
    form.post(route("sapitems.store"), {
        preserveScroll: true,
        onError: () => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "Please check the highlighted fields.",
                life: 3000,
            });
        },
    });
};
</script>

<template>
    <Layout heading="Create SAP Item">
        <Card>
            <CardHeader>
                <CardTitle>SAP Item Details</CardTitle>
                <CardDescription>
                    One row per item code and unit. The conversion reads
                    {{ form.AltQty || 0 }} {{ form.AltUOM || "Alt UOM" }} =
                    {{ form.BaseQty || 0 }} {{ form.BaseUOM || "Base UOM" }}.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid sm:grid-cols-2 gap-5">
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
                    <Label>Alt Qty</Label>
                    <Input v-model="form.AltQty" type="number" min="0" step="any" />
                    <FormError>{{ form.errors.AltQty }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Alt UOM</Label>
                    <Input v-model="form.AltUOM" placeholder="e.g. Case" />
                    <FormError>{{ form.errors.AltUOM }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Base Qty</Label>
                    <Input v-model="form.BaseQty" type="number" min="0" step="any" />
                    <FormError>{{ form.errors.BaseQty }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Base UOM</Label>
                    <Input v-model="form.BaseUOM" placeholder="e.g. Can" />
                    <FormError>{{ form.errors.BaseUOM }}</FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Active Status</LabelXS>
                    <Select
                        v-model="form.is_active"
                        :options="activeStatuses"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select a Status"
                    />
                    <FormError>{{ form.errors.is_active }}</FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Item Type</LabelXS>
                    <Select
                        v-model="form.sap_item_type_id"
                        :options="itemTypeOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Uncategorised"
                        showClear
                    />
                    <span class="text-xs text-gray-500">
                        Applies to every UOM row of the item code. Leave blank
                        to keep the type an existing code already has.
                    </span>
                    <FormError>{{ form.errors.sap_item_type_id }}</FormError>
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
