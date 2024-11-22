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
                    <TH>Id</TH>
                    <TH>Name</TH>
                    <TH>Inventory Code</TH>
                    <TH>Brand</TH>
                    <TH>Conversion</TH>
                    <TH>UOM</TH>
                    <TH>Cost</TH>
                    <TH>Actions</TH>
                </TableHead>

                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.id }}</TD>
                        <TD>{{ item.name }}</TD>
                        <TD>{{ item.inventory_code }}</TD>
                        <TD>{{ item.brand }}</TD>
                        <TD>{{ item.conversion }}</TD>
                        <TD>{{ item.unit_of_measurement.name }}</TD>
                        <TD>{{ item.cost }}</TD>
                        <TH>
                            <Button class="text-blue-500" variant="link">
                                <Pencil class="size-6" />
                            </Button>
                        </TH>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
