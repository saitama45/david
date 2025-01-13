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

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();
</script>

<template>
    <Layout
        heading="Store Transactions"
        :hasButton="hasAccess('create store transactions')"
        buttonName="Create New Transaction"
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
                                v-if="hasAccess('view store transaction')"
                                :isLink="true"
                                :href="route('usage-records.show', record.id)"
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="record in records.data">
                    <MobileTableHeading :title="record.order_number">
                        <ShowButton
                            v-if="hasAccess('view store transaction')"
                            :isLink="true"
                            :href="route('usage-records.show', record.id)"
                        />
                    </MobileTableHeading>
                    <LabelXS>Store Branch: {{ record.branch.name }}</LabelXS>
                    <LabelXS
                        >Transaction Date:
                        {{ record.transaction_date }}</LabelXS
                    >
                    <LabelXS>Total Amount: {{ record.total_amount }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="records" />
        </TableContainer>
    </Layout>
</template>
