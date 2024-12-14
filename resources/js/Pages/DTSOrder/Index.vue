<script setup>
import { router } from "@inertiajs/vue3";
const filter = ref("all");
const changeFilter = (currentFilter) => {};

const handleClick = () => {
    router.get("/dts-orders/create");
};
</script>
<template>
    <Layout
        heading="DTS Orders"
        :hasButton="true"
        :handleClick="handleClick"
        buttonName="Create New Order"
    >
        <FilterTab>
            <FilterTabButton
                label="All"
                filter="all"
                :currentFilter="filter"
                @click="changeFilter('all')"
            />
            <FilterTabButton
                label="Approved"
                filter="approved"
                :currentFilter="filter"
                @click="changeFilter('approved')"
            />
            <FilterTabButton
                label="Pending"
                filter="pending"
                :currentFilter="filter"
                @click="changeFilter('pending')"
            />
            <FilterTabButton
                label="Rejected"
                filter="rejected"
                :currentFilter="filter"
                @click="changeFilter('rejected')"
            />
        </FilterTab>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input id="search" placeholder="Search..." class="pl-10" />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Supplier</TH>
                    <TH>Store</TH>
                    <TH>Order #</TH>
                    <TH>Order Date</TH>
                    <TH>Order Placed Date</TH>
                    <TH>Order Approval Status</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody> </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
