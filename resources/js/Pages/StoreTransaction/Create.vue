<script setup></script>
<template>
    <Layout heading="Create Store Transactions">
        <section class="grid grid-cols-3 gap-3">
            <Card>
                <CardHeader>
                    <CardTitle>Transaction Details</CardTitle>
                    <CardDescription
                        >Please input all the required fields.</CardDescription
                    >
                    <section class="space-y-3">
                        <InputContainer>
                            <LabelXS>Order Number</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Transaction Period</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Transaction Date</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Cashier Id</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Order Type</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Sub Total</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Tax Amount</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Service Charge</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Discount Type</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Discount Amount</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Total Amount</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Payment Type</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Remarks</LabelXS>
                            <Textarea />
                        </InputContainer>
                    </section>
                </CardHeader>
            </Card>
        </section>
    </Layout>
</template>
