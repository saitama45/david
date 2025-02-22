<script setup>
import { router } from "@inertiajs/vue3";
import { useSearch } from "@/Composables/useSearch";

const { search } = useSearch("menu-list.index");
const props = defineProps({
    menus: {
        type: Object,
        required: true,
    },
});

const createNewMenu = () => {
    router.get(route("menu-list.create"));
};

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

import { useReferenceDelete } from "@/Composables/useReferenceDelete";
const { deleteModel } = useReferenceDelete();

const exportRoute = route("menu-list.export", {
    search: search.value,
});
</script>

<template>
    <Layout
        heading="BOM List"
        :hasButton="hasAccess('create bom')"
        :handleClick="createNewMenu"
        buttonName="Create New BOM"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
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
                    <TH>Product Id</TH>
                    <TH>Remarks</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="menu in menus.data" :key="menu.id">
                        <TD>{{ menu.id }}</TD>
                        <TD>{{ menu.product_id }}</TD>
                        <TD>{{ menu.remarks }}</TD>
                        <TD>
                            <DivFlexCenter class="gap-3">
                                <ShowButton
                                    v-if="hasAccess('view bom')"
                                    :isLink="true"
                                    :href="route('menu-list.show', menu.id)"
                                />
                                <EditButton
                                    v-if="hasAccess('edit bom')"
                                    :isLink="true"
                                    :href="route('menu-list.edit', menu.id)"
                                />
                                <DeleteButton
                                    @click="
                                        deleteModel(
                                            route('menu-list.destroy', menu.id),
                                            'Menu'
                                        )
                                    "
                                />
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="menu in menus.data" :key="menu.id">
                    <MobileTableHeading :title="`${menu.name}`">
                        <ShowButton
                            v-if="hasAccess('view menu')"
                            :isLink="true"
                            :href="route('menu-list.show', menu.id)"
                        />
                        <EditButton
                            v-if="hasAccess('edit menu')"
                            :isLink="true"
                            :href="route('menu-list.edit', menu.id)"
                        />
                        <DeleteButton
                            @click="
                                deleteModel(
                                    route('menu-list.destroy', menu.id),
                                    'Menu'
                                )
                            "
                        />
                    </MobileTableHeading>
                    <LabelXS>Category: {{ menu.category }}</LabelXS>
                    <LabelXS>Price: {{ menu.price }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="menus" />
        </TableContainer>
    </Layout>
</template>
