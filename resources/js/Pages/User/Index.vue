<script setup>
import { router } from "@inertiajs/vue3";
import { throttle } from "lodash";
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
</script>

<template>
    <Layout
        heading="Users"
        :hasButton="true"
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

            <Table>
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
                                    :isLink="true"
                                    :href="`/users/show/${user.id}`"
                                />
                                <EditButton
                                    :isLink="true"
                                    :href="`/users/edit/${user.id}`"
                                />
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <Pagination :data="users" />
        </TableContainer>

    </Layout>
</template>
