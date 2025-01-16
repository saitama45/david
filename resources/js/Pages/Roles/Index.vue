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

import { useReferenceDelete } from "@/Composables/useReferenceDelete";
const { deleteModel } = useReferenceDelete();
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
            <Table class="sm:table hidden">
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
                            <section class="space-x-3 space-y-3">
                                <Badge
                                    v-for="permission in role.permissions"
                                    class="w-fit"
                                >
                                    {{ permission.name }}
                                </Badge>
                            </section>
                        </TD>
                        <TD
                            class="items-center flex"
                            v-if="hasAccess('edit roles')"
                        >
                            <EditButton
                                :isLink="true"
                                :href="route('roles.edit', role.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route('roles.destroy', role.id),
                                        'Role'
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <DivFlexCol class="sm:hidden gap-3">
                <DivFlexCol class="sm:hidden gap-3">
                    <DivFlexCol
                        class="rounded-lg border min-h-20 p-3"
                        v-for="role in roles.data"
                    >
                        <MobileTableHeading :title="role.name.toUpperCase()">
                            <EditButton
                                class="size-5"
                                v-if="hasAccess('edit roles')"
                                :isLink="true"
                                :href="`/roles/edit/${role.id}`"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route('roles.destroy', role.id),
                                        'Role'
                                    )
                                "
                            />
                        </MobileTableHeading>
                        <LabelXS class="text-[10px]">Roles</LabelXS>
                        <LabelXS>
                            {{
                                role.permissions
                                    .slice(0, 3)
                                    .map((perm) => perm.name)
                                    .join(", ") +
                                (role.permissions.length > 3
                                    ? ` and ${role.permissions.length - 3} more`
                                    : "")
                            }}
                        </LabelXS>
                    </DivFlexCol>
                </DivFlexCol>
            </DivFlexCol>
            <Pagination :data="roles" />
        </TableContainer>
    </Layout>
</template>
