<script setup>
import { computed } from 'vue';
import Checkbox from "primevue/checkbox";

const props = defineProps({
    role: {
        type: Object,
        required: true,
    },
    permissions: {
        type: Object,
        required: true,
    },
});

const exportRoute = computed(() => 
    route("roles.export-role", { role: props.role.id })
);

// Create a Set of the role's permission IDs for efficient lookup
const rolePermissionIds = computed(() => {
    return new Set(props.role.permissions.map(p => p.id));
});

// Function to check if a permission is assigned to the role
const hasPermission = (permissionId) => {
    return rolePermissionIds.value.has(permissionId);
};

</script>

<template>
    <Layout
        :heading="`Role: ${role.name}`"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <Card class="p-5 space-y-5">
            <!-- Role Name Display -->
            <InputContainer>
                <LabelXS>Name</LabelXS>
                <h1 class="text-lg font-semibold">{{ role.name }}</h1>
            </InputContainer>

            <!-- Permissions Section -->
            <InputContainer>
                <LabelXS>Permissions</LabelXS>
                <div
                    class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5 mt-4"
                >
                    <!-- Loop through the main permission categories -->
                    <div
                        v-for="(subCategories, mainCategoryLabel) in permissions"
                        :key="mainCategoryLabel"
                        class="flex flex-col gap-3"
                    >
                        <!-- Display the main category name -->
                        <SpanBold class="text-xs text-blue-700">{{
                            mainCategoryLabel.toUpperCase().replace(/_/g, " ")
                        }}</SpanBold>

                        <!-- Loop through sub-categories within each main category -->
                        <div
                            v-for="(permissionList, subCategoryLabel) in subCategories"
                            :key="subCategoryLabel"
                            class="flex flex-col gap-2 pl-2 border-l border-gray-200"
                        >
                            <!-- Display the sub-category name -->
                            <SpanBold class="text-xs text-gray-800 mt-2">{{
                                subCategoryLabel
                            }}</SpanBold>

                            <!-- Loop through individual permissions -->
                            <div
                                class="flex items-center gap-3"
                                v-for="permission in permissionList"
                                :key="permission.id"
                            >
                                <!-- Read-only Checkbox for each permission -->
                                <Checkbox
                                    :inputId="`permission-${permission.id}`"
                                    :modelValue="hasPermission(permission.id)"
                                    :binary="true"
                                    disabled
                                    :class="{
                                        'checkbox-green': hasPermission(permission.id),
                                        'checkbox-red': !hasPermission(permission.id)
                                    }"
                                />
                                <label
                                    :for="`permission-${permission.id}`"
                                    class="text-xs text-gray-600"
                                >
                                    {{ permission.name }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </InputContainer>
        </Card>
        <BackButton />
    </Layout>
</template>

<style scoped>
:deep(.checkbox-green .p-checkbox-box) {
    border-color: #10B981 !important;
    background-color: #10B981 !important;
}

:deep(.checkbox-red .p-checkbox-box) {
    border-color: #EF4444 !important;
    background-color: #EF4444 !important;
}

/* Ensure the checkmark is visible on the green background */
:deep(.checkbox-green .p-checkbox-icon) {
    color: white !important;
}

/* For the red/unchecked box, we don't want a checkmark, but if one were to appear, it should be white */
:deep(.checkbox-red .p-checkbox-icon) {
    color: white !important;
}
</style>
