<script setup>
import { router } from "@inertiajs/vue3";
const openCreatePage = () => {
    router.get("/sales-orders/create");
};
</script>

<template>
    <Layout
        heading="Sales"
        :hasButton="true"
        buttonName="Create Sales Order"
        :handleClick="openCreatePage"
    >
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input id="search" placeholder="Search..." class="pl-10" />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Sales Order Number</TH>
                    <TH>Sales Order Date</TH>
                    <TH>Sales Invoice Number</TH>
                    <TH>Sales Invoice Date</TH>
                    <TH>Status</TH>
                    <TH>Actions</TH>
                </TableHead>
            </Table>
        </TableContainer>
    </Layout>
</template>
