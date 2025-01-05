<script setup>
import { DollarSign } from "lucide-vue-next";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
defineProps({
    heading: String,
    value: {
        required: true,
    },
    subheading: String,
});
</script>

<template>
    <Card>
        <CardHeader
            class="flex flex-row items-center justify-between space-y-0 pb-2"
        >
            <CardTitle class="text-sm font-medium"> {{ heading }} </CardTitle>
            <DollarSign class="h-4 w-4 text-muted-foreground" />
        </CardHeader>
        <CardContent>
            <div class="text-2xl font-bold">{{ value }}</div>
            <p class="text-xs text-muted-foreground">{{ subheading }}</p>
        </CardContent>
    </Card>
</template>
