<script setup>
import { computed } from 'vue'; // Import computed

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
    // New prop for all available suppliers
    suppliers: {
        type: Object, // This will be an object like {id: name, ...} from Laravel's pluck
        required: true,
    },
});

// Convert the suppliers prop object into an array of values for length comparison
const allAvailableSuppliersCount = computed(() => {
    return Object.keys(props.suppliers).length;
});

// Computed property to check if all suppliers are assigned
const isAllSuppliersAssigned = computed(() => {
    return (
        props.user.suppliers.length > 0 && // Ensure there's at least one assigned
        props.user.suppliers.length === allAvailableSuppliersCount.value
    );
});
</script>

<template>
    <Layout heading="User Details">
        <Card class="p-5">
            <section class="grid grid-cols-2 gap-5">
                <InputContainer>
                    <LabelXS>First Name</LabelXS>
                    <SpanBold class="font-bold">{{ user.first_name }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Middle Name</LabelXS>
                    <SpanBold>{{ user.middle_name ?? "N/a" }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Last Name</LabelXS>
                    <SpanBold>{{ user.last_name }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Phone Number</LabelXS>
                    <SpanBold>{{ user.phone_number }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Email</LabelXS>
                    <SpanBold>{{ user.email }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Roles</LabelXS>
                    <SpanBold>{{
                        user.roles.map((role) => role.name).join(", ")
                    }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Is Active</LabelXS>
                    <SpanBold>{{ user.is_active ? "Yes" : "No" }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Remarks</LabelXS>
                    <SpanBold>{{ user.remarks ?? "N/a" }}</SpanBold>
                </InputContainer>
                <InputContainer class="col-span-2">
                    <LabelXS>Assigned Branches</LabelXS>
                    <SpanBold
                        v-if="
                            user.roles
                                .map((role) => role.name)
                                .includes('admin')
                        "
                    >
                        All Branches
                    </SpanBold>
                    <SpanBold v-else>
                        {{
                            user.store_branches
                                .map((branch) => branch.name)
                                .join(", ")
                        }}
                    </SpanBold>
                </InputContainer>

                <!-- Updated InputContainer for Assigned Suppliers -->
                <InputContainer class="col-span-2">
                    <LabelXS>Assigned Suppliers</LabelXS>
                    <SpanBold>
                        <template v-if="isAllSuppliersAssigned">
                            All Suppliers
                        </template>
                        <template v-else-if="user.suppliers.length > 0">
                            {{ user.suppliers.map((supplier) => supplier.name).join(", ") }}
                        </template>
                        <template v-else>
                            N/a
                        </template>
                    </SpanBold>
                </InputContainer>
            </section>
        </Card>
        <BackButton routeName="users.index" />
    </Layout>
</template>

