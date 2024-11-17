<script setup>
import { Badge } from "@/components/ui/badge";
import { useSearch } from "@/Composables/useSearch";
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

const { search } = useSearch("orders-receiving.index");
</script>
<template>
    <Layout heading="Orders For Receiving List">
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
                    <TH> Store/Branch</TH>
                    <TH> SO Number</TH>
                    <TH> Order Date</TH>
                    <TH> Actual Received</TH>
                    <TH> Receiving Status </TH>
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data">
                        <TD>{{ order.branch?.Name ?? "N/a" }}</TD>
                        <TD>{{ order.SONumber }}</TD>
                        <TD>{{ order.OrderDate }}</TD>
                        <TD>{{ order.Total_Item }}</TD>
                        <TD>{{ order.TOTALQUANTITY }}</TD>
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
                            <Button variant="link">
                                <Eye />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <div
                v-if="orders.data.length !== 0"
                class="flex items-center justify-end gap-2"
            >
                <Component
                    v-for="link in orders.links"
                    :is="link.url ? 'Link' : 'span'"
                    :href="link.url"
                    v-html="link.label"
                    class="px-3 py-1 border border-gray-200 text-primary-font font-bold rounded-lg"
                    :class="{
                        'bg-primary text-white': link.active,
                        'hover:bg-primary/50 transition-colors transition-duration duration-300':
                            link.url,
                    }"
                />
            </div>
        </TableContainer>
    </Layout>
</template>
