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
        case "INCOMPLETE":
            return "bg-yellow-500 text-white";
        case "REJECTED":
            return "bg-red-400 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};

const { search } = useSearch("approved-orders.index");
const showOrderDetails = (id) => {
    console.log(id);
    router.get(`/approved-orders/show/${id}`);
};

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

const exportRoute = computed(() => {
    return route("approved-orders.export", {
        search: search.value,
    });
});
</script>

<template>
    <Layout
        heading="Approved Received Items"
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
                    <TH>Order Status</TH>
                    <TH v-if="hasAccess('view approved received item')"
                        >Actions</TH
                    >
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
                            <ShowButton
                                v-if="hasAccess('view approved received item')"
                                @click="showOrderDetails(order.order_number)"
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="order in orders.data">
                    <MobileTableHeading :title="order.order_number">
                        <ShowButton
                            v-if="hasAccess('view approved received item')"
                            @click="showOrderDetails(order.order_number)"
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
