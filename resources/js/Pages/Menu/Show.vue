<script setup>
const props = defineProps({
    menu: {
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
    <Layout heading="Menu Details">
        <DivFlexCol class="gap-5">
            <Card class="grid grid-cols-2 p-5 gap-5">
                <InputContainer>
                    <LabelXS>Id</LabelXS>
                    <SpanBold>{{ menu.id }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Name</LabelXS>
                    <SpanBold>{{ menu.name }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Price</LabelXS>
                    <SpanBold>{{ menu.price }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Remarks</LabelXS>
                    <SpanBold>{{ menu.remarks ?? "N/a" }}</SpanBold>
                </InputContainer>
            </Card>
            <TableContainer>
                <TableHeader>
                    <SpanBold>Ingredients</SpanBold>
                </TableHeader>
                <Table>
                    <Thead>
                        <TH>Inventory Code</TH>
                        <TH>Name</TH>
                        <TH>Quantity</TH>
                        <TH>UOM</TH>
                    </Thead>
                    <Tbody>
                        <Tr
                            v-for="ingredient in ingredients"
                            :key="ingredient.id"
                        >
                            <TD>{{ ingredient.inventory_code }}</TD>
                            <TD>{{ ingredient.name }}</TD>
                            <TD>{{ ingredient.quantity }}</TD>
                            <TD>{{ ingredient.uom }}</TD>
                        </Tr>
                    </Tbody>
                </Table>
            </TableContainer>
        </DivFlexCol>
    </Layout>
</template>
