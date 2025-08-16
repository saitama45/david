<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@/Composables/useToast";
import { ref } from 'vue'; // Explicitly import ref
import { router } from "@inertiajs/vue3"; // Import router for client-side navigation

const { toast } = useToast();
const form = useForm({
    branch_code: "",
    brand_code: "",
    name: "",
    brand_name: "",
    location_code: "",
    store_status: "",
    tin: "",
    complete_address: "",
    head_chef: "",
    director_operations: "",
    vp_operations: "",
    store_representative: "",
    aom: "",
    point_of_contact: "",
    contact_number: "",
    is_active: 1, // Default to Active
});

const store = () => {
    // FIX: Changed route name from 'store-branches.store' to 'branches.store'
    form.post(route("branches.store"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Store Branch Created Successfully.",
                life: 5000,
            });
            form.reset();
            // FIX: Use router.visit for client-side navigation without full page reload
            router.visit(route('branches.index'));
        },
        onError: () => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occurred while trying to create the store branch.",
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
    <Layout heading="Create New Store Branch">
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
                    <FormError v-if="form.errors.location_code"> <!-- FIX: Changed error check to location_code -->
                        {{ form.errors.location_code }}
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
                <Button @click="store">Create</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
