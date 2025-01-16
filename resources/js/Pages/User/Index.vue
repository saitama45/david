<script setup>
import { router } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();
const props = defineProps({
    users: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
});

let filter = ref(props.filters.search);
const search = ref(filter.value);
const handleClick = () => {
    router.get("/users/create");
};

watch(
    search,
    throttle(function (value) {
        router.get(
            route("users.index"),
            { search: value },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

import { useReferenceDelete } from "@/Composables/useReferenceDelete";
const { deleteModel } = useReferenceDelete();
</script>

<template>
    <Layout
        heading="Users"
        :hasButton="hasAccess('create users')"
        buttonName="Create New User"
        :handleClick="handleClick"
    >
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

            <Table class="sm:table hidden">
                <TableHead>
                    <TH> Id </TH>
                    <TH> Full Name</TH>
                    <TH> Email</TH>
                    <TH> Roles</TH>
                    <TH> Is Active</TH>
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="user in users.data">
                        <TD>{{ user.id }}</TD>
                        <TD>{{ user.first_name + " " + user.last_name }}</TD>
                        <TD>{{ user.email }}</TD>
                        <TD>{{
                            user.roles.map((role) => role.name).join(",")
                        }}</TD>
                        <TD>{{ user.is_active == 1 ? "Yes" : "No" }}</TD>
                        <TD>
                            <DivFlexCenter class="gap-3">
                                <ShowButton
                                    v-if="hasAccess('view user')"
                                    :isLink="true"
                                    :href="`/users/show/${user.id}`"
                                />
                                <EditButton
                                    v-if="hasAccess('edit users')"
                                    :isLink="true"
                                    :href="`/users/edit/${user.id}`"
                                />
                                <DeleteButton
                                    @click="
                                        deleteModel(
                                            route('users.destroy', user.id),
                                            'user'
                                        )
                                    "
                                />
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <DivFlexCol class="sm:hidden gap-3">
                <DivFlexCol
                    class="rounded-lg border min-h-20 p-3"
                    v-for="user in users.data"
                >
                    <DivFlexCenter class="justify-between">
                        <SpanBold class="text-xs"
                            >{{ user.first_name }}
                            {{ user.last_name }}</SpanBold
                        >
                        <EditButton
                            class="size-5"
                            v-if="hasAccess('edit users')"
                            :isLink="true"
                            :href="`/users/edit/${user.id}`"
                        />
                    </DivFlexCenter>
                    <LabelXS>{{ user.email }}</LabelXS>
                </DivFlexCol>
            </DivFlexCol>

            <Pagination :data="users" />
        </TableContainer>
    </Layout>
</template>
