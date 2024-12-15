<script setup>
import Logo from "../../../images/logo.png";
import { useForm, router } from "@inertiajs/vue3";
const form = useForm({
    first_name: null,
    middle_name: null,
    last_name: null,
    phone_number: null,
    email: null,
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
                        <h1 class="font-bold text-2xl">Nathaniel Alvarez</h1>
                        <p class="text-blue-500 font-bold text-sm">
                            Admin, So Encoder
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
                            <span class="font-bold text-blue-500 text-sm"
                                >09288229210
                            </span>
                            <span class="font-bold text-gray-800 text-sm"
                                >Email:
                            </span>
                            <span class="font-bold text-blue-500 text-sm"
                                >nathaniel@gmail.com
                            </span>
                        </section>
                    </DivFlexCol>

                    <DivFlexCol class="gap-3">
                        <span class="text-xs text-gray-800">Remarks</span>
                        <span class="text-sm text-gray-800 font-bold">N/a</span>
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
                    <Input v-model="form.phone_number" />
                    <FormError>{{ form.errors.phone_number }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>New Password</Label>
                    <Input v-model="form.phone_number" />
                    <FormError>{{ form.errors.phone_number }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Confirm Password</Label>
                    <Input v-model="form.phone_number" />
                    <FormError>{{ form.errors.phone_number }}</FormError>
                </InputContainer>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <Button>Update</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
