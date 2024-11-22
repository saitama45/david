<script setup>
import { useSearch } from "@/Composables/useSearch";
import { useToast } from "primevue/usetoast";
import { watch } from "vue";
import { usePage } from "@inertiajs/vue3";
const toast = useToast();
const page = usePage();

console.log(page);

watch(
    () => page.props.flash,
    (flash) => {
        if (flash && flash.message) {
            toast.add({
                severity: flash.message.severity,
                summary: flash.message.summary,
                detail: flash.message.detail,
                life: 3000,
            });
        }
    },
    { immediate: true, deep: true }
);

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
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
