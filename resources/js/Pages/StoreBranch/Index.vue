<script setup>
import { useForm, usePage } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { useReferenceDelete } from "@/composables/useReferenceDelete";
import { ref, computed, watch } from 'vue'; // Explicitly import ref and computed
import { throttle } from "lodash";

const isEditModalVisible = ref(false);

const toast = useToast();
const isLoading = ref(false);

const form = useForm({
    name: null,
    remarks: null,
});

const targetId = ref(null);

const store = () => {
    form.post(route("branches.update", targetId.value), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Category Details Updated Successfully.",
                life: 5000,
            });
            form.reset();
            isEditModalVisible.value = false;
        },
    });
};

const props = defineProps({
    data: {
        type: Object,
        required: true,
    },
    statusCounts: {
        type: Object,
        default: () => ({ active: 0, inactive: 0 }),
    },
});

// Search and the Active/Inactive cards reload together so neither drops the other.
const search = ref(usePage().props.filters?.search ?? "");
const status = ref(usePage().props.filters?.status ?? null);

const reload = () => {
    router.get(
        route("branches.index"),
        { search: search.value || undefined, status: status.value || undefined },
        { preserveState: true, replace: true }
    );
};

watch(search, throttle(reload, 500));

// Clicking the selected card again clears the filter.
const toggleStatus = (value) => {
    status.value = status.value === value ? null : value;
    reload();
};

// Use named route for editCategoryDetails
const editCategoryDetails = (id) => {
    router.get(route("branches.edit", id)); // Already correct
};

// FIX: Use named route for viewDetails
const viewDetails = (id) => {
    router.get(route("branches.show", id)); // Changed to use the named route
};

// FIX: Use named route for createNewStoreBranch
const createNewStoreBranch = () => {
    router.get(route("branches.create")); // Changed to use the named route
};

const { deleteModel } = useReferenceDelete();

// SQL Server returns is_active as "1"/"0" and the string "0" is truthy in JS.
const isActive = (branch) => Number(branch.is_active) === 1;

const exportRoute = computed(() =>
    route("branches.export", { search: search.value })
);
</script>

<template>
    <Layout
        heading="Store Branches"
        :hasButton="true"
        :handleClick="createNewStoreBranch"
        buttonName="Create New Store Branch"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <div class="mb-4 grid grid-cols-2 gap-4 sm:max-w-md">
            <button
                type="button"
                @click="toggleStatus('active')"
                :aria-pressed="status === 'active'"
                :class="status === 'active' ? 'ring-2 ring-green-500 border-green-400' : 'border-green-200 hover:border-green-400'"
                class="rounded-lg border bg-green-50 p-4 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-green-700">Active Stores</p>
                <p class="mt-1 text-2xl font-semibold text-green-900">{{ statusCounts.active }}</p>
            </button>
            <button
                type="button"
                @click="toggleStatus('inactive')"
                :aria-pressed="status === 'inactive'"
                :class="status === 'inactive' ? 'ring-2 ring-gray-500 border-gray-400' : 'border-gray-200 hover:border-gray-400'"
                class="rounded-lg border bg-gray-50 p-4 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-500"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-600">Inactive Stores</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ statusCounts.inactive }}</p>
            </button>
        </div>

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
                    <TH> Id </TH>
                    <TH> Name</TH>
                    <TH> Branch Code</TH>
                    <TH> Location Code</TH> <!-- Added Location Code -->
                    <TH> Active Status</TH>
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="branch in data.data" :key="branch.id">
                        <TD>{{ branch.id }}</TD>
                        <TD>{{ branch.name }}</TD>
                        <TD>{{ branch.branch_code }}</TD>
                        <TD>{{ branch.location_code ?? "N/a" }}</TD> <!-- Added Location Code -->
                        <TD>
                            <span
                                :class="isActive(branch) ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'"
                                class="px-2 py-0.5 rounded-full text-xs font-medium"
                            >
                                {{ isActive(branch) ? "Active" : "Inactive" }}
                            </span>
                        </TD>
                        <TD>
                            <ShowButton @click="viewDetails(branch.id)" />
                            <EditButton
                                @click="editCategoryDetails(branch.id)"
                            />
                            <DeleteButton
                                @click="
                                    deleteModel(
                                        route(
                                            'branches.destroy',
                                            branch.id
                                        ),
                                        'Branch'
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <MobileTableContainer>
                <MobileTableRow v-for="branch in data.data" :key="branch.id">
                    <MobileTableHeading :title="branch.name">
                        <ShowButton @click="viewDetails(branch.id)" />
                        <EditButton @click="editCategoryDetails(branch.id)" />
                    </MobileTableHeading>
                    <LabelXS>{{ branch.branch_code }}</LabelXS>
                    <LabelXS>{{ branch.location_code ?? "N/a" }}</LabelXS> <!-- Added Location Code for mobile -->
                    <LabelXS>Status: {{ isActive(branch) ? "Active" : "Inactive" }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="data" />
        </TableContainer>

        <Dialog v-model:open="isEditModalVisible">
            <DialogContent class="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Edit Category Details</DialogTitle>
                    <DialogDescription>
                        Input all important fields.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-5">
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs">Name</Label>
                        <Input v-model="form.name" />
                        <FormError>{{ form.errors.name }}</FormError>
                    </div>
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs">Remarks</Label>
                        <Textarea v-model="form.remarks" />
                        <FormError>{{ form.errors.remarks }}</FormError>
                    </div>
                    <div class="flex justify-end">
                        <Button @click="store" class="gap-2">
                            Save Changes
                            <span><Loading v-if="isLoading" /></span>
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
