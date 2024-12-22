<script setup></script>

<template>
    <Layout heading="Delivery Schedules">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Store Branch</TH>
                    <TH>Loc Code</TH>
                    <TH>
                        <DivFlexCol class="justify-center items-center">
                            Normal Orders
                            <LabelXS class="text-[10px]"
                                >Sun (7:00 am) to Wed (7:00 am)</LabelXS
                            >
                        </DivFlexCol>
                    </TH>
                    <TH>
                        <DivFlexCol class="justify-center items-center">
                            Normal Orders
                            <LabelXS class="text-[10px]"
                                >Thurs (7:00 am) to Sat (7:00 am)</LabelXS
                            >
                        </DivFlexCol>
                    </TH>
                    <TH>
                        <DivFlexCol class="justify-center items-center">
                            DTS Orders
                            <LabelXS class="text-[10px]">Ice Cream</LabelXS>
                        </DivFlexCol>
                    </TH>
                    <TH>
                        <DivFlexCol class="justify-center items-center">
                            DTS Orders
                            <LabelXS class="text-[10px]">Salmon</LabelXS>
                        </DivFlexCol>
                    </TH>
                    <TH>
                        <DivFlexCol class="justify-center items-center">
                            DTS Orders
                            <LabelXS class="text-[10px]"
                                >Fruits and Vegetables</LabelXS
                            >
                        </DivFlexCol>
                    </TH>
                </TableHead>
            </Table>
        </TableContainer>
    </Layout>
</template>
