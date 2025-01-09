<script setup>
import { router } from "@inertiajs/vue3";
const props = defineProps({
    roles: {
        type: Object,
        required: true,
    },
});
const createNewRole = () => {
    router.get(route("roles.create"));
};

import { useSearch } from "@/Composables/useSearch";

const { search } = useSearch("roles.index");
</script>

<template>
    <Layout
        heading="Roles"
        :hasButton="true"
        buttonName="Create New Role"
        :handleClick="createNewRole"
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
                    <tr v-for="role in roles.data">
                        <TD>{{ role.id }}</TD>
                        <TD>{{ role.name }}</TD>
                        <TD>
                            <section class="max-w-[400px] space-x-3 space-y-3">
                                <Badge
                                    v-for="permission in role.permissions"
                                    class="w-fit"
                                >
                                    {{ permission.name }}
                                </Badge>
                            </section>
                        </TD>
                        <TD>
                            <EditButton />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="roles" />
        </TableContainer>
    </Layout>
</template>
