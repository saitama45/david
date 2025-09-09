<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import InputContainer from '@/components/form/InputContainer.vue';
import { Label } from '@/components/ui/label';

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
});

// Helper function to parse remarks
const getDetailFromRemark = (remark, detail) => {
    if (!remark) return 'N/A';
    const match = remark.match(new RegExp(`${detail}: (\\S+)`));
    return match ? match[1] : 'N/A';
};

const overallTotal = computed(() => {
    return props.order.store_order_items.reduce((sum, item) => sum + parseFloat(item.total_cost), 0);
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    // Add 'T00:00:00' to handle potential timezone issues with date-only strings
    return new Date(dateString + 'T00:00:00').toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

</script>

<template>
    <Layout :heading="`DTS Order: ${order.order_number}`">
        <div class="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>DTS Order Details</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <InputContainer>
                            <Label>Supplier</Label>
                            <p class="font-semibold text-sm">{{ order.supplier.name }}</p>
                        </InputContainer>
                        <InputContainer>
                            <Label>Order Number</Label>
                            <p class="font-semibold text-sm">{{ order.order_number }}</p>
                        </InputContainer>
                        <InputContainer>
                            <Label>Status</Label>
                            <p class="font-semibold text-sm capitalize">{{ order.order_status }}</p>
                        </InputContainer>
                        <InputContainer>
                            <Label>Encoded By</Label>
                            <p class="font-semibold text-sm">{{ order.encoder.name }}</p>
                        </InputContainer>
                        <InputContainer>
                            <Label>Main Order Date</Label>
                            <p class="font-semibold text-sm">{{ formatDate(order.order_date) }}</p>
                        </InputContainer>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Order Items</CardTitle></CardHeader>
                <CardContent>
                    <div class="space-y-6">
                        <div v-for="item in order.store_order_items" :key="item.id" class="p-4 border rounded-lg shadow-sm bg-gray-50">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
                                <!-- Row 1: Branch, Variant, Item -->
                                <InputContainer>
                                    <Label>Store Branch</Label>
                                    <p class="font-semibold text-sm">{{ order.store_branch.name }}</p>
                                </InputContainer>

                                <InputContainer>
                                    <Label>Variant</Label>
                                    <p class="font-semibold text-sm">{{ getDetailFromRemark(item.remarks, 'Variant') }}</p>
                                </InputContainer>

                                <InputContainer>
                                    <Label>Item</Label>
                                    <p class="font-semibold text-sm">{{ item.item_code }}</p>
                                </InputContainer>

                                <!-- Row 2: Date, UOM, Quantity, Cost -->
                                <InputContainer>
                                    <Label>Delivery Date</Label>
                                    <p class="font-semibold text-sm">{{ formatDate(getDetailFromRemark(item.remarks, 'Delivery Date')) }}</p>
                                </InputContainer>

                                <InputContainer>
                                    <Label>UOM</Label>
                                    <p class="font-semibold text-sm">{{ item.uom }}</p>
                                </InputContainer>

                                <InputContainer>
                                    <Label>Quantity</Label>
                                    <p class="font-semibold text-sm">{{ item.quantity_ordered }}</p>
                                </InputContainer>
                                
                                <InputContainer>
                                    <Label>Cost</Label>
                                    <p class="font-semibold text-sm">{{ formatCurrency(item.cost_per_quantity) }}</p>
                                </InputContainer>

                                <div class="md:col-span-3 flex items-end justify-end">
                                    <div class="text-sm font-semibold pt-2">Item Total: {{ formatCurrency(item.total_cost) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </CardContent>
                <CardFooter class="flex justify-between items-center mt-6 p-6 border-t">
                    <div class="text-lg font-bold">Overall Total: {{ formatCurrency(overallTotal) }}</div>
                    <Link :href="route('dts-orders.index')">
                        <Button variant="outline">Back to Orders</Button>
                    </Link>
                </CardFooter>
            </Card>
        </div>
    </Layout>
</template>