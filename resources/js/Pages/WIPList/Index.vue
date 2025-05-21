<script setup>
import { router } from "@inertiajs/vue3";
import { useSearch } from "@/Composables/useSearch";

const { search } = useSearch("wip-list.index");

const props = defineProps({
    wips: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout heading="WIP List">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Name</TH>
                    <TH>Remarks</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="wip in wips.data" :key="wip.id">
                        <TD>{{ wip.id }}</TD>
                        <TD>{{ wip.name }}</TD>
                        <TD>{{ wip.remarks ?? "none" }}</TD>
                        <TD></TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
