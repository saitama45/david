<script setup>
import { router } from "@inertiajs/vue3";
const props = defineProps({
    users: {
        type: Object,
        required: true,
    },
});
const handleClick = () => {
    router.get("/users/create");
};

console.log(props.users);
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
                    <Input class="pl-10" placeholder="Search..." />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH> Id </TH>
                    <TH> Name</TH>
                    <TH> Email</TH>
                    <TH> Role</TH>
                    <TH> Is Active</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="user in users.data">
                        <TD>{{ user.id }}</TD>
                        <TD>{{ user.name }}</TD>
                        <TD>{{ user.email }}</TD>
                        <TD>{{ user.role }}</TD>
                        <TD>{{ user.isActive ? "Yes" : "No" }}</TD>
                    </tr>
                </TableBody>
            </Table>

            <Pagination :data="users" />
        </TableContainer>
    </Layout>
</template>
