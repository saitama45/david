<script setup>

</script>

<template>
    <Layout heading="Delivery Schedules">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Store Branch</TH>
                    <TH>Loc Code</TH>
                    <TH>
                        <DivFlexCol>
                            Normal Orders
                            <LabelXS class="text-[10px]"
                                >Sun (7:00 am) to Wed (7:00 am)</LabelXS
                            >
                        </DivFlexCol>
                    </TH>
                    <TH>
                        <DivFlexCol>
                            Normal Orders
                            <LabelXS class="text-[10px]"
                                >Thurs (7:00 am) to Sat (7:00 am)</LabelXS
                            >
                        </DivFlexCol>
                    </TH>
                    <TH>
                        <DivFlexCol>
                            DTS Orders
                            <LabelXS class="text-[10px]">Ice Cream</LabelXS>
                        </DivFlexCol>
                    </TH>
                    <TH>
                        <DivFlexCol>
                            DTS Orders
                            <LabelXS class="text-[10px]">Salmon</LabelXS>
                        </DivFlexCol>
                    </TH>
                    <TH>
                        <DivFlexCol>
                            DTS Orders
                            <LabelXS class="text-[10px]"
                                >Fruits and Vegetables</LabelXS
                            >
                        </DivFlexCol>
                    </TH>
                </TableHead>

                <TableBody>
                    <tr>
                        <TD>SM BICUTAN</TD>
                        <TD>SMB</TD>
                        <TD>
                            <DivFlexCol class="gap-1">
                                <Badge class="w-fit">SUNDAY</Badge>
                                <Badge class="w-fit">MONDAY</Badge>
                                <Badge class="w-fit">TUESDAY</Badge>
                                <Badge class="w-fit">WEDNESDAY</Badge>
                            </DivFlexCol>
                        </TD>
                        <TD>
                            <DivFlexCol class="gap-1">
                                <Badge class="w-fit">THURSDAY</Badge>
                                <Badge class="w-fit">FRIDAY</Badge>
                                <Badge class="w-fit">SATURDAY</Badge>
                            </DivFlexCol>
                        </TD>
                        <TD>
                            <DivFlexCol class="gap-1">
                                <Badge class="w-fit">MONDAY</Badge>
                                <Badge class="w-fit">WEDNESDAY</Badge>
                                <Badge class="w-fit">FRIDAY</Badge>
                            </DivFlexCol>
                        </TD>
                        <TD>
                            <DivFlexCol class="gap-1">
                                <Badge class="w-fit">THURSDAY</Badge>
                                <Badge class="w-fit">SATURDAY</Badge>
                            </DivFlexCol>
                        </TD>
                        <TD>No Schedule</TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
