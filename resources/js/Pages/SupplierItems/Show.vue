<script setup>
import CardContent from "@/Components/ui/card/CardContent.vue";
// Ensure other UI components are imported if they are not globally registered
// For example, if Label, Card, CardHeader, CardTitle, Button are custom components:
// import Label from "@/Components/ui/label/Label.vue";
// import Card from "@/Components/ui/card/Card.vue";
// import CardHeader from "@/Components/ui/card/CardHeader.vue";
// import CardTitle from "@/Components/ui/card/CardTitle.vue";
// import Button from "@/Components/ui/button/Button.vue";
// import CardDescription from "@/Components/ui/card/CardDescription.vue"; // If used

import { useBackButton } from "@/Composables/useBackButton";

// Initialize back button functionality
const { backButton } = useBackButton(route("SupplierItems.index"));

// Define props for the component
const { item } = defineProps({
    item: {
        type: Object,
        required: true,
    },
});

// Helper function to format numbers as currency/decimals
const formatNumber = (value) => {
    return value !== null && value !== undefined ? Number(value).toFixed(2) : "N/a";
};

// Helper function to display active status
const displayIsActive = (value) => {
    return value == 1 ? "Yes" : "No";
};
</script>

<template>
    <Layout heading="Supplier Items Details">
        <section class="grid sm:grid-cols-1">
            <Card>
                <CardHeader>
                    <CardTitle class="text-xl">
                        <Label class="font-bold">Item Code:</Label> {{ item.ItemCode ?? "N/a" }}
                    </CardTitle>
                    <CardDescription>
                        Details for Supplier Code: <Label class="font-bold">{{ item.SupplierCode ?? "N/a" }}</Label>
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <!-- Item Name -->
                    <div>
                        <Label>Item Name</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ item.item_name ?? "N/a" }}</Label>
                    </div>

                    <div>
                        <Label>Supplier Code</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ item.SupplierCode ?? "N/a" }}</Label>
                    </div>

                    <div>
                        <Label>Category</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ item.category ?? "N/a" }}</Label>
                    </div>

                    <div>
                        <Label>Brand</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ item.brand ?? "N/a" }}</Label>
                    </div>

                    <div>
                        <Label>Classification</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ item.classification ?? "N/a" }}</Label>
                    </div>

                    <div>
                        <Label>Packaging Config</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ item.packaging_config ?? "N/a" }}</Label>
                    </div>

                    <div>
                        <Label>UOM</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ item.uom ?? "N/a" }}</Label>
                    </div>

                    <div>
                        <Label>Cost</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ formatNumber(item.cost) }}</Label>
                    </div>

                    <div>
                        <Label>SRP</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ formatNumber(item.srp) }}</Label>
                    </div>

                    <div>
                        <Label>Is Active</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ displayIsActive(item.is_active) }}</Label>
                    </div>

                    <div>
                        <Label>Created At</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ item.created_at ? new Date(item.created_at).toLocaleString() : "N/a" }}</Label>
                    </div>

                    <div>
                        <Label>Updated At</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ item.updated_at ? new Date(item.updated_at).toLocaleString() : "N/a" }}</Label>
                    </div>
                </CardContent>
            </Card>
        </section>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>
