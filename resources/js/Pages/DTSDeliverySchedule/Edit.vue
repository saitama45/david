<script setup>
import CardDescription from "@/Components/ui/card/CardDescription.vue";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
const toast = useToast();
const confirm = useConfirm();

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

const update = (id) => {
    form.post(route("dts-delivery-schedules.update", props.branch.id), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Store Schedule Successfully Updated",
                life: 3000,
            });
        },
        onError: (e) => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occured while trying to update the store schedule.",
                life: 3000,
            });
        },
    });
};
</script>

<template>
    <Layout heading="Edit Store Schedule">
        <DivFlexCol class="gap-5">
            <Card class="p-5 grid sm:grid-cols-2 gap-5">
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

            <DivFlexCenter class="justify-end gap-3">
                <BackButton />
                <Button @click="update">Update</Button>
            </DivFlexCenter>
        </DivFlexCol>
    </Layout>
</template>
