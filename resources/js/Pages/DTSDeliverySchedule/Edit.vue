<script setup>
import CardDescription from "@/Components/ui/card/CardDescription.vue";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useForm } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    branch: {
        type: Object,
        required: true,
    },
    schedules: {
        type: Object,
        required: true,
        default: () => ({}),
    },
    deliverySchedules: {
        type: Object,
        required: true,
        default: () => ({}),
    },
});

const getScheduleValues = (key) => {
    try {
        return props.schedules?.[key]
            ? Object.values(props.schedules[key])
            : [];
    } catch {
        return [];
    }
};

const deliverySchedulesArray = computed(() => {
    try {
        return Object.values(props.deliverySchedules || {});
    } catch {
        return [];
    }
});

const form = useForm({
    ice_cream: getScheduleValues("ice_cream"),
    salmon: getScheduleValues("salmon"),
    fruits_and_vegetables: getScheduleValues("fruits_and_vegetables"),
});
</script>

<template>
    <Layout heading="Edit Store Schedule">
        <DivFlexCol class="gap-5">
            <Card class="p-5 grid grid-cols-2 gap-5">
                <InputContainer>
                    <LabelXS>Branch Name</LabelXS>
                    <SpanBold>{{ branch.name }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Branch Code</LabelXS>
                    <SpanBold>{{ branch.branch_code }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Complete Address</LabelXS>
                    <SpanBold>{{ branch.complete_address }}</SpanBold>
                </InputContainer>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Ice Cream</CardTitle>
                    <CardDescription>Delivery Schedules</CardDescription>
                </CardHeader>
                <CardContent>
                    <MultiSelect
                        filter
                        placeholder="Set delivery days"
                        class="w-full"
                        optionLabel="label"
                        optionValue="value"
                        v-model="form.ice_cream"
                        :options="deliverySchedulesArray"
                    ></MultiSelect>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Salmon</CardTitle>
                    <CardDescription>Delivery Schedules</CardDescription>
                </CardHeader>
                <CardContent>
                    <MultiSelect
                        filter
                        placeholder="Set delivery days"
                        class="w-full"
                        optionLabel="label"
                        optionValue="value"
                        v-model="form.salmon"
                        :options="deliverySchedulesArray"
                    ></MultiSelect>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Fruits and Vegetables</CardTitle>
                    <CardDescription>Delivery Schedules</CardDescription>
                </CardHeader>
                <CardContent>
                    <MultiSelect
                        filter
                        placeholder="Set delivery days"
                        class="w-full"
                        optionLabel="label"
                        optionValue="value"
                        v-model="form.fruits_and_vegetables"
                        :options="deliverySchedulesArray"
                    ></MultiSelect>
                </CardContent>
            </Card>
        </DivFlexCol>
    </Layout>
</template>
