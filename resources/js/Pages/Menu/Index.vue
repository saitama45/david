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

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

import { useReferenceDelete } from "@/Composables/useReferenceDelete";
const { deleteModel } = useReferenceDelete();
</script>

<template>
    <Layout
        heading="Menu List"
        :hasButton="hasAccess('create menu')"
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
                                <ShowButton
                                    v-if="hasAccess('show menu')"
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
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="menus" />
        </TableContainer>
    </Layout>
</template>
