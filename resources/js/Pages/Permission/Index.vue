<script setup>
import { router } from "@inertiajs/vue3";
const props = defineProps({
    permissions: {
        type: Object,
        required: true,
    },
});
const createNewPermission = () => {
    router.get(route("permissions.create"));
};

import { useSearch } from "@/Composables/useSearch";

const { search } = useSearch("permissions.index");
</script>

<template>
    <Layout
        heading="Permissions"
        :hasButton="true"
        buttonName="Create New Permission"
        :handleClick="createNewPermission"
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
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="permission in permissions.data">
                        <TD>{{ permission.id }}</TD>
                        <TD>{{ permission.name }}</TD>
                        <TD>
                            <EditButton />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="permissions" />
        </TableContainer>
    </Layout>
</template>
