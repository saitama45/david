<script setup>
const { branches } = defineProps({
    branches: {
        type: Object,
        required: true,
    },
});
import { useSearch } from "@/Composables/useSearch";

const { search } = useSearch("dts-delivery-schedules.index");
import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();
</script>

<template>
    <Layout heading="DTS Delivery Schedules">
        <TableContainer>
            <TableHeader>
                <!-- Search Bar-->
                <SearchBar>
                    <Input
                        id="search"
                        type="text"
                        v-model="search"
                        placeholder="Search..."
                        class="pl-10"
                    />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Store Branch</TH>
                    <TH>Location Code</TH>
                    <!-- <TH>
                        <DivFlexCol>
                            Normal Orders
                            <LabelXS class="text-[10px]"
                                >Sun (7:00 am) to Wed (7:00 am)</LabelXS
                            >
                        </DivFlexCol>
                    </TH>
                    <TH>
                        <DivFlexCol>
                            Normal Orders
                            <LabelXS class="text-[10px]"
                                >Thurs (7:00 am) to Sat (7:00 am)</LabelXS
                            >
                        </DivFlexCol>
                    </TH> -->
                    <TH>
                        <DivFlexCol> Ice Cream </DivFlexCol>
                    </TH>
                    <TH>
                        <DivFlexCol> Salmon </DivFlexCol>
                    </TH>
                    <TH>
                        <DivFlexCol> Fruits and Vegetables </DivFlexCol>
                    </TH>
                    <TH v-if="hasAccess('edit dts delivery schedules')"
                        >Actions</TH
                    >
                </TableHead>

                <TableBody>
                    <tr v-for="branch in branches.data">
                        <TD>{{ branch.id }}</TD>
                        <TD>{{ branch.name }}</TD>
                        <TD>{{ branch.location_code ?? "N/a" }}</TD>
                        <!-- <TD>
                            <DivFlexCol class="gap-1">
                                <Badge class="w-fit">SUNDAY</Badge>
                                <Badge class="w-fit">MONDAY</Badge>
                                <Badge class="w-fit">TUESDAY</Badge>
                                <Badge class="w-fit">WEDNESDAY</Badge>
                            </DivFlexCol>
                        </TD>
                        <TD>
                            <DivFlexCol class="gap-1">
                                <Badge class="w-fit">THURSDAY</Badge>
                                <Badge class="w-fit">FRIDAY</Badge>
                                <Badge class="w-fit">SATURDAY</Badge>
                            </DivFlexCol>
                        </TD> -->
                        <TD>
                            <DivFlexCol class="gap-1">
                                <Badge
                                    class="w-fit"
                                    v-if="branch.ice_cream"
                                    v-for="data in branch.ice_cream.day"
                                >
                                    {{ data }}
                                </Badge>
                                <SpanBold v-else>No Schedule</SpanBold>
                            </DivFlexCol>
                        </TD>
                        <TD>
                            <DivFlexCol class="gap-1">
                                <Badge
                                    class="w-fit"
                                    v-if="branch.salmon"
                                    v-for="data in branch.salmon.day"
                                >
                                    {{ data }}
                                </Badge>
                                <SpanBold v-else>No Schedule</SpanBold>
                            </DivFlexCol>
                        </TD>
                        <TD>
                            <DivFlexCol class="gap-1">
                                <Badge
                                    class="w-fit"
                                    v-if="branch.fruits_and_vegetables"
                                    v-for="data in branch.fruits_and_vegetables
                                        .day"
                                >
                                    {{ data }}
                                </Badge>
                                <SpanBold v-else>No Schedule</SpanBold>
                            </DivFlexCol>
                        </TD>
                        <TD v-if="hasAccess('edit dts delivery schedules')"
                            ><EditButton
                                :isLink="true"
                                :href="
                                    route('dts-delivery-schedules.edit', branch.id)
                                "
                        /></TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="branch in branches.data">
                    <MobileTableHeading :title="branch.name">
                        <EditButton
                            class="size-5"
                            :isLink="true"
                            :href="route('dts-delivery-schedules.edit', branch.id)"
                        />
                    </MobileTableHeading>

                    <LabelXS>{{ branch.location_code ?? "N/a" }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="branches" />
        </TableContainer>
    </Layout>
</template>
