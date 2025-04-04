<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { router } from "@inertiajs/vue3";
const { branches, filters } = defineProps({
    branches: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    accountPayable: String,
    costOfGoods: String,
});
const { options: branchesOptions } = useSelectOptions(branches);
const branchId = ref(filters.branchId || branchesOptions.value[0].value);

watch(branchId, (value) => {
    router.get(
        route("days-payable-outstanding.index"),
        {
            branchId: value,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});
</script>

<template>
    <Layout heading="Days Payable Outstanding">
        <TableContainer>
            <TableHeader>
                <span></span>

                <DivFlexCenter class="gap-5">
                    <Select
                        filter
                        placeholder="Select a Branch"
                        v-model="branchId"
                        :options="branchesOptions"
                        optionLabel="label"
                        optionValue="value"
                    >
                    </Select>
                </DivFlexCenter>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Particular</TH>
                    <TH>Value</TH>
                </TableHead>
                <TableBody>
                    <tr>
                        <TD>Account Payable</TD>
                        <TD>{{ accountPayable }}</TD>
                    </tr>
                    <tr>
                        <TD>Cost of Goods Sold</TD>
                        <TD>{{ costOfGoods }}</TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
