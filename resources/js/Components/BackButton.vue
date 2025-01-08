<script setup>
import { useBackButton } from "@/Composables/useBackButton";


const { routeName } = defineProps({
    routeName: {
        type: String,
        default: ""
    },
});
const { backButton } = useBackButton(route(routeName));

</script>
<template>
    <Button variant="outline" class="text-lg px-7" @click="backButton">
        Back
    </Button>
</template>
