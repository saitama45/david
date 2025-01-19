<script setup>

const { transaction } = defineProps({
    transaction: {
        type: Object,
        required: true,
    },
});

const subtotals = computed(() => {
    return transaction.items.reduce(
        (acc, item) => ({
            lineTotal: acc.lineTotal + Number(item.line_total),
            netTotal: acc.netTotal + Number(item.net_total),
            totalDiscount: acc.totalDiscount + Number(item.discount),
        }),
        { lineTotal: 0, netTotal: 0, totalDiscount: 0 }
    );
});

</script>
<template>
    <Layout heading="Store Transaction Details">
        <Card class="grid grid-cols-2 gap-5 p-5">
            <InputContainer>
                <LabelXS>Branch</LabelXS>
                <SpanBold>{{ transaction.branch }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Lot Serial</LabelXS>
                <SpanBold>{{ transaction.lot_serial }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Date</LabelXS>
                <SpanBold>{{ transaction.date }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Posted</LabelXS>
                <SpanBold>{{ transaction.posted }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>TM#</LabelXS>
                <SpanBold>{{ transaction.tim_number }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Receipt Number</LabelXS>
                <SpanBold>{{ transaction.receipt_number }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Customer ID</LabelXS>
                <SpanBold>{{ transaction.customer_id }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Customer</LabelXS>
                <SpanBold>{{ transaction.customer }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Cancel Reason</LabelXS>
                <SpanBold>{{ transaction.cancel_reason }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Reference Number</LabelXS>
                <SpanBold>{{ transaction.reference_number }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Total Discount</LabelXS>
                <SpanBold>{{ subtotals.totalDiscount }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Total Line</LabelXS>
                <SpanBold>{{ subtotals.lineTotal }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Total Net</LabelXS>
                <SpanBold>{{ subtotals.netTotal }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Remarks</LabelXS>
                <SpanBold>{{ transaction.remarks }}</SpanBold>
            </InputContainer>
        </Card>
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Product Id</TH>
                    <TH>Name</TH>
                    <TH>Base Quantity</TH>
                    <TH>Quantity</TH>
                    <TH>Price</TH>
                    <TH>Discount</TH>
                    <TH>Line Total</TH>
                    <TH>Net Total</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in transaction.items">
                        <TD>{{ item.product_id }}</TD>
                        <TD>{{ item.name }}</TD>
                        <TD>{{ item.base_quantity }}</TD>
                        <TD>{{ item.quantity }}</TD>
                        <TD>{{ item.price }}</TD>
                        <TD>{{ item.discount }}</TD>
                        <TD>{{ item.line_total }}</TD>
                        <TD>{{ item.net_total }}</TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
        <BackButton />
    </Layout>
</template>
