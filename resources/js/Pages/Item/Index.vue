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

import { usePage } from "@inertiajs/vue3";

let filter = ref(usePage().props.filter || "all");

const { search } = useSearch("items.index");

const changeFilter = (currentFilter) => {
    filter.value = currentFilter;
};

watch(filter, function (value) {
    router.get(
        route("items.index"),
        { filter: value },
        {
            preserveState: true,
            replace: true,
        }
    );
});
</script>

<template>
    <Layout
        heading="Products List"
        :hasButton="true"
        buttonName="Create New Product"
        :handleClick="handleClick"
    >
        <FilterTab>
            <FilterTabButton
                label="All"
                filter="all"
                :currentFilter="filter"
                @click="changeFilter('all')"
            />
            <FilterTabButton
                label="With Cost"
                filter="with_cost"
                :currentFilter="filter"
                @click="changeFilter('with_cost')"
            />
            <FilterTabButton
                label="Without Cost"
                filter="without_cost"
                :currentFilter="filter"
                @click="changeFilter('without_cost')"
            />
        </FilterTab>
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
