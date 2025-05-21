<script setup>
defineProps({
    wip: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout heading="WIP Details">
        <Card class="p-5">
            <section class="grid grid-cols-2 gap-5">
                <InputContainer>
                    <LabelXS>ID</LabelXS>
                    <SpanBold>{{ wip.id }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>SAP Code</LabelXS>
                    <SpanBold>{{ wip.sap_code }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Name</LabelXS>
                    <SpanBold>{{ wip.name }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Remarks</LabelXS>
                    <SpanBold>{{ wip.remarks ?? "N/a" }}</SpanBold>
                </InputContainer>
            </section>
        </Card>

        <Card class="p-5">
            <section>
                <LabelXS>Ingredients</LabelXS>
                <Table class="mt-2">
                    <TableHead>
                        <TH>ID</TH>
                        <TH>Inventory Code</TH>
                        <TH>Name</TH>
                        <TH>UOM</TH>
                        <TH>Quantity</TH>
                    </TableHead>
                    <TableBody>
                        <tr
                            v-for="ingredient in wip.wip_ingredients"
                            :key="ingredient.id"
                        >
                            <TD>{{ ingredient.id }}</TD>
                            <TD>{{ ingredient.product.inventory_code }}</TD>
                            <TD>{{ ingredient.product.name }}</TD>
                            <TD>{{ ingredient.unit }}</TD>
                            <TD>{{ ingredient.quantity }}</TD>
                        </tr>
                    </TableBody>
                </Table>
            </section>
        </Card>
    </Layout>
</template>
