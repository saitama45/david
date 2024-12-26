<script setup>
import { useSearch } from "@/Composables/useSearch";
const { search } = useSearch("fruits-and-vegetables.index");
const { items } = defineProps({
    items: {
        type: Object,
        required: true,
    },
});

</script>

<template>
    <Layout heading="Fruits And Vegetables Orders">
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
                    <TH>Item</TH>
                    <TH>Monday</TH>
                    <TH>Tuesday</TH>
                    <TH>Wednesday</TH>
                    <TH>Thursday</TH>
                    <TH>Friday</TH>
                    <TH>Saturday</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.inventory_code }}</TD>
                        <TD>{{ item.name }}</TD>
                        <TD>{{ item.quantity_ordered.monday }}</TD>
                        <TD>{{ item.quantity_ordered.tuesday }}</TD>
                        <TD>{{ item.quantity_ordered.wednesday }}</TD>
                        <TD>{{ item.quantity_ordered.thursday }}</TD>
                        <TD>{{ item.quantity_ordered.friday }}</TD>
                        <TD>{{ item.quantity_ordered.saturday }}</TD>
                        <TD
                            ><ShowButton
                                :isLink="true"
                                :href="`/fruits-and-vegetables/show/${item.inventory_code}`"
                        /></TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
