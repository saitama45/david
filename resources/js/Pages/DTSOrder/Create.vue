<script setup>
import { useForm } from "@inertiajs/vue3";
const { suppliers, items } = defineProps({
    suppliers: {
        type: Object,
        required: true,
    },
    items: {
        type: Object,
        required: true,
    },
});

import { useSelectOptions } from "@/Composables/useSelectOptions";

const { options: itemsOption } = useSelectOptions(items);
const { options: suppliersOptions } = useSelectOptions(suppliers);

const orderForm = useForm({
    branch_id: null,
    supplier_id: Object.keys(suppliers)[0] + "",
    order_date: null,
    orders: [],
});
</script>
<template>
    <Layout heading="DST Orders > Create">
        <div class="grid sm:grid-cols-3 gap-5 grid-cols-1">
            <section class="grid gap-5">
                <Card>
                    <CardHeader>
                        <CardTitle>Order Details</CardTitle>
                        <CardDescription
                            >Please input all the fields</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Supplier" />
                            <Select
                                filter
                                placeholder="Select a Supplier"
                                :options="suppliersOptions"
                                optionLabel="label"
                                optionValue="value"
                                v-model="orderForm.supplier_id"
                            >
                            </Select>
                            <FormError></FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Store Branch" />
                            <Select
                                filter
                                placeholder="Select a Store"
                                optionLabel="label"
                                optionValue="value"
                            >
                            </Select>
                            <FormError></FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Order Date" />
                            <DatePicker
                                showIcon
                                fluid
                                dateFormat="yy/mm/dd"
                                :showOnFocus="false"
                            />
                            <FormError></FormError>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Add Item</CardTitle>
                        <CardDescription
                            >Please input all the fields</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex flex-col space-y-1">
                            <Label>Item</Label>
                            <Select
                                filter
                                placeholder="Select an Item"
                                optionLabel="label"
                                optionValue="value"
                                :options="itemsOption"
                            >
                            </Select>
                            <FormError></FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>Unit Of Measurement (UOM)</Label>
                            <Input type="text" disabled />
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>Cost</Label>
                            <Input type="text" disabled />
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>Quantity</Label>
                            <Input type="number" />
                            <FormError></FormError>
                        </div>
                    </CardContent>

                    <CardFooter class="flex justify-end">
                        <Button> Add to Orders </Button>
                    </CardFooter>
                </Card>
            </section>

            <Card class="col-span-2 flex flex-col">
                <CardHeader>
                    <CardTitle>Items List</CardTitle>
                </CardHeader>
                <CardContent class="flex-1">
                    <Table>
                        <TableHead>
                            <TH> Name </TH>
                            <TH> Code </TH>
                            <TH> Quantity </TH>
                            <TH> Unit </TH>
                            <TH> Cost </TH>
                            <TH> Total Cost </TH>
                            <TH> Action </TH>
                        </TableHead>
                        <TableBody> </TableBody>
                    </Table>
                </CardContent>
                <CardFooter class="flex justify-end">
                    <Button>Place Order</Button>
                </CardFooter>
            </Card>
        </div>
    </Layout>
</template>
