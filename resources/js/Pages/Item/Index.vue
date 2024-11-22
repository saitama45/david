<script setup>
import { useSearch } from "@/Composables/useSearch";
const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
});

import { router } from "@inertiajs/vue3";
const handleClick = () => {
    router.get(route("items.create"));
};

const { search } = useSearch("items.index");
</script>

<template>
    <Layout
        heading="Products List"
        :hasButton="true"
        buttonName="Create New Product"
        :handleClick="handleClick"
    >
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        v-model="search"
                        placeholder="Search..."
                    />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Code</TH>
                    <TH>Name</TH>
                    <TH>Unit</TH>
                </TableHead>

                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.InventoryID }}</TD>
                        <TD>{{ item.InventoryName }}</TD>
                        <TD>{{ item.Packaging }}</TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
