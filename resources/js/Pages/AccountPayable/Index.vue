<script setup>
defineProps({
    storeOrderItems: {
        type: Object,
        required: true,
    },
});
</script>
<template>
    <Layout heading="Account Payable">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Item</TH>
                    <TH>Item Code</TH>
                    <TH>Quantity</TH>
                    <TH>Price</TH>
                    <TH>Total</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in storeOrderItems.data">
                        <TD>{{ item.product_inventory.name }}</TD>
                        <TD>{{ item.product_inventory.inventory_code }}</TD>
                        <TD>
                            {{ item.quantity_received }}
                        </TD>
                        <TD>{{ item.product_inventory.cost }}</TD>
                        <TD>
                            {{
                                item.quantity_received *
                                item.product_inventory.cost
                            }}
                        </TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
