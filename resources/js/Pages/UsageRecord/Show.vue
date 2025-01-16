<script setup>
const { record, itemsSold, ingredients } = defineProps({
    record: {
        type: Object,
        required: true,
    },
    itemsSold: {
        type: Object,
        required: true,
    },
    ingredients: {
        type: Object,
        required: true,
    },
});

const computeSoldItemsTotal = () => {
    return itemsSold.data
        .reduce((total, item) => total + item.quantity * item.menu.price, 0)
        .toFixed(2);
};

// const computeIngredientsTotal = () => {
//     return ingredients
//         .reduce((total, item) => total + item.total_quantity * item.cost, 0)
//         .toFixed(2);
// };
</script>

<template>
    <Layout heading="Usage Record Details">
        <DivFlexCol class="gap-5">
            <Card class="p-5 grid grid-cols-2 gap-5">
                <InputContainer>
                    <LabelXS>Record Id</LabelXS>
                    <SpanBold>{{ record.id }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Encoder</LabelXS>
                    <SpanBold
                        >{{ record.encoder.first_name }}
                        {{ record.encoder.last_name }}</SpanBold
                    >
                </InputContainer>
                <InputContainer>
                    <LabelXS>Branch</LabelXS>
                    <SpanBold>{{ record.branch.name }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Branch Code</LabelXS>
                    <SpanBold>{{ record.branch.branch_code }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Number</LabelXS>
                    <SpanBold>{{ record.order_number }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Transaction Period</LabelXS>
                    <SpanBold>{{ record.transaction_period }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Transaction Date</LabelXS>
                    <SpanBold>{{ record.transaction_date }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Cashier Id</LabelXS>
                    <SpanBold>{{ record.cashier_id }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Type</LabelXS>
                    <SpanBold>{{ record.order_type }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Sub Total</LabelXS>
                    <SpanBold>{{ record.sub_total }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Total Amount</LabelXS>
                    <SpanBold>{{ record.total_amount }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Tax Amount</LabelXS>
                    <SpanBold>{{ record.tax_amount ?? "None" }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Payment Type</LabelXS>
                    <SpanBold>{{ record.payment_type }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Discount Amount</LabelXS>
                    <SpanBold>{{ record.discount_amount ?? "None" }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Discount Type</LabelXS>
                    <SpanBold>{{ record.discount_type ?? "None" }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Service Charge</LabelXS>
                    <SpanBold>{{ record.service_charge ?? "None" }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Remarks</LabelXS>
                    <SpanBold>{{ record.remarks ?? "None" }}</SpanBold>
                </InputContainer>
            </Card>

            <TableContainer>
                <DivFlexCenter class="flex justify-between">
                    <SpanBold>Items Sold</SpanBold>
                    <DivFlexCenter class="gap-2">
                        <LabelXS> Overall Total:</LabelXS>
                        <SpanBold>{{ computeSoldItemsTotal() }}</SpanBold>
                    </DivFlexCenter>
                </DivFlexCenter>
                <Table>
                    <TableHead>
                        <TH>Id</TH>
                        <TH>Name</TH>
                        <TH>Price</TH>
                        <TH>Quantity</TH>
                        <TH>Total Price</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="item in itemsSold.data">
                            <TD>{{ item.menu.id }}</TD>
                            <TD>{{ item.menu.name }}</TD>
                            <TD>{{ item.menu.price }}</TD>
                            <TD>{{ item.quantity }}</TD>
                            <TD>{{
                                parseFloat(item.quantity * item.menu.price)
                            }}</TD>
                            <TD>
                                <ShowButton />
                            </TD>
                        </tr>
                    </TableBody>
                </Table>

                <MobileTableContainer>
                    <MobileTableRow v-for="item in itemsSold.data">
                        <MobileTableHeading :title="item.menu.name">
                            <ShowButton />
                        </MobileTableHeading>
                        <LabelXS>Quantity: {{ item.quantity }}</LabelXS>
                        <LabelXS>Price: {{ item.menu.price }}</LabelXS>
                        <LabelXS
                            >Total Price:
                            {{
                                parseFloat(item.quantity * item.menu.price)
                            }}</LabelXS
                        >
                    </MobileTableRow>
                </MobileTableContainer>

                <Pagination :data="itemsSold" />
            </TableContainer>

            <TableContainer>
                <DivFlexCenter class="flex justify-between">
                    <SpanBold>Ingredients Used</SpanBold>
                    <!-- <DivFlexCenter class="gap-2">
                        <LabelXS> Overall Total:</LabelXS>
                        <SpanBold>{{ computeIngredientsTotal() }}</SpanBold>
                    </DivFlexCenter> -->
                </DivFlexCenter>
                <Table>
                    <TableHead>
                        <TH>Item</TH>
                        <TH>Name</TH>
                        <!-- <TH>Price</TH> -->
                        <TH>Quantity Used</TH>
                        <TH>UOM</TH>
                        <!-- <TH>Total Price</TH> -->
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="item in ingredients">
                            <TD>{{ item.inventory_code }}</TD>
                            <TD>{{ item.name }}</TD>
                            <!-- <TD>{{ item.cost }}</TD> -->
                            <TD>{{ item.total_quantity }}</TD>
                            <TD>{{ item.uom }}</TD>
                            <!-- <TD>{{
                                parseFloat(
                                    item.total_quantity * item.cost
                                ).toFixed(2)
                            }}</TD> -->
                            <TD>
                                <ShowButton />
                            </TD>
                        </tr>
                    </TableBody>
                </Table>

                <MobileTableContainer>
                    <MobileTableRow v-for="item in ingredients">
                        <MobileTableHeading
                            :title="`${item.name} (${item.inventory_code})`"
                        >
                            <ShowButton />
                        </MobileTableHeading>
                        <LabelXS
                            >Quantity Used: {{ item.total_quantity }}</LabelXS
                        >
                        <LabelXS>UOM: {{ item.uom }}</LabelXS>
                    </MobileTableRow>
                </MobileTableContainer>
            </TableContainer>
        </DivFlexCol>
        <BackButton />
    </Layout>
</template>
