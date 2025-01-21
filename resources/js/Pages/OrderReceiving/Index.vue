<script setup>
import { useSearch } from "@/Composables/useSearch";
import { router } from "@inertiajs/vue3";

const props = defineProps({
    orders: {
        type: Object,
    },
});

const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "RECEIVED":
            return "bg-green-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        default:
            return "bg-orange-500 text-white";
    }
};

const viewDetails = (id) => {
    router.get(`/orders-receiving/show/${id}`);
};
const { search } = useSearch("orders-receiving.index");

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

const exportRoute = route("orders-receiving.export", {
    search: search.value,
});
</script>

<template>
    <Layout
        heading="Approved Orders"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        v-model="search"
                        placeholder="Search..."
                    />
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
                    <TH>Receiving Status</TH>
                    <TH v-if="hasAccess('view approved order')">Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data">
                        <TD>{{ order.id }}</TD>
                        <TD>{{ order.supplier?.name ?? "N/A" }}</TD>
                        <TD>{{ order.store_branch?.name ?? "N/A" }}</TD>
                        <TD>{{ order.order_number }}</TD>
                        <TD>{{ order.order_date }}</TD>
                        <TD>{{ order.created_at }}</TD>
                        <TD>
                            <Badge
                                :class="statusBadgeColor(order.order_status)"
                                class="font-bold"
                                >{{
                                    order.order_status
                                        .toUpperCase()
                                        .replace("_", " ")
                                }}</Badge
                            >
                        </TD>
                        <TD>
                            <Button
                                v-if="hasAccess('view approved order')"
                                variant="outline"
                                @click="viewDetails(order.order_number)"
                            >
                                <Eye />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="order in orders.data">
                    <MobileTableHeading :title="order.order_number">
                        <ShowButton
                            v-if="hasAccess('view approved order')"
                            @click="viewDetails(order.order_number)"
                        />
                    </MobileTableHeading>
                    <LabelXS
                        >Receiving Status:
                        {{ order.order_status.toUpperCase() }}</LabelXS
                    >
                    <LabelXS>Order Date: {{ order.order_date }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>
