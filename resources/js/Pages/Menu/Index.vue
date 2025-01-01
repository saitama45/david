<script setup>
import { router } from "@inertiajs/vue3";
const props = defineProps({
    menus: {
        type: Object,
        required: true,
    },
});

const createNewMenu = () => {
    router.get(route("menu-list.create"));
};
</script>

<template>
    <Layout
        heading="Menu List"
        :hasButton="true"
        :handleClick="createNewMenu"
        buttonName="Create New Menu"
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
                    <TH>Item</TH>
                    <TH>Category</TH>
                    <TH>Price</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="menu in menus.data" :key="menu.id">
                        <TD>{{ menu.id }}</TD>
                        <TD>{{ menu.name }}</TD>
                        <TD>{{ menu.category }}</TD>
                        <TD>{{ menu.price }}</TD>
                        <TD>
                            <DivFlexCenter class="gap-3">
                                <ShowButton />
                                <EditButton />
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="menus" />
        </TableContainer>
    </Layout>
</template>
