<script setup>
const props = defineProps({
    audits: {
        type: Object,
    },
});
</script>

<template>
    <Layout heading="Audits">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Audit Id</TH>
                    <TH>User</TH>
                    <TH>Event</TH>
                    <TH>Model</TH>
                    <TH>Date and Time</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="audit in audits.data">
                        <TD>{{ audit.id }}</TD>
                        <TD>{{ audit.user.full_name }}</TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
