<script setup>
import { router } from "@inertiajs/vue3";
const createDirectReceiving = () => {
    router.visit(route("direct-receiving.create"));
};

defineProps({
    directReceivings: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout
        heading="Direct Receiving"
        :hasButton="true"
        buttonName="Create New"
        :handleClick="createDirectReceiving"
    >
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input class="pl-10" placeholder="Search..." />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>ID</TH>
                    <TH>Store Branch</TH>
                    <TH>Vendor</TH>
                    <TH>Date Needed</TH>
                    <TH>Status</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="directReceiving in directReceivings.data">
                        <TD>{{ directReceiving.id }}</TD>
                        <TD>{{ directReceiving.store_branch.name }}</TD>
                        <TD>{{ directReceiving.vendor }}</TD>
                        <TD>{{ directReceiving.date_needed }}</TD>
                        <TD>{{ directReceiving.status.toUpperCase() }}</TD>
                        <TD>
                            <ShowButton
                                :isLink="true"
                                :href="
                                    route('cash-pull-out.show', directReceiving.id)
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="directReceivings" />
        </TableContainer>
    </Layout>
</template>
