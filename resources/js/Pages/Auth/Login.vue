<script setup>
import Checkbox from "@/Components/Checkbox.vue";
import InputError from "@/Components/InputError.vue";
import InputLabel from "@/Components/InputLabel.vue";
import { Link, useForm } from "@inertiajs/vue3";
import { Input } from "@/components/ui/input";
import ImageBanner from "../../../images/temporaryLoginImage.png";

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: "",
    password: "",
    remember: false,
});

const submit = () => {
    form.post(route("login"), {
        onFinish: () => form.reset("password"),
    });
};
</script>

<template>
    <div
        class="grid lg:grid-cols-2 gap-10 max-h-screen items-center sm:p-20 p-5 grid-cols-1"
    >
        <section class="lg:block hidden">
            <img :src="ImageBanner" alt="banner" />
        </section>
        <section
            class="border border-gray-300 rounded-lg sm:p-10 sm:space-y-5 space-y-3 p-5"
        >
            <h1 class="sm:text-lg text-sm sm:mb-5 text-[#74d1f6]">
                Welcome to DAVID!
            </h1>
            <h1 class="sm:text-2xl text-lg font-bold text-[#24ace4]">
                Sign in to your account
            </h1>

            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <InputLabel for="email" value="Email" />

                    <Input
                        id="email"
                        type="email"
                        class="mt-1 block w-full sm:h-12"
                        v-model="form.email"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="Enter your email"
                    />

                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div class="mt-4">
                    <InputLabel for="password" value="Password" />

                    <Input
                        id="password"
                        type="password"
                        class="mt-1 block w-full sm:h-12"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                        placeholder="Enter your password"
                    />

                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <div class="block">
                        <label class="flex items-center">
                            <Checkbox
                                name="remember"
                                v-model:checked="form.remember"
                            />
                            <span class="ms-2 sm:text-sm text-xs text-gray-600"
                                >Remember me</span
                            >
                        </label>
                    </div>

                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="rounded-md sm:text-sm text-xs text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        Forgot your password?
                    </Link>
                </div>

                <Button
                    class="w-full h-12 bg-[#24ace4] font-bold text-lg"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Log in
                </Button>
            </form>
        </section>
    </div>
</template>
