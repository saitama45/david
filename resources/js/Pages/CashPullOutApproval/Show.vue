<script setup>
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";

const confirm = useConfirm();
const { toast } = useToast();
import { router } from "@inertiajs/vue3";
const { cashPullOut } = defineProps({
    cashPullOut: {
        type: Object,
        required: true,
    },
});

const approveRequest = () => {
    confirm.require({
        message: "Are you sure you want to approve this cash pull out request?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Discard",
            severity: "danger",
        },
        acceptProps: {
            label: "Continue",
            severity: "primary",
        },
        accept: () => {
            router.put(
                route("cash-pull-out-approval.approve", cashPullOut.id),
                {
                    onSuccess: () => {
                        console.log("success");
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Approved Successfully.",
                            life: 5000,
                        });
                    },
                    onError: (e) => { 
                        console.log("errpr");
                        toast.add({
                            severity: "error",
                            summary: "Error",
                            detail: "An error occured while trying to approve this request.",
                            life: 5000,
                        });
                    },
                    onFinish: () => {
                        console.log("fish");
                    },
                }
            );
        },
    });
};
</script>

<template>
    <Layout
        heading="Cash Pull Out For Approval Details"
        :hasButton="true"
        buttonName="Approve"
        :handleClick="approveRequest"
    >
        <Card class="p-5 grid sm:grid-cols-4 gap-5">
            <InputContainer>
                <LabelXS>ID </LabelXS>
                <SpanBold>{{ cashPullOut.id }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Store Branch </LabelXS>
                <SpanBold>{{ cashPullOut.store_branch.name }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Date Needed </LabelXS>
                <SpanBold>{{ cashPullOut.date_needed }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Vendor </LabelXS>
                <SpanBold>{{ cashPullOut.vendor }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Vendor Address </LabelXS>
                <SpanBold>{{ cashPullOut.vendor_address }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Status </LabelXS>
                <SpanBold>{{ cashPullOut.status.toUpperCase() }}</SpanBold>
            </InputContainer>
            <InputContainer>
                <LabelXS>Remarks </LabelXS>
                <SpanBold>{{ cashPullOut.remarks ?? "None" }}</SpanBold>
            </InputContainer>
        </Card>

        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Item Name</TH>
                    <TH>Code</TH>
                    <TH>UOM</TH>
                    <TH>Quantity</TH>
                    <TH>Cost</TH>
                    <TH>Total Cost</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in cashPullOut.cash_pull_out_items">
                        <TD>{{ item.product_inventory.name }}</TD>
                        <TD>{{ item.product_inventory.inventory_code }}</TD>
                        <TD>{{
                            item.product_inventory.unit_of_measurement.name
                        }}</TD>
                        <TD>{{ item.product_inventory.cost }}</TD>
                        <TD>{{ item.quantity_ordered }}</TD>
                        <TD>{{
                            item.product_inventory.cost * item.quantity_ordered
                        }}</TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
