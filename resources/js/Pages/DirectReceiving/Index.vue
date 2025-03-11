<script setup>
import { router } from "@inertiajs/vue3";
const createDirectReceiving = () => {
    router.visit(route("direct-receiving.create"));
};
</script>

<template>
    <Layout
        heading="Direct Receiving"
        :hasButton="true"
        buttonName="Create New"
        :handleClick="createDirectReceiving"
    >
    </Layout>
</template>
