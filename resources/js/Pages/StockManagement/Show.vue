<script setup>
const { branches, history } = defineProps({
    branches: {
        type: Object,
        required: true,
    },
    history: {
        type: Object,
        required: true,
    },
});
</script>
<template>
    <Layout heading="Stock Details">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Quantity Used</TH>
                    <TH>Remarks</TH>
                    <TH>Created at</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="data in history.data">
                        <TD>{{ data.id }}</TD>
                        <TD>{{ data.quantity }}</TD>
                        <TD>{{ data.remarks ?? "None" }}</TD>
                        <TD>{{ data.created_at }}</TD>
                        <TD><ShowButton /></TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
