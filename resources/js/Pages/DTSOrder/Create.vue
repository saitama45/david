<script setup>
import { useForm } from "@inertiajs/vue3";
const { suppliers, items, branches, variant } = defineProps({
    suppliers: {
        type: Object,
        required: true,
    },
    items: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
    variant: {
        required: true,
        type: String,
    },
});

console.log(variant);

import { useSelectOptions } from "@/Composables/useSelectOptions";

const { options: itemsOption } = useSelectOptions(items);
const { options: suppliersOptions } = useSelectOptions(suppliers);
const { options: branchesOptions } = useSelectOptions(branches);

const orderForm = useForm({
    branch_id: null,
    supplier_id: Object.keys(suppliers)[0] + "",
    order_date: null,
    orders: [],
});

const allowedDays = ref([]);

const dayNameToNumber = {
    SUNDAY: 0,
    MONDAY: 1,
    TUESDAY: 2,
    WEDNESDAY: 3,
    THURSDAY: 4,
    FRIDAY: 5,
    SATURDAY: 6,
};

watch(
    () => orderForm.branch_id,
    (value) => {
        if (value) {
            axios
                .get(route("schedule.show", value), {
                    params: { variant: variant },
                })
                .then((response) => {
                    const days = response.data.map(
                        (item) => dayNameToNumber[item]
                    );
                    let daysOfWeek = [0, 1, 2, 3, 4, 5, 6];
                    allowedDays.value = daysOfWeek.filter(
                        (item) => !days.includes(item)
                    );
                    console.log(allowedDays.value);
                })
                .catch((err) => console.log(err));
        }
    }
);
const getNextSunday = () => {
    const today = new Date();
    const dayOfWeek = today.getDay();
    const daysUntilNextSunday = (7 - dayOfWeek) % 7 || 7;

    const nextSunday = new Date(today);
    nextSunday.setDate(today.getDate() + daysUntilNextSunday);
    return nextSunday;
};

//Proxy(Array) {0: 1, 1: 3, 2: 5}
</script>
<template>
    <Layout :heading="`DST Orders > ${variant.toUpperCase()} > Create`">
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
                                :options="branchesOptions"
                                optionLabel="label"
                                optionValue="value"
                                v-model="orderForm.branch_id"
                            >
                            </Select>
                            <FormError></FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Order Date" />
                            <DatePicker
                                v-model="orderForm.order_date"
                                showIcon
                                fluid
                                :disabledDays="allowedDays"
                                dateFormat="yy/mm/dd"
                                :showOnFocus="false"
                                :minDate="new Date()"
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
