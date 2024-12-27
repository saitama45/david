<script setup>
import { useSearch } from "@/Composables/useSearch";
const { search } = useSearch("fruits-and-vegetables.index");
import { router } from "@inertiajs/vue3";
const { items, datesOption, filters } = defineProps({
    items: {
        type: Object,
        required: true,
    },
    datesOption: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
});

const defaultSelectedDate =
    datesOption.length > 0 ? datesOption[1]["code"] : null;
const selectedDate = ref(filters.start_date_filter || datesOption[1]["code"]);

watch(selectedDate, function (value) {
    console.log(value);
    router.get(
        route("fruits-and-vegetables.index"),
        { start_date_filter: value },
        {
            preserveState: false,
            replace: true,
        }
    );
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

                <Select
                    v-model="selectedDate"
                    :options="datesOption"
                    placeholder="No Available Options"
                    class="min-w-96 w-fit"
                    optionLabel="name"
                    optionValue="code"
                />
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
