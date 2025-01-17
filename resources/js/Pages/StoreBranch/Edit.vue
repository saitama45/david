sm:
<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@/Composables/useToast";

const { toast } = useToast();
const { branch } = defineProps({
    branch: {
        type: Object,
        required: true,
    },
});
const form = useForm({
    branch_code: branch.branch_code || "",
    name: branch.name || "",
    brand_name: branch.brand_name || "",
    brand_code: branch.brand_code || "",
    location_code: branch.location_code || "",
    store_status: branch.store_status || "",
    tin: branch.tin || "",
    complete_address: branch.complete_address || "",
    head_chef: branch.head_chef || "",
    director_operations: branch.director_operations || "",
    vp_operations: branch.vp_operations || "",
    store_representative: branch.store_representative || "",
    aom: branch.aom || "",
    point_of_contact: branch.point_of_contact || "",
    contact_number: branch.contact_number || "",
    is_active: branch.is_active ?? null,
});

const update = (id) => {
    form.post(route("store-branches.update", id), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Store Branch Updated Successfully.",
                life: 5000,
            });
            form.reset();
        },
        onError: () => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occured while trying to update the store branch details.",
                life: 5000,
            });
        },
    });
};

const activeStatuses = ref([
    { label: "Active", value: 1 },
    { label: "Inactive", value: 0 },
]);
</script>

<template>
    <Layout heading="Edit Store Branch Details">
        <Card>
            <CardHeader>
                <CardTitle>Store Branch Details</CardTitle>
                <CardDescription>
                    Please input all the required fields
                </CardDescription>
            </CardHeader>
            <CardContent class="grid sm:grid-cols-2 gap-5">
                <InputContainer>
                    <LabelXS>Branch Code</LabelXS>
                    <Input
                        v-model="form.branch_code"
                        placeholder="Enter branch code"
                    />
                    <FormError v-if="form.errors.branch_code">
                        {{ form.errors.branch_code }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Name</LabelXS>
                    <Input
                        v-model="form.name"
                        placeholder="Enter branch name"
                    />
                    <FormError v-if="form.errors.name">
                        {{ form.errors.name }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Brand Name</LabelXS>
                    <Input
                        v-model="form.brand_name"
                        placeholder="Enter brand name"
                    />
                    <FormError v-if="form.errors.brand_name">
                        {{ form.errors.brand_name }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Brand Code</LabelXS>
                    <Input
                        v-model="form.brand_code"
                        placeholder="Enter brand code"
                    />
                    <FormError v-if="form.errors.brand_code">
                        {{ form.errors.brand_code }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Location Code</LabelXS>
                    <Input
                        v-model="form.location_code"
                        placeholder="Enter location code"
                    />
                    <FormError v-if="form.errors.brand_code">
                        {{ form.errors.brand_code }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Store Status</LabelXS>
                    <Input
                        v-model="form.store_status"
                        placeholder="Enter store status"
                    />
                    <FormError v-if="form.errors.store_status">
                        {{ form.errors.store_status }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>TIN</LabelXS>
                    <Input v-model="form.tin" placeholder="Enter TIN" />
                    <FormError v-if="form.errors.tin">
                        {{ form.errors.tin }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Complete Address</LabelXS>
                    <Input
                        v-model="form.complete_address"
                        placeholder="Enter complete address"
                    />
                    <FormError v-if="form.errors.complete_address">
                        {{ form.errors.complete_address }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Head Chef Email</LabelXS>
                    <Input
                        v-model="form.head_chef"
                        placeholder="Enter head chef email"
                        type="email"
                    />
                    <FormError v-if="form.errors.head_chef">
                        {{ form.errors.head_chef }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Director of Operations Email</LabelXS>
                    <Input
                        v-model="form.director_operations"
                        placeholder="Enter director of operations"
                        type="email"
                    />
                    <FormError v-if="form.errors.director_operations">
                        {{ form.errors.director_operations }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>VP of Operations Email</LabelXS>
                    <Input
                        v-model="form.vp_operations"
                        placeholder="Enter VP of operations"
                        type="email"
                    />
                    <FormError v-if="form.errors.vp_operations">
                        {{ form.errors.vp_operations }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Store Representative</LabelXS>
                    <Input
                        v-model="form.store_representative"
                        placeholder="Enter store representative"
                        type="email"
                    />
                    <FormError v-if="form.errors.store_representative">
                        {{ form.errors.store_representative }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>AOM Email</LabelXS>
                    <Input
                        v-model="form.aom"
                        placeholder="Enter AOM"
                        type="email"
                    />
                    <FormError v-if="form.errors.aom">
                        {{ form.errors.aom }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Point of Contact</LabelXS>
                    <Input
                        v-model="form.point_of_contact"
                        placeholder="Enter point of contact"
                    />
                    <FormError v-if="form.errors.point_of_contact">
                        {{ form.errors.point_of_contact }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Contact Number</LabelXS>
                    <Input
                        v-model="form.contact_number"
                        placeholder="Enter contact number"
                    />
                    <FormError v-if="form.errors.contact_number">
                        {{ form.errors.contact_number }}
                    </FormError>
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
                    <FormError v-if="form.errors.is_active">
                        {{ form.errors.is_active }}
                    </FormError>
                </InputContainer>
            </CardContent>
            <CardFooter class="flex justify-end gap-3">
                <BackButton />
                <Button @click="update(branch.id)">Create</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
