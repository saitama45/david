<script setup>
import { router } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { useAuth } from "@/composables/useAuth";
import Dialog from "primevue/dialog";
import { useSelectOptions } from "@/composables/useSelectOptions";
import { ref, watch, computed } from "vue"; // Ensure ref, watch, computed are imported

const { hasAccess } = useAuth();
const props = defineProps({
    users: {
        type: Object,
        required: true,
    },
    usersList: {
        type: Object,
        required: true,
    },
    rolesList: {
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
    isLoading.value = true;
    router.get("/users/create", { roleId: roleId.value });
};

const rolesOption = computed(() => {
    if (!props.rolesList) return [];
    return Object.entries(props.rolesList).map(([value, label]) => ({
        value: isNaN(Number(value)) ? value : Number(value),
        label: label,
    }));
});

const isTemplateModalVisible = ref(false);

const openTemplateModal = () => {
    isTemplateModalVisible.value = true;
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

import { useReferenceDelete } from "@/composables/useReferenceDelete";
const { deleteModel } = useReferenceDelete();
const roleId = ref(null);
const exportRoute = computed(() =>
    route("users.export", { search: search.value })
);

const isLoading = ref(false);
</script>

<template>
    <Layout
        heading="Users"
        :hasButton="hasAccess('create users')"
        buttonName="Create New User"
        :handleClick="openTemplateModal"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
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
                            <DivFlexCenter class="sm:gap-3">
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
                    <MobileTableHeading
                        :title="user.first_name + ' ' + user.last_name"
                    >
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
                    </MobileTableHeading>
                    <LabelXS>{{ user.email }}</LabelXS>
                </DivFlexCol>
            </DivFlexCol>

            <Pagination :data="users" />
        </TableContainer>

        <Dialog
            v-model:visible="isTemplateModalVisible"
            modal
            :style="{ width: '30rem' }"
        >
            <template #header>
                <DivFlexCol>
                    <SpanBold>Create New User</SpanBold>
                    <LabelXS
                        >Select a role for the new user</LabelXS
                    >
                </DivFlexCol>
            </template>

            <DivFlexCol>
                <InputContainer>
                    <LabelXS>Roles</LabelXS>
                    <Select
                        filter
                        placeholder="No Template"
                        v-model="roleId"
                        optionLabel="label"
                        optionValue="value"
                        :options="rolesOption"
                    />
                </InputContainer>

                <DivFlexCenter class="justify-end mt-5">
                    <Button :disabled="isLoading" @click="handleClick">
                        Continue
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DivFlexCenter>
            </DivFlexCol>
        </Dialog>
    </Layout>
</template>
