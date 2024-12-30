<script setup>
import { router } from "@inertiajs/vue3";
const handleClick = () => {
    router.get(route("usage-records.create"));
};
</script>

<template>
    <Layout
        heading="Usage Records"
        :hasButton="true"
        buttonName="Create New Record"
        :handleClick="handleClick"
    >
    </Layout>
</template>
