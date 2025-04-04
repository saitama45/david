<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
const { items, branches, filters } = defineProps({
    items: {
        type: Object,
        required: false,
    },
    branches: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    begginingInventory: String,
    endingInventory: String,
    averageInventory: String,
    costOfGoods: String,
});

const { options: branchesOptions } = useSelectOptions(branches);
let search = ref(filters.search);
const branchId = ref(filters.branchId || branchesOptions.value[0].value);
watch(branchId, (value) => {
    router.get(
        route("days-inventory-outstanding.index"),
        {
            search: search.value,
            branchId: value,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

watch(
    search,
    throttle(function (value) {
        router.get(
            route("days-inventory-outstanding.index"),
            {
                search: value,
                branchId: branchId.value,
            },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);
</script>

<template>
    <Layout heading="Days Inventory Outstanding">
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
                        <TD>Beginning Inventory</TD>
                        <TD>{{ begginingInventory }}</TD>
                    </tr>
                    <tr>
                        <TD>Ending Inventory</TD>
                        <TD>{{ endingInventory }}</TD>
                    </tr>
                    <tr>
                        <TD>Average Inventory</TD>
                        <TD>{{ averageInventory }}</TD>
                    </tr>
                    <tr>
                        <TD>Cogs of Goods</TD>
                        <TD>{{ costOfGoods }}</TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
    </Layout>
</template>
