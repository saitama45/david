<script setup>
import InputContainer from "@/Components/form/InputContainer.vue";

const showAddItemModal = ref(true);
</script>

<template>
    <Layout heading="SO Items">
        <Card
            class="grid grid-cols-3 p-5 gap-5 overflow-auto overflow-x-auto scrollbar-thin scrollbar-track-gray-100 scrollbar-thumb-gray-300 hover:scrollbar-thumb-gray-400"
        >
            <DivFlexCol class="gap-3 min-w-[400px]">
                <DivFlexCol>
                    <LabelXS>Status:</LabelXS>
                    <SpanBold> ON PROCESS</SpanBold>
                </DivFlexCol>

                <DivFlexCenter class="justify-between">
                    <InputContainer>
                        <LabelXS>SO#:</LabelXS>
                        <SpanBold> SO4872384973829</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>SO Date: </LabelXS>
                        <DatePicker
                            showIcon
                            fluid
                            dateFormat="yy/mm/dd"
                            :manualInput="true"
                        />
                    </InputContainer>
                </DivFlexCenter>

                <DivFlexCenter class="justify-between">
                    <InputContainer>
                        <LabelXS>SI#:</LabelXS>
                        <SpanBold> SO4872384973829</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>SI Date: </LabelXS>
                        <DatePicker
                            showIcon
                            fluid
                            dateFormat="yy/mm/dd"
                            :manualInput="true"
                        />
                    </InputContainer>
                </DivFlexCenter>

                <InputContainer>
                    <LabelXS>Account Name:</LabelXS>
                    <Input />
                </InputContainer>

                <InputContainer>
                    <LabelXS>Terms:</LabelXS>
                    <Input />
                </InputContainer>

                <InputContainer>
                    <LabelXS>VAT Exclusive?</LabelXS>
                    <RadioGroup class="flex flex-center gap-2">
                        <DivFlexCenter class="gap-2">
                            <RadioGroupItem id="r1" value="default" />
                            <LabelXS>Yes</LabelXS>
                        </DivFlexCenter>
                        <DivFlexCenter class="gap-2">
                            <RadioGroupItem id="r1" value="" />
                            <LabelXS>No</LabelXS>
                        </DivFlexCenter>
                    </RadioGroup>
                </InputContainer>

                <InputContainer>
                    <LabelXS>Non-Vat?</LabelXS>
                    <RadioGroup class="flex flex-center gap-2">
                        <DivFlexCenter class="gap-2">
                            <RadioGroupItem id="r1" value="default" />
                            <LabelXS>Yes</LabelXS>
                        </DivFlexCenter>
                        <DivFlexCenter class="gap-2">
                            <RadioGroupItem id="r1" value="" />
                            <LabelXS>No</LabelXS>
                        </DivFlexCenter>
                    </RadioGroup>
                </InputContainer>

                <div class="grid grid-cols-2">
                    <LabelXS>Vatable Sales:</LabelXS>
                    <SpanBold>20,000.00</SpanBold>
                </div>

                <div class="grid grid-cols-2">
                    <LabelXS>Vat:</LabelXS>
                    <SpanBold>20,000.00</SpanBold>
                </div>

                <div class="grid grid-cols-2">
                    <LabelXS>Net Amount:</LabelXS>
                    <SpanBold>20,000.00</SpanBold>
                </div>

                <InputContainer>
                    <LabelXS>Less:</LabelXS>
                    <Input />
                </InputContainer>

                <div class="grid grid-cols-2">
                    <LabelXS>Net Sales:</LabelXS>
                    <SpanBold>20,000.00</SpanBold>
                </div>

                <InputContainer>
                    <LabelXS>Budget:</LabelXS>
                    <Input />
                </InputContainer>

                <InputContainer>
                    <LabelXS>PO No:</LabelXS>
                    <Input />
                </InputContainer>

                <InputContainer>
                    <LabelXS>Project Name:</LabelXS>
                    <Input />
                </InputContainer>

                <InputContainer>
                    <LabelXS>Note:</LabelXS>
                    <Input />
                </InputContainer>
            </DivFlexCol>

            <TableContainer class="col-span-2 h-fit">
                <DivFlexCenter class="gap-3">
                    <Button>Add Item</Button>
                    <Button>Sales Invoice</Button>
                    <Button>Sales Order</Button>
                </DivFlexCenter>
                <Table>
                    <TableHead>
                        <TH>Warehouse</TH>
                        <TH>Barcode</TH>
                        <TH>Item</TH>
                        <TH>Lot#/Serial#</TH>
                        <TH>Expiry Date</TH>
                        <TH>UOM</TH>
                        <TH>Qty</TH>
                        <TH>SRP</TH>
                        <TH>Discount</TH>
                        <TH>Is Free?</TH>
                        <TH>Total Amount</TH>
                        <TH>Net Amount</TH>
                        <TH>Actions</TH>
                    </TableHead>
                </Table>
            </TableContainer>
        </Card>

        <Dialog v-model:open="showAddItemModal">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Add Item</DialogTitle>
                    <DialogDescription>
                        Please input all the important details.
                    </DialogDescription>
                </DialogHeader>
                <DivFlexCol class="gap-3">
                    <InputContainer>
                        <LabelXS>Warehouse</LabelXS>
                        <Input />
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Item</LabelXS>
                        <Select></Select>
                    </InputContainer>
                    <section class="grid grid-cols-2 gap-3">
                        <DivFlexCol>
                            <LabelXS>On Process</LabelXS>
                            <SpanBold>0.00</SpanBold>
                        </DivFlexCol>
                        <DivFlexCol>
                            <LabelXS>Available SOH</LabelXS>
                            <SpanBold>10.00</SpanBold>
                        </DivFlexCol>
                    </section>
                    <InputContainer>
                        <LabelXS>Quantity</LabelXS>
                        <Input />
                    </InputContainer>
                    <RadioGroup>
                        <DivFlexCenter class="gap-2">
                            <LabelXS>Is Free?</LabelXS>
                            <RadioGroupItem id="r1" value="default" />
                        </DivFlexCenter>
                    </RadioGroup>
                    <section class="grid grid-cols-2 gap-3">
                        <InputContainer>
                            <LabelXS>SRP</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Discount</LabelXS>
                            <Input />
                        </InputContainer>
                    </section>
                    <InputContainer>
                        <LabelXS>Note</LabelXS>
                        <Textarea />
                    </InputContainer>
                    <Button>Add</Button>
                </DivFlexCol>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
