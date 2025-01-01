<script setup>
const props = defineProps({
    menus: {
        type: Object,
        required: true,
    },
});

console.log(props.menus);
</script>

<template>
    <Layout heading="Menu List">
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
                            <EditButton />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="menus" />
        </TableContainer>
    </Layout>
</template>
