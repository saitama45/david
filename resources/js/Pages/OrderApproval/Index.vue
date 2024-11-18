<script setup>
import { Badge } from "@/components/ui/badge";
import { useSearch } from "@/Composables/useSearch";
import { router } from "@inertiajs/vue3";
const props = defineProps({
    orders: {
        type: Object,
    },
});

const statusBadgeColor = (status) => {
    switch (status) {
        case 1:
            return "bg-green-500 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};
const { search } = useSearch("orders-approval.index");

const showOrderDetails = (id) => {
    router.get(`/orders-approval/show/${id}`);
};
</script>
<template>
    <Layout heading="Orders For Approval List">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        v-model="search"
                        placeholder="Order Number Search"
                    />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH> Order Placed At </TH>
                    <TH> SO Date</TH>
                    <TH> SO Number</TH>
                    <TH> Total Item</TH>
                    <TH> Total Quantity </TH>
                    <TH> Created By</TH>
                    <TH> Vendor </TH>
                    <TH> Branch </TH>
                    <TH> Status </TH>
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data">
                        <TD>{{ order.OrderDate }}</TD>
                        <TD>{{ order.SODate }}</TD>
                        <TD>{{ order.SONumber }}</TD>
                        <TD>{{ order.Total_Item }}</TD>
                        <TD>{{ order.TOTALQUANTITY }}</TD>
                        <TD>{{ order.CreatedBy }}</TD>
                        <TD>{{ order.vendor?.Name ?? "N/a" }}</TD>
                        <TD>{{ order.branch?.Name ?? "N/a" }}</TD>
                        <TD>
                            <Badge
                                :class="statusBadgeColor(order.IsApproved)"
                                class="font-bold"
                                >{{
                                    order.IsApproved == 1
                                        ? "Approved"
                                        : "For Approval"
                                }}</Badge
                            >
                        </TD>
                        <TD>
                            <Button
                                variant="link"
                                @click="showOrderDetails(order.SONumber)"
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
