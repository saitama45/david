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
                    <TH>Quantity</TH>
                    <TH>Action</TH>
                    <TH>Remarks</TH>
                    <TH>Created at</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="data in history.data">
                        <TD>{{ data.id }}</TD>
                        <TD
                            ><span v-if="data.action == 'log_usage'">-</span
                            >{{ data.quantity }}</TD
                        >
                        <TD>{{
                            data.action.replace(/_/g, " ").toUpperCase()
                        }}</TD>
                        <TD>{{ data.remarks ?? "None" }}</TD>
                        <TD>{{ data.created_at }}</TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="history" />
        </TableContainer>
        <BackButton routeName="stock-management.index" />
    </Layout>
</template>
