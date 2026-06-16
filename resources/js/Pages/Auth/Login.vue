<script setup>
import Checkbox from "@/components/Checkbox.vue";
import InputError from "@/components/InputError.vue";
import InputLabel from "@/components/InputLabel.vue";
import { computed } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { Input } from "@/components/ui/input";
import ImageBanner from "../../../images/temporaryLoginImage.png";

const props = defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    entities: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    email: "",
    password: "",
    remember: false,
    entity_id: props.entities.length === 1 ? props.entities[0].id : "",
});

const selectedEntityLogo = computed(() => {
    const e = props.entities.find((x) => x.id === form.entity_id);
    return e ? e.logo_url : null;
});

const submit = () => {
    form.post(route("login"), {
        onFinish: () => form.reset("password"),
    });
};
</script>

<template>
    <div
        class="grid lg:grid-cols-2 gap-10 min-h-screen max-h-screen items-center sm:p-20 p-5 grid-cols-1"
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
                    <InputLabel for="entity_id" value="Entity" />

                    <div class="flex items-center gap-3 mt-1">
                        <img
                            v-if="selectedEntityLogo"
                            :src="selectedEntityLogo"
                            alt="entity logo"
                            class="h-12 w-12 rounded object-contain border bg-white shrink-0"
                        />
                        <select
                            id="entity_id"
                            v-model="form.entity_id"
                            required
                            class="block w-full sm:h-12 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            <option value="" disabled>Select an entity</option>
                            <option
                                v-for="entity in entities"
                                :key="entity.id"
                                :value="entity.id"
                            >
                                {{ entity.name }} ({{ entity.code }})
                            </option>
                        </select>
                    </div>

                    <InputError class="mt-2" :message="form.errors.entity_id" />
                </div>

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

                    <!-- <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="rounded-md sm:text-sm text-xs text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        Forgot your password?
                    </Link> -->
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
