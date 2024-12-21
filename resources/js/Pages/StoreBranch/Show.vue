<script setup>
defineProps({
    branch: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout heading="Store Branch Details">
        <Card class="grid grid-cols-2 p-10 gap-5">
            <InputContainer>
                <InfolistLabel>Name</InfolistLabel>
                <SpanBold>{{ branch.name }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>Branch Code</InfolistLabel>
                <SpanBold>{{ branch.branch_code }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>Brand Name</InfolistLabel>
                <SpanBold>{{ branch.brand_name ?? "N/a" }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>Brand Code</InfolistLabel>
                <SpanBold>{{ branch.brand_code ?? "N/a" }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>Store Status</InfolistLabel>
                <SpanBold>{{ branch.store_status }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>TIN</InfolistLabel>
                <SpanBold>{{ branch.tin ?? "N/a" }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>Complete Address</InfolistLabel>
                <SpanBold>{{ branch.complete_address ?? "N/a" }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>Head Chef</InfolistLabel>
                <SpanBold>{{ branch.head_chef ?? "N/a" }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>Director of Operations</InfolistLabel>
                <SpanBold>{{ branch.director_operations ?? "N/a" }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>VP of Operations</InfolistLabel>
                <SpanBold>{{ branch.vp_operations ?? "N/a" }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>Store Representative</InfolistLabel>
                <SpanBold>{{ branch.store_representative ?? "N/a" }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>AOM</InfolistLabel>
                <SpanBold>{{ branch.aom ?? "N/a" }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>Point of Contact</InfolistLabel>
                <SpanBold>{{ branch.point_of_contact ?? "N/a" }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>Contact Number</InfolistLabel>
                <SpanBold>{{ branch.contact_number ?? "N/a" }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <InfolistLabel>Active Status</InfolistLabel>
                <SpanBold>{{
                    branch.is_active ? "Active" : "Inactive"
                }}</SpanBold>
            </InputContainer>
        </Card>
    </Layout>
</template>
