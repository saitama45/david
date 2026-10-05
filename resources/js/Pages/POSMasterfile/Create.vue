<script setup>
import { useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import { useToast } from "primevue/usetoast";

const toast = useToast();

const props = defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
});

const categoryOptions = computed(() =>
    props.categories.map((name) => ({ label: name, value: name }))
);

const activeStatuses = [
    { label: "Active", value: 1 },
    { label: "Inactive", value: 0 },
];

const form = useForm({
    POSCode: "",
    POSDescription: "",
    Category: null,
    SubCategory: "",
    UOM: "",
    SRP: 0,
    is_active: 1,
});

const handleCreate = () => {
    // The list page toasts the server's success message; only errors are toasted here.
    form.post(route("POSMasterfile.store"), {
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
    <Layout heading="Create POS Item">
        <Card>
            <CardHeader>
                <CardTitle>POS Item Details</CardTitle>
                <CardDescription>
                    Add the item's BOM afterwards from the POS BOM list.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid sm:grid-cols-2 gap-5">
                <InputContainer>
                    <Label>POS Code</Label>
                    <Input v-model="form.POSCode" />
                    <FormError>{{ form.errors.POSCode }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>POS Desc</Label>
                    <Input v-model="form.POSDescription" />
                    <FormError>{{ form.errors.POSDescription }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Category</Label>
                    <Select
                        v-model="form.Category"
                        :options="categoryOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select or type a category"
                        editable
                        showClear
                    />
                    <FormError>{{ form.errors.Category }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Sub Category</Label>
                    <Input v-model="form.SubCategory" />
                    <FormError>{{ form.errors.SubCategory }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>SRP</Label>
                    <Input v-model="form.SRP" type="number" min="0" step="0.01" />
                    <FormError>{{ form.errors.SRP }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>UOM</Label>
                    <Input v-model="form.UOM" maxlength="50" placeholder="e.g. Gm, Cup, Pc" />
                    <FormError>{{ form.errors.UOM }}</FormError>
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
