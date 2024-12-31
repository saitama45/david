<script setup>
const { record, itemsSold } = defineProps({
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
</script>

<template>
    <Layout heading="Record Details">
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
                    <LabelXS>Usage Date</LabelXS>
                    <SpanBold>{{ record.usage_date }}</SpanBold>
                </InputContainer>
            </Card>

            <TableContainer>
                <TableHeader>
                    <SpanBold>Sold Items</SpanBold>
                </TableHeader>
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
                <Pagination :data="itemsSold" />
            </TableContainer>

            <TableContainer>
                <TableHeader>
                    <SpanBold>Ingredients Used</SpanBold>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>Item</TH>
                        <TH>Name</TH>
                        <TH>Price</TH>
                        <TH>Quantity Used</TH>
                        <TH>UOM</TH>
                        <TH>Total Price</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="item in ingredients">
                            <TD>{{ item.inventory_code }}</TD>
                            <TD>{{ item.name }}</TD>
                            <TD>{{ item.cost }}</TD>
                            <TD>{{ item.total_quantity }}</TD>
                            <TD>{{ item.uom }}</TD>
                            <TD>{{
                                parseFloat(
                                    item.total_quantity * item.cost
                                ).toFixed(2)
                            }}</TD>
                            <TD>
                                <ShowButton />
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
            </TableContainer>
        </DivFlexCol>
    </Layout>
</template>
