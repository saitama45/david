<script setup>
import { useForm } from "@inertiajs/vue3";

const form = useForm({
    branch_code: "",
    name: "",
    brand_name: "",
    brand_code: "",
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
    is_active: 1, 
});

const store = () => {
    form.post(route("store-branches.store"), {
        onSuccess: () => {
            console.log("Success");
        },
        onError: () => {
            console.log("Error");
        },
    });
};
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
            <CardContent class="grid grid-cols-2 gap-5">
                <InputContainer>
                    <Label>Branch Code</Label>
                    <Input
                        v-model="form.branch_code"
                        placeholder="Enter branch code"
                    />
                    <FormError v-if="form.errors.branch_code">
                        {{ form.errors.branch_code }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Name</Label>
                    <Input
                        v-model="form.name"
                        placeholder="Enter branch name"
                    />
                    <FormError v-if="form.errors.name">
                        {{ form.errors.name }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Brand Name</Label>
                    <Input
                        v-model="form.brand_name"
                        placeholder="Enter brand name"
                    />
                    <FormError v-if="form.errors.brand_name">
                        {{ form.errors.brand_name }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Brand Code</Label>
                    <Input
                        v-model="form.brand_code"
                        placeholder="Enter brand code"
                    />
                    <FormError v-if="form.errors.brand_code">
                        {{ form.errors.brand_code }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Store Status</Label>
                    <Input
                        v-model="form.store_status"
                        placeholder="Enter store status"
                    />
                    <FormError v-if="form.errors.store_status">
                        {{ form.errors.store_status }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <Label>TIN</Label>
                    <Input v-model="form.tin" placeholder="Enter TIN" />
                    <FormError v-if="form.errors.tin">
                        {{ form.errors.tin }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Complete Address</Label>
                    <Input
                        v-model="form.complete_address"
                        placeholder="Enter complete address"
                    />
                    <FormError v-if="form.errors.complete_address">
                        {{ form.errors.complete_address }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Head Chef Email</Label>
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
                    <Label>Director of Operations Email</Label>
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
                    <Label>VP of Operations Email</Label>
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
                    <Label>Store Representative</Label>
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
                    <Label>AOM Email</Label>
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
                    <Label>Point of Contact</Label>
                    <Input
                        v-model="form.point_of_contact"
                        placeholder="Enter point of contact"
                    />
                    <FormError v-if="form.errors.point_of_contact">
                        {{ form.errors.point_of_contact }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Contact Number</Label>
                    <Input
                        v-model="form.contact_number"
                        placeholder="Enter contact number"
                    />
                    <FormError v-if="form.errors.contact_number">
                        {{ form.errors.contact_number }}
                    </FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Active Status</Label>
                    <Select v-model="form.is_active">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </Select>
                    <FormError v-if="form.errors.is_active">
                        {{ form.errors.is_active }}
                    </FormError>
                </InputContainer>
            </CardContent>
            <CardFooter class="flex justify-end">
                <Button @click="store">Create</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
