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
        case "PARTIALLY_RECEIVED":
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
</script>

<template>
    <Layout heading="Approved Orders">
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
                    <TH>Actions</TH>
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
                                variant="outline"
                                @click="showOrderDetails(order.order_number)"
                            >
                                <Eye />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>
