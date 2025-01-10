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
import TD from "@/Components/table/TD.vue";

const { search } = useSearch("roles.index");

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();
</script>

<template>
    <Layout
        heading="Roles"
        :hasButton="hasAccess('create roles')"
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
                    <TH>Permissions</TH>
                    <TH v-if="hasAccess('edit roles')">Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="role in roles.data" class="h-full">
                        <TD>
                            <DivFlexCenter class="h-full">
                                {{ role.id }}
                            </DivFlexCenter>
                        </TD>
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
                        <TD v-if="hasAccess('edit roles')">
                            <EditButton
                                :isLink="true"
                                :href="route('roles.edit', role.id)"
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="roles" />
        </TableContainer>
    </Layout>
</template>
