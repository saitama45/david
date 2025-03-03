<script setup>
import { router } from "@inertiajs/vue3";
const handleClick = () => {
    router.get("/cash-pull-out/create");
};
</script>

<template>
    <Layout
        heading="Cash Pull Outs List"
        :hasButton="true"
        buttonName="Create New"
        :handleClick="handleClick"
    ></Layout>
</template>
