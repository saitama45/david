<script setup>
import Checkbox from "primevue/checkbox";

import { useForm } from "@inertiajs/vue3";
const form = useForm({
    name: "",
    selectedPermissions: [],
});
const props = defineProps({
    permissions: {
        type: Object,
        required: true,
    },
    role: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout heading="Edit Role"> </Layout>
</template>
