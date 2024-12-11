<script setup>
defineProps({
    user: {
        type: Object,
        required: true,
    },
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
                        user.roles.map((role) => role.name).join(",")
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
            </section>
        </Card>
    </Layout>
</template>
