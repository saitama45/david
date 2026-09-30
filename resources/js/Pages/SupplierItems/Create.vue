<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";

const toast = useToast();

const props = defineProps({
    suppliers: {
        type: Array,
        default: () => [],
    },
});

const activeStatuses = [
    { label: "Active", value: 1 },
    { label: "Inactive", value: 0 },
];

const form = useForm({
    SupplierCode: props.suppliers.length === 1 ? props.suppliers[0].value : null,
    ItemCode: "",
    uom: "",
    item_name: "",
    category: "",
    category2: "",
    area: "",
    brand: "",
    classification: "",
    packaging_config: "",
    config: 0,
    cost: 0,
    srp: 0,
    sort_order: 0,
    is_active: 1,
});

const handleCreate = () => {
    form.post(route("SupplierItems.store"), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Supplier item created",
                life: 3000,
            });
        },
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
    <Layout heading="Create Supplier Item">
        <Card>
            <CardHeader>
                <CardTitle>Supplier Item Details</CardTitle>
                <CardDescription>
                    The item code and unit must already exist in the SAP Masterfile.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid sm:grid-cols-2 gap-5">
                <InputContainer>
                    <Label>Supplier</Label>
                    <Select
                        v-model="form.SupplierCode"
                        :options="suppliers"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select a supplier"
                        filter
                    />
                    <FormError>{{ form.errors.SupplierCode }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Item Code</Label>
                    <Input v-model="form.ItemCode" />
                    <FormError>{{ form.errors.ItemCode }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Unit</Label>
                    <Input v-model="form.uom" placeholder="An Alt UOM of the item in SAP" />
                    <FormError>{{ form.errors.uom }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Item Name</Label>
                    <Input v-model="form.item_name" placeholder="Defaults to the SAP description" />
                    <FormError>{{ form.errors.item_name }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Category</Label>
                    <Input v-model="form.category" />
                    <FormError>{{ form.errors.category }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Category 2</Label>
                    <Input v-model="form.category2" />
                    <FormError>{{ form.errors.category2 }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Area</Label>
                    <Input v-model="form.area" />
                    <FormError>{{ form.errors.area }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Brand</Label>
                    <Input v-model="form.brand" />
                    <FormError>{{ form.errors.brand }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Classification</Label>
                    <Input v-model="form.classification" />
                    <FormError>{{ form.errors.classification }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Packaging Config</Label>
                    <Input v-model="form.packaging_config" />
                    <FormError>{{ form.errors.packaging_config }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Config</Label>
                    <Input type="number" v-model="form.config" min="0" step="0.01" />
                    <FormError>{{ form.errors.config }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Cost</Label>
                    <Input type="number" v-model="form.cost" min="0" step="0.01" />
                    <FormError>{{ form.errors.cost }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>SRP</Label>
                    <Input type="number" v-model="form.srp" min="0" step="0.01" />
                    <FormError>{{ form.errors.srp }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Sort Order</Label>
                    <Input type="number" v-model="form.sort_order" min="0" step="1" />
                    <FormError>{{ form.errors.sort_order }}</FormError>
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
