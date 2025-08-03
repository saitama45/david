<script setup>
import CardContent from "@/Components/ui/card/CardContent.vue";
import { useBackButton } from "@/Composables/useBackButton";

// Initialize back button functionality
const { backButton } = useBackButton(route("pos-bom.index")); // Changed route to pos-bom.index

// Define props for the component
const { bom } = defineProps({ // Changed prop name from 'item' to 'bom'
    bom: {
        type: Object,
        required: true,
    },
});

// Helper function to format numbers as currency/decimals
const formatNumber = (value) => {
    return value !== null && value !== undefined ? Number(value).toFixed(2) : "N/a";
};

// Helper function to display active status (if applicable, POSMasterfileBOM doesn't have is_active)
const displayIsActive = (value) => {
    return value == 1 ? "Yes" : "No";
};

// Helper function to format percentage
const formatPercentage = (value) => {
    return value !== null && value !== undefined ? (Number(value) * 100).toFixed(2) + '%' : "N/a";
};

// Helper function to get full name from creator/updater relationship
const getFullName = (userObject) => {
    return userObject && userObject.first_name && userObject.last_name 
           ? `${userObject.first_name} ${userObject.last_name}` 
           : (userObject ? `ID: ${userObject.id}` : "N/a"); // Fallback to ID if name not available
};
</script>

<template>
    <Layout heading="POSMasterfile BOM Details">
        <section class="grid sm:grid-cols-1">
            <Card>
                <CardHeader>
                    <CardTitle class="text-xl">
                        <Label class="font-bold">POS Code:</Label> {{ bom.POSCode ?? "N/a" }}
                    </CardTitle>
                    <CardDescription>
                        Details for Assembly: <Label class="font-bold">{{ bom.Assembly ?? "N/a" }}</Label>
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <!-- POS Description -->
                    <div>
                        <Label>POS Description</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ bom.POSDescription ?? "N/a" }}</Label>
                    </div>

                    <!-- Item Code -->
                    <div>
                        <Label>Item Code</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ bom.ItemCode ?? "N/a" }}</Label>
                    </div>

                    <!-- Item Description -->
                    <div>
                        <Label>Item Description</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ bom.ItemDescription ?? "N/a" }}</Label>
                    </div>

                    <!-- Rec Percent -->
                    <div>
                        <Label>Rec Percent</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ formatPercentage(bom.RecPercent) }}</Label>
                    </div>

                    <!-- Recipe Qty -->
                    <div>
                        <Label>Recipe Quantity</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ bom.RecipeQty ?? "N/a" }} {{ bom.RecipeUOM ?? "" }}</Label>
                    </div>

                    <!-- BOM Qty -->
                    <div>
                        <Label>BOM Quantity</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ bom.BOMQty ?? "N/a" }} {{ bom.BOMUOM ?? "" }}</Label>
                    </div>

                    <!-- Unit Cost -->
                    <div>
                        <Label>Unit Cost</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ formatNumber(bom.UnitCost) }}</Label>
                    </div>

                    <!-- Total Cost -->
                    <div>
                        <Label>Total Cost</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ formatNumber(bom.TotalCost) }}</Label>
                    </div>

                    <!-- Created By -->
                    <div>
                        <Label>Created By</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ getFullName(bom.creator) }}</Label>
                    </div>

                    <!-- Created At -->
                    <div>
                        <Label>Created At</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ bom.created_at ? new Date(bom.created_at).toLocaleString() : "N/a" }}</Label>
                    </div>

                    <!-- Updated By -->
                    <div>
                        <Label>Updated By</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ getFullName(bom.updater) }}</Label>
                    </div>

                    <!-- Updated At -->
                    <div>
                        <Label>Updated At</Label>
                    </div>
                    <div>
                        <Label class="font-bold">{{ bom.updated_at ? new Date(bom.updated_at).toLocaleString() : "N/a" }}</Label>
                    </div>
                </CardContent>
            </Card>
        </section>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>

