<script setup>
import { router } from "@inertiajs/vue3";

const { records } = defineProps({
    records: {
        type: Object,
        required: true,
    },
});
const handleClick = () => {
    router.get(route("usage-records.create"));
};
</script>

<template>
    <Layout
        heading="Usage Records"
        :hasButton="true"
        buttonName="Create New Record"
        :handleClick="handleClick"
    >
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input class="pl-10" placeholder="Search..." />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Order Number</TH>
                    <TH>Store Branch</TH>
                    <TH>Transaction Date</TH>
                    <TH>Total Amount</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="record in records.data">
                        <TD>{{ record.id }}</TD>
                        <TD>{{ record.order_number }}</TD>
                        <TD>{{ record.branch.name }}</TD>
                        <TD>{{ record.transaction_date }}</TD>
                        <TD>{{ record.total_amount }}</TD>
                        <TD>
                            <ShowButton
                                :isLink="true"
                                :href="route('usage-records.show', record.id)"
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="records" />
        </TableContainer>
    </Layout>
</template>
