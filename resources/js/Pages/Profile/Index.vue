<script setup>
import Logo from "../../../images/logo.png";
import { useForm, router } from "@inertiajs/vue3";
const { user } = defineProps({
    user: {
        type: Object,
        required: true,
    },
});
const form = useForm({
    first_name: user.first_name,
    middle_name: user.middle_name,
    last_name: user.last_name,
    phone_number: user.phone_number,
    email: user.email,
});

const passwordForm = useForm({
    current_password: null,
    password: null,
    confirm_password: null,
});
</script>

<template>
    <Layout heading="My Profile">
        <div class="flex flex-col gap-5">
            <DivFlexCenter class="h-56 gap-10">
                <img
                    :src="Logo"
                    alt="logo"
                    class="size-56 rounded-lg shadow-lg"
                />

                <DivFlexCol class="gap-5 h-56">
                    <section>
                        <h1 class="font-bold text-2xl">
                            {{ user.first_name + " " + user.last_name }}
                        </h1>
                        <p class="text-blue-500 font-bold text-sm">
                            {{
                                user.roles
                                    .map((role) => role.name.toUpperCase())
                                    .join(",")
                            }}
                        </p>
                    </section>

                    <DivFlexCol class="gap-3">
                        <span class="text-xs text-gray-800"
                            >Contact Information</span
                        >
                        <section class="grid grid-cols-2 gap-3">
                            <span class="font-bold text-gray-800 text-sm"
                                >Phone:
                            </span>
                            <span class="font-bold text-blue-500 text-sm">
                                {{ user.phone_number }}
                            </span>
                            <span class="font-bold text-gray-800 text-sm"
                                >Email:
                            </span>
                            <span class="font-bold text-blue-500 text-sm">
                                {{ user.email }}
                            </span>
                        </section>
                    </DivFlexCol>

                    <DivFlexCol class="gap-3">
                        <span class="text-xs text-gray-800">Remarks</span>
                        <span class="text-sm text-gray-800 font-bold">{{
                            user.remarks ?? "N/a"
                        }}</span>
                    </DivFlexCol>
                </DivFlexCol>
            </DivFlexCenter>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Edit Profile Details</CardTitle>
                <CardDesription class="text-xs"
                    >Please input all the important information.</CardDesription
                >
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-5">
                <InputContainer>
                    <Label>First Name</Label>
                    <Input v-model="form.first_name" />
                    <FormError>{{ form.errors.first_name }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Middle Name</Label>
                    <Input v-model="form.middle_name" />
                    <FormError>{{ form.errors.middle_name }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Last Name</Label>
                    <Input v-model="form.last_name" />
                    <FormError>{{ form.errors.last_name }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Phone Number</Label>
                    <Input v-model="form.phone_number" />
                    <FormError>{{ form.errors.phone_number }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Email</Label>
                    <Input v-model="form.email" type="email" />
                    <FormError>{{ form.errors.email }}</FormError>
                </InputContainer>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <Button>Update</Button>
            </CardFooter>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Update Password</CardTitle>
                <CardDesription class="text-xs"
                    >Please input all the important information.</CardDesription
                >
            </CardHeader>
            <CardContent class="space-y-4">
                <InputContainer>
                    <Label>Current Password</Label>
                    <Input v-model="passwordForm.current_password" />
                    <FormError>{{
                        passwordForm.errors.current_password
                    }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>New Password</Label>
                    <Input v-model="passwordForm.password" />
                    <FormError>{{ passwordForm.errors.password }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Confirm Password</Label>
                    <Input v-model="passwordForm.confirm_password" />
                    <FormError>{{
                        passwordForm.errors.confirm_password
                    }}</FormError>
                </InputContainer>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <Button>Update</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
