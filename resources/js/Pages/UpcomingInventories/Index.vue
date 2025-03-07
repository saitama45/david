<script setup>
defineProps({
    inventories: {
        type: Object,
        required: true,
    },
});
</script>
<template>
    <Layout heading="Upcoming Inventories">
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
                    <tr v-for="inventory in inventories.data">
                        <TD>{{ inventory.product_inventory.name }}</TD>
                        <TD>{{
                            inventory.product_inventory.inventory_code
                        }}</TD>
                        <TD>
                            {{ inventory.quantity_commited }}
                        </TD>
                        <TD>{{ inventory.product_inventory.cost }}</TD>
                        <TD>
                            {{
                                inventory.quantity_commited *
                                inventory.product_inventory.cost
                            }}
                        </TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
