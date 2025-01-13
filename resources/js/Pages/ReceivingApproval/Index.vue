<script setup>
const props = defineProps({
    orders: {
        type: Object,
        required: true,
    },
});

import { useSearch } from "@/Composables/useSearch";

const { search } = useSearch("receiving-approvals.index");

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();
</script>

<template>
    <Layout heading="Received Orders For Approval List">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        v-model="search"
                        class="pl-10"
                        placeholder="Search..."
                    />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Supplier</TH>
                    <TH>Store Branch</TH>
                    <TH>Order Number</TH>
                    <TH v-if="hasAccess('view approved order for approval')"
                        >Actions</TH
                    >
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data">
                        <TD>{{ order.id }}</TD>
                        <TD>{{ order.supplier.name }}</TD>
                        <TD>{{ order.store_branch.name }}</TD>
                        <TD>{{ order.order_number }}</TD>
                        <TD
                            v-if="hasAccess('view approved order for approval')"
                        >
                            <ShowButton
                                :isLink="true"
                                :href="`/receiving-approvals/show/${order.order_number}`"
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <MobileTableContainer>
                <MobileTableRow v-for="order in orders.data">
                    <MobileTableHeading :title="order.order_number">
                        <ShowButton
                            v-if="hasAccess('view approved order for approval')"
                            :isLink="true"
                            :href="`/receiving-approvals/show/${order.order_number}`"
                        />
                    </MobileTableHeading>
                    <LabelXS
                        >Store Branch: {{ order.store_branch.name }}</LabelXS
                    >
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>
