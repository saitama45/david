<script setup>
defineProps({
    cashPullOut: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout heading="Direct Receiving Details">
        <Card class="p-5 grid sm:grid-cols-4 gap-5">
            <InputContainer>
                <LabelXS>ID </LabelXS>
                <SpanBold>{{ cashPullOut.id }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Store Branch </LabelXS>
                <SpanBold>{{ cashPullOut.store_branch.name }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Date Needed </LabelXS>
                <SpanBold>{{ cashPullOut.date_needed }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Vendor </LabelXS>
                <SpanBold>{{ cashPullOut.vendor }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Vendor Address </LabelXS>
                <SpanBold>{{ cashPullOut.vendor_address }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Status </LabelXS>
                <SpanBold>{{ cashPullOut.status.toUpperCase() }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Remarks </LabelXS>
                <SpanBold>{{ cashPullOut.remarks ?? "None" }}</SpanBold>
            </InputContainer>
        </Card>

        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Item Name</TH>
                    <TH>Code</TH>
                    <TH>UOM</TH>
                    <TH>Quantity</TH>
                    <TH>Cost</TH>
                    <TH>Total Cost</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in cashPullOut.cash_pull_out_items">
                        <TD>{{ item.product_inventory.name }}</TD>
                        <TD>{{ item.product_inventory.inventory_code }}</TD>
                        <TD>{{
                            item.product_inventory.unit_of_measurement.name
                        }}</TD>
                        <TD>{{ item.product_inventory.cost }}</TD>
                        <TD>{{ item.quantity_ordered }}</TD>
                        <TD>{{
                            item.product_inventory.cost * item.quantity_ordered
                        }}</TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>

        <BackButton />
    </Layout>
</template>
