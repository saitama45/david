<script setup>
import { Link, router } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { useAuth } from "@/composables/useAuth";
import { ref, watch } from "vue";
import { useReferenceRestore } from "@/composables/useReferenceRestore";
import { RotateCcw } from "lucide-vue-next";

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

const search = ref(props.filters.search);

watch(
    search,
    throttle(function (value) {
        router.get(
            route("users.deleted"),
            { search: value },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

const { restoreModel } = useReferenceRestore();

const formatDate = (value) => {
    if (!value) return "";
    const d = new Date(value);
    if (isNaN(d)) return value;
    return d.toLocaleString();
};
</script>

<template>
    <Layout heading="Deleted Users">
        <template #header-actions>
            <Link
                :href="route('users.index')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 h-9 px-4 py-2 bg-secondary text-secondary-foreground shadow-sm hover:bg-secondary/80"
            >
                Back to Users
            </Link>
        </template>

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
                    <TH> Deleted At</TH>
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-if="!users.data.length">
                        <TD colspan="6" class="text-center text-gray-400 py-6">
                            No deleted users.
                        </TD>
                    </tr>
                    <tr v-for="user in users.data" :key="user.id">
                        <TD>{{ user.id }}</TD>
                        <TD>{{ user.first_name + " " + user.last_name }}</TD>
                        <TD>{{ user.email }}</TD>
                        <TD>
                            <div v-if="user.roles.length" class="flex flex-wrap gap-2">
                                <template v-for="role in user.roles" :key="role.id">
                                    <Badge class="w-fit">
                                        {{ role.name }}
                                    </Badge>
                                </template>
                            </div>
                            <span v-else class="text-sm text-gray-400">No roles</span>
                        </TD>
                        <TD>{{ formatDate(user.deleted_at) }}</TD>
                        <TD>
                            <DivFlexCenter class="sm:gap-3">
                                <Button
                                    v-if="hasAccess('delete users')"
                                    severity="success"
                                    size="small"
                                    class="sm:text-normal text-xs gap-2"
                                    @click="
                                        restoreModel(
                                            route('users.restore', user.id),
                                            'user'
                                        )
                                    "
                                >
                                    <RotateCcw class="h-4 w-4" />
                                    Restore
                                </Button>
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <DivFlexCol class="sm:hidden gap-3">
                <DivFlexCol
                    v-if="!users.data.length"
                    class="rounded-lg border min-h-20 p-3 items-center justify-center text-gray-400"
                >
                    No deleted users.
                </DivFlexCol>
                <DivFlexCol
                    class="rounded-lg border min-h-20 p-3"
                    v-for="user in users.data"
                    :key="user.id"
                >
                    <MobileTableHeading
                        :title="user.first_name + ' ' + user.last_name"
                    >
                        <Button
                            v-if="hasAccess('delete users')"
                            severity="success"
                            size="small"
                            class="text-xs gap-2"
                            @click="
                                restoreModel(
                                    route('users.restore', user.id),
                                    'user'
                                )
                            "
                        >
                            <RotateCcw class="h-4 w-4" />
                            Restore
                        </Button>
                    </MobileTableHeading>
                    <LabelXS>{{ user.email }}</LabelXS>
                    <LabelXS>Deleted: {{ formatDate(user.deleted_at) }}</LabelXS>
                </DivFlexCol>
            </DivFlexCol>

            <Pagination :data="users" />
        </TableContainer>
    </Layout>
</template>
