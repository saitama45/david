<script setup>
import { router } from "@inertiajs/vue3";
const handleClick = () => {
    router.get("/cash-pull-out/create");
};

defineProps({
    cashPullOuts: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout
        heading="Cash Pull Outs List"
        :hasButton="true"
        buttonName="Create New"
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
                    <TH>ID</TH>
                    <TH>Store Branch</TH>
                    <TH>Vendor</TH>
                    <TH>Date Needed</TH>
                    <TH>Status</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="cashPullOut in cashPullOuts.data">
                        <TD>{{ cashPullOut.id }}</TD>
                        <TD>{{ cashPullOut.store_branch.name }}</TD>
                        <TD>{{ cashPullOut.vendor }}</TD>
                        <TD>{{ cashPullOut.date_needed }}</TD>
                        <TD>{{ cashPullOut.status.toUpperCase() }}</TD>
                        <TD>
                            <ShowButton
                                :isLink="true"
                                :href="
                                    route('cash-pull-out.show', cashPullOut.id)
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="cashPullOuts" />
        </TableContainer>
    </Layout>
</template>
