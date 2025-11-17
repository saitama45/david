<script setup>
import { defineProps } from 'vue';
import { useSearch } from "@/Composables/useSearch";
import { useAuth } from "@/Composables/useAuth";

const props = defineProps({
    suppliers: Object,
    filters: Object,
});

const { search } = useSearch("dsp-delivery-schedules.index");

const { hasAccess } = useAuth();
</script>

<template>
    <Layout heading="Delivery Schedules">
        <TableContainer>
            <TableHeader>
                <!-- Search Bar-->
                <SearchBar>
                    <Input
                        id="search"
                        type="text"
                        v-model="search"
                        placeholder="Search by name or code..."
                        class="pl-10"
                    />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>ID</TH>
                    <TH>Supplier Code</TH>
                    <TH>Name</TH>
                    <TH v-if="hasAccess('edit dsp delivery schedules') || hasAccess('view dsp delivery schedule')">Actions</TH>
                </TableHead>

                <TableBody>
                    <tr v-for="supplier in suppliers.data" :key="supplier.id">
                        <TD>{{ supplier.id }}</TD>
                        <TD>{{ supplier.supplier_code }}</TD>
                        <TD>{{ supplier.name }}</TD>
                        <TD class="flex items-center" v-if="hasAccess('edit dsp delivery schedules') || hasAccess('view dsp delivery schedule')">
                            <ShowButton
                                v-if="hasAccess('view dsp delivery schedule')"
                                :isLink="true"
                                :href="route('dsp-delivery-schedules.show', supplier.id)"
                            />
                            <EditButton
                                v-if="hasAccess('edit dsp delivery schedules')"
                                :isLink="true"
                                :href="route('dsp-delivery-schedules.edit', supplier.id)"
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="supplier in suppliers.data" :key="supplier.id">
                    <MobileTableHeading :title="supplier.name">
                        <div class="flex items-center gap-2">
                            <ShowButton
                                v-if="hasAccess('view dsp delivery schedule')"
                                class="size-5"
                                :isLink="true"
                                :href="route('dsp-delivery-schedules.show', supplier.id)"
                            />
                            <EditButton
                                v-if="hasAccess('edit dsp delivery schedules')"
                                class="size-5"
                                :isLink="true"
                                :href="route('dsp-delivery-schedules.edit', supplier.id)"
                            />
                        </div>
                    </MobileTableHeading>
                    <LabelXS>{{ supplier.supplier_code }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="suppliers" />
        </TableContainer>
    </Layout>
</template>