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
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="audit in audits.data">
                        <TD>{{ audit.id }}</TD>
                        <TD>{{
                            audit.user.first_name + " " + audit.user.last_name
                        }}</TD>
                        <TD>{{ audit.event }}</TD>
                        <TD>{{ audit.auditable_type }}</TD>
                        <TD>{{ audit.created_at }}</TD>
                        <TD>
                            <ShowButton />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="audits" />
        </TableContainer>
    </Layout>
</template>
