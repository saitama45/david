<script setup>
import { useSearch } from "@/Composables/useSearch";

const props = defineProps({
    items: {
        type: Object,
    },
    filters: {
        type: Object,
        required: true,
    },
});

const { search } = useSearch("stocks.index");


</script>
<template>
    <Layout heading="Stocks">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        v-model="search"
                        class="pl-10"
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
                        <TD>{{ item.brand ?? "N/a" }}</TD>
                        <TD>{{ item.conversion }}</TD>
                        <TD>{{ item.unit_of_measurement.name }}</TD>
                        <TD>{{ item.cost }}</TD>
                        <TD>
                            <ShowButton
                                :isLink="true"
                                :href="`/stocks/show/${item.id}`"
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="item in items.data">
                    <MobileTableHeading
                        :title="`${item.name} (${item.inventory_code})`"
                    >
                        <ShowButton
                            :isLink="true"
                            :href="`/stocks/show/${item.id}`"
                        />
                    </MobileTableHeading>
                    <LabelXS>UOM: {{ item.unit_of_measurement.name }}</LabelXS>
                    <LabelXS>Cost: {{ item.cost }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
