<script setup>
import { useSearch } from "@/Composables/useSearch";
const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
});
const { search } = useSearch("items.index");
</script>

<template>
    <Layout heading="Items List">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        v-model="search"
                        placeholder="Search..."
                    />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Code</TH>
                    <TH>Name</TH>
                    <TH>Unit Tst</TH>
                </TableHead>

                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.InventoryID }}</TD>
                        <TD>{{ item.InventoryName }}</TD>
                        <TD>{{ item.Packaging }}</TD>
                    </tr>
                </TableBody>
            </Table>
            <div
                v-if="items.data.length !== 0"
                class="flex items-center justify-end gap-2"
            >
                <Component
                    v-for="link in items.links"
                    :is="link.url ? 'Link' : 'span'"
                    :href="link.url"
                    v-html="link.label"
                    class="px-3 py-1 border border-gray-200 text-primary-font font-bold rounded-lg"
                    :class="{
                        'bg-primary text-white': link.active,
                        'hover:bg-primary/50 transition-colors transition-duration duration-300':
                            link.url,
                    }"
                />
            </div>
        </TableContainer>
    </Layout>
</template>
