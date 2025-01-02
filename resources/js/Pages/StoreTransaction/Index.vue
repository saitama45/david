<script setup>
import { router } from "@inertiajs/vue3";
const createNewTransaction = () => {
    router.get(route("store-transactions.create"));
};
</script>
<template>
    <Layout
        heading="Store Transactions"
        :hasButton="true"
        buttonName="Create New Transaction"
        :handleClick="createNewTransaction"
    >
    </Layout>
</template>
