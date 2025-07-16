<script setup>
import CardContent from "@/Components/ui/card/CardContent.vue";
import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("POSMasterfile.index"));

const { item } = defineProps({
    item: {
        type: Object,
        required: true,
    },

});
</script>

<template>
    <Layout heading="POSMasterfile Details">
        <section class="grid sm:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="text-xl">
                        {{ item.ItemDescription ?? "N/a" }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="grid sm:grid-cols-2 gap-3">
                    <Label>Item Code</Label>
                    <Label class="font-bold">{{
                        item.ItemCode ?? "N/a"
                    }}</Label>

                    <Label>Item Desc</Label>
                    <Label class="font-bold">{{
                        item.ItemDescription ?? "N/a"
                    }}</Label>

                    <Label>Category</Label>
                    <Label class="font-bold">{{ item.Category ?? "N/a" }}</Label>

                    <Label>SubCategory</Label>
                    <Label class="font-bold">{{
                        item.SubCategory ?? "N/a"
                    }}</Label>

                    <Label>SRP</Label>
                    <Label class="font-bold">{{
                        item.SRP ?? "N/a"
                    }}</Label>

                    <Label>Is Active</Label>
                    <Label class="font-bold">{{
                        item.is_active == 1 ? "Yes" : "No"
                    }}</Label>
                </CardContent>
            </Card>
        </section>


        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>
