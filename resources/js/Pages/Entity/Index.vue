<script setup>
import { ref, computed } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import Modal from "@/components/Modal.vue";

const props = defineProps({
    entities: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

const isModalVisible = ref(false);
const isDeleteVisible = ref(false);
const editingId = ref(null);
const deleteTarget = ref(null);
const logoPreview = ref(null);
const roleSearch = ref("");
const search = ref("");

const form = useForm({
    name: "",
    code: "",
    is_active: true,
    logo: null,
    role_ids: [],
});

const isEditing = computed(() => editingId.value !== null);

const filteredEntities = computed(() => {
    const q = search.value.toLowerCase();
    return props.entities.filter(
        (e) =>
            !q ||
            e.name.toLowerCase().includes(q) ||
            (e.code || "").toLowerCase().includes(q)
    );
});

const filteredRoles = computed(() => {
    const q = roleSearch.value.toLowerCase();
    return props.roles.filter((r) => r.name.toLowerCase().includes(q));
});

const roleLabel = (name) =>
    name.replace(/(^|\s)\S/g, (s) => s.toUpperCase());

const resetForm = () => {
    form.reset();
    form.clearErrors();
    logoPreview.value = null;
    roleSearch.value = "";
};

const openCreate = () => {
    editingId.value = null;
    resetForm();
    isModalVisible.value = true;
};

const openEdit = (entity) => {
    editingId.value = entity.id;
    resetForm();
    form.name = entity.name;
    form.code = entity.code;
    form.is_active = !!entity.is_active;
    form.role_ids = (entity.roles || []).map((r) => r.id);
    logoPreview.value = entity.logo_url || null;
    isModalVisible.value = true;
};

const onLogoChange = (e) => {
    const file = e.target.files[0] || null;
    form.logo = file;
    logoPreview.value = file ? URL.createObjectURL(file) : logoPreview.value;
};

const toggleRole = (id) => {
    const i = form.role_ids.indexOf(id);
    if (i > -1) form.role_ids.splice(i, 1);
    else form.role_ids.push(id);
};

const allRolesSelected = computed(
    () =>
        filteredRoles.value.length > 0 &&
        filteredRoles.value.every((r) => form.role_ids.includes(r.id))
);

const toggleSelectAll = () => {
    const ids = filteredRoles.value.map((r) => r.id);
    if (allRolesSelected.value) {
        form.role_ids = form.role_ids.filter((id) => !ids.includes(id));
    } else {
        const set = new Set(form.role_ids);
        ids.forEach((id) => set.add(id));
        form.role_ids = Array.from(set);
    }
};

const save = () => {
    const opts = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: page.props.flash?.success || "Entity saved.",
                life: 4000,
            });
            isModalVisible.value = false;
            resetForm();
            editingId.value = null;
        },
    };
    if (isEditing.value) {
        form.post(route("entities.update", editingId.value), opts);
    } else {
        form.post(route("entities.store"), opts);
    }
};

const confirmDelete = (entity) => {
    deleteTarget.value = entity;
    isDeleteVisible.value = true;
};

const doDelete = () => {
    form.delete(route("entities.destroy", deleteTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Deleted",
                detail: page.props.flash?.success || "Entity deleted.",
                life: 4000,
            });
            isDeleteVisible.value = false;
            deleteTarget.value = null;
        },
        onError: (errors) => {
            toast.add({
                severity: "error",
                summary: "Cannot delete",
                detail: errors.message || "Unable to delete this entity.",
                life: 5000,
            });
            isDeleteVisible.value = false;
        },
    });
};
</script>

<template>
    <Layout
        heading="Entities"
        :hasButton="true"
        :handleClick="openCreate"
        buttonName="Create New Entity"
    >
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input class="pl-10" v-model="search" placeholder="Search entities..." />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH> Logo </TH>
                    <TH> Name </TH>
                    <TH> Code </TH>
                    <TH> Status </TH>
                    <TH> Roles </TH>
                    <TH> Branches </TH>
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="entity in filteredEntities" :key="entity.id">
                        <TD>
                            <img
                                v-if="entity.logo_url"
                                :src="entity.logo_url"
                                :alt="entity.name"
                                class="h-10 w-10 rounded object-contain border bg-white"
                            />
                            <div
                                v-else
                                class="h-10 w-10 rounded border bg-muted flex items-center justify-center text-xs text-muted-foreground"
                            >
                                {{ (entity.code || "?").slice(0, 3) }}
                            </div>
                        </TD>
                        <TD>{{ entity.name }}</TD>
                        <TD>{{ entity.code }}</TD>
                        <TD>
                            <span
                                :class="entity.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'"
                                class="px-2 py-0.5 rounded-full text-xs font-medium"
                            >
                                {{ entity.is_active ? "Active" : "Inactive" }}
                            </span>
                        </TD>
                        <TD>{{ entity.roles_count }}</TD>
                        <TD>{{ entity.store_branches_count }}</TD>
                        <TD>
                            <div class="flex items-center space-x-1">
                                <EditButton @click="openEdit(entity)" />
                                <DeleteButton @click="confirmDelete(entity)" />
                            </div>
                        </TD>
                    </tr>
                    <tr v-if="filteredEntities.length === 0">
                        <TD colspan="7" class="text-center text-muted-foreground py-6">
                            No entities found.
                        </TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>

        <!-- Create / Edit Modal -->
        <Modal :show="isModalVisible" @close="isModalVisible = false" maxWidth="2xl">
            <div class="p-6 space-y-5">
                <h2 class="text-lg font-semibold">
                    {{ isEditing ? "Edit Entity" : "Create Entity" }}
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs">Name</Label>
                        <Input v-model="form.name" placeholder="e.g. Nonos" />
                        <FormError>{{ form.errors.name }}</FormError>
                    </div>
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs">Code</Label>
                        <Input v-model="form.code" placeholder="e.g. NONOS" />
                        <FormError>{{ form.errors.code }}</FormError>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <div
                        class="h-16 w-16 rounded border bg-white flex items-center justify-center overflow-hidden shrink-0"
                    >
                        <img v-if="logoPreview" :src="logoPreview" class="h-full w-full object-contain" />
                        <span v-else class="text-xs text-muted-foreground">No logo</span>
                    </div>
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs">Logo (shown in sidebar)</Label>
                        <input type="file" accept="image/*" @change="onLogoChange" class="text-sm" />
                        <FormError>{{ form.errors.logo }}</FormError>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" v-model="form.is_active" />
                    Active
                </label>

                <div class="flex flex-col space-y-1">
                    <Label class="text-xs">Roles with access ({{ form.role_ids.length }} selected)</Label>
                    <p class="text-xs text-muted-foreground">
                        Any user with one of these roles can access this entity.
                    </p>
                    <Input v-model="roleSearch" placeholder="Search roles..." class="mb-1" />
                    <div class="border rounded-md max-h-48 overflow-y-auto divide-y">
                        <label
                            v-if="filteredRoles.length"
                            class="flex items-center gap-2 px-3 py-2 text-sm font-medium bg-muted/40 sticky top-0 cursor-pointer"
                        >
                            <input
                                type="checkbox"
                                :checked="allRolesSelected"
                                @change="toggleSelectAll"
                            />
                            <span class="flex-1">Select all{{ roleSearch ? " (filtered)" : "" }}</span>
                        </label>
                        <label
                            v-for="r in filteredRoles"
                            :key="r.id"
                            class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-muted/50 cursor-pointer"
                        >
                            <input
                                type="checkbox"
                                :checked="form.role_ids.includes(r.id)"
                                @change="toggleRole(r.id)"
                            />
                            <span class="flex-1 capitalize">{{ roleLabel(r.name) }}</span>
                        </label>
                        <div v-if="filteredRoles.length === 0" class="px-3 py-2 text-xs text-muted-foreground">
                            No roles found.
                        </div>
                    </div>
                    <FormError>{{ form.errors.role_ids }}</FormError>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <Button variant="outline" @click="isModalVisible = false">Cancel</Button>
                    <Button @click="save" :disabled="form.processing">
                        {{ isEditing ? "Save Changes" : "Create Entity" }}
                    </Button>
                </div>
            </div>
        </Modal>

        <!-- Delete Confirm Modal -->
        <Modal :show="isDeleteVisible" @close="isDeleteVisible = false" maxWidth="md">
            <div class="p-6 space-y-4">
                <h2 class="text-lg font-semibold">Delete Entity</h2>
                <p class="text-sm text-muted-foreground">
                    Are you sure you want to delete
                    <span class="font-medium text-foreground">{{ deleteTarget?.name }}</span>?
                    This cannot be undone. Entities that still own branches or records cannot be deleted.
                </p>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="isDeleteVisible = false">Cancel</Button>
                    <Button class="bg-red-600 hover:bg-red-700" @click="doDelete" :disabled="form.processing">
                        Delete
                    </Button>
                </div>
            </div>
        </Modal>
    </Layout>
</template>
