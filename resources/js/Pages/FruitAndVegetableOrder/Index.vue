<script setup>
import { useSearch } from "@/Composables/useSearch";
const { search } = useSearch("fruits-and-vegetables.index");
import { router } from "@inertiajs/vue3";

import { useSelectOptions } from "@/Composables/useSelectOptions";
import { filter } from "lodash";

const { items, datesOption, filters, branches } = defineProps({
    items: {
        type: Object,
        required: true,
    },
    datesOption: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        rquired: true,
    },
});
const { options: branchesOption } = useSelectOptions(branches);

const getDefaultSelectedDate = () => {
    if (!datesOption || !Array.isArray(datesOption) || datesOption.length < 2) {
        return null;
    }
    return datesOption[1]?.code || null;
};

const defaultSelectedDate = getDefaultSelectedDate();
const selectedDate = ref(filters.start_date_filter || defaultSelectedDate);
const branchId = ref(filters.branchId || []);
watch(selectedDate, function (value) {
    console.log(value);
    router.get(
        route("fruits-and-vegetables.index"),
        { start_date_filter: value },
        {
            preserveState: false,
            replace: true,
        }
    );
});

watch(branchId, (value) => {
    console.log(value);
    router.get(
        route("fruits-and-vegetables.index"),
        { branchId: value, start_date_filter: selectedDate.value },
        {
            preserveState: false,
            replace: true,
        }
    );
});

const exportRoute = computed(() => {
    return route("fruits-and-vegetables.export", {
        start_date_filter: selectedDate.value,
        branchId: branchId.value,
    });
});
</script>

<template>
    <Layout
        heading="Fruits And Vegetables Orders"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <TableContainer>
            <TableHeader class="gap-3 sm:flex-row flex-col">
                <DivFlexCenter class="gap-5 w-full">
                    <SearchBar>
                        <Input
                            class="pl-10"
                            v-model="search"
                            placeholder="Search..."
                        />
                    </SearchBar>
                    <section class="sm:hidden">
                        <Popover class="w-fit">
                            <PopoverTrigger> <Filter /> </PopoverTrigger>
                            <PopoverContent>
                                <DivFlexCol class="gap-3">
                                    <MultiSelect
                                        filter
                                        showClear
                                        optionLabel="label"
                                        optionValue="value"
                                        :options="branchesOption"
                                        placeholder="Select a Branch"
                                        v-model="branchId"
                                    />
                                </DivFlexCol>
                            </PopoverContent>
                        </Popover>
                    </section>
                </DivFlexCenter>
                <section class="sm:hidden">
                    <Select
                        v-model="selectedDate"
                        :options="datesOption"
                        placeholder="No Available Options"
                        class="min-w-fit w-fit"
                        optionLabel="name"
                        optionValue="code"
                    />
                </section>

                <DivFlexCenter class="sm:flex hidden gap-3">
                    <MultiSelect
                        showClear
                        filter
                        optionLabel="label"
                        optionValue="value"
                        :options="branchesOption"
                        class="max-w-72"
                        placeholder="Select a Branch"
                        v-model="branchId"
                    />
                    <Select
                        v-model="selectedDate"
                        :options="datesOption"
                        placeholder="No Available Options"
                        class="max-w-72 w-fit"
                        optionLabel="name"
                        optionValue="code"
                    />
                </DivFlexCenter>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Code</TH>
                    <TH>Item</TH>
                    <TH>Monday</TH>
                    <TH>Tuesday</TH>
                    <TH>Wednesday</TH>
                    <TH>Thursday</TH>
                    <TH>Friday</TH>
                    <TH>Saturday</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.inventory_code }}</TD>
                        <TD>{{ item.name }}</TD>
                        <TD>{{ item.quantity_ordered.monday }}</TD>
                        <TD>{{ item.quantity_ordered.tuesday }}</TD>
                        <TD>{{ item.quantity_ordered.wednesday }}</TD>
                        <TD>{{ item.quantity_ordered.thursday }}</TD>
                        <TD>{{ item.quantity_ordered.friday }}</TD>
                        <TD>{{ item.quantity_ordered.saturday }}</TD>
                        <TD
                            ><ShowButton
                                :isLink="true"
                                :href="`/fruits-and-vegetables/show/${item.inventory_code}`"
                        /></TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="item in items.data">
                    <MobileTableHeading
                        :title="`${item.name} (${item.inventory_code})`"
                    >
                        <ShowButton
                            :isLink="true"
                            :href="`/fruits-and-vegetables/show/${item.inventory_code}`"
                        />
                    </MobileTableHeading>
                    <LabelXS
                        >Monday: {{ item.quantity_ordered.monday }}</LabelXS
                    >
                    <LabelXS
                        >Tuesday: {{ item.quantity_ordered.tuesday }}</LabelXS
                    >
                    <LabelXS
                        >Wednesday:
                        {{ item.quantity_ordered.wednesday }}</LabelXS
                    >
                    <LabelXS
                        >Thursday: {{ item.quantity_ordered.thursday }}</LabelXS
                    >
                    <LabelXS
                        >Friday: {{ item.quantity_ordered.friday }}</LabelXS
                    >
                    <LabelXS
                        >Saturday: {{ item.quantity_ordered.saturday }}</LabelXS
                    >
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
