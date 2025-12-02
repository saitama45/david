<script setup>
import { router } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { useAuth } from "@/Composables/useAuth";
import Dialog from "primevue/dialog";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { ref, computed, watch } from "vue";

const { hasAccess } = useAuth();
const props = defineProps({
    articles: {
        type: Object,
        required: true,
    },
    articlesList: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
});

let filter = ref(props.filters.search);
const search = ref(filter.value);

const handleClick = () => {
    router.get(route('manage-knowledge-base.create'));
};

const { options: articlesOption } = useSelectOptions(props.articlesList);

watch(
    search,
    throttle(function (value) {
        router.get(
            route("manage-knowledge-base.index"),
            { search: value },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

import { useReferenceDelete } from "@/Composables/useReferenceDelete";
const { deleteModel } = useReferenceDelete();

const exportRoute = computed(() =>
    route("manage-knowledge-base.export", { search: search.value })
);

const isLoading = ref(false);
</script>

<template>
    <Layout
        heading="Knowledge Base Articles"
        :hasButton="hasAccess('create knowledge base articles')"
        buttonName="Create New Article"
        :handleClick="handleClick"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        v-model="search"
                        class="pl-10"
                        placeholder="Search..."
                    />
                </SearchBar>
            </TableHeader>

            <Table class="sm:table hidden">
                <TableHead>
                    <TH> Id </TH>
                    <TH> Title </TH>
                    <TH> Category </TH>
                    <TH> Author </TH>
                    <TH> Status </TH>
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="article in articles.data" :key="article.id">
                        <TD>{{ article.id }}</TD>
                        <TD>{{ article.title }}</TD>
                        <TD>{{ article.category || '-' }}</TD>
                        <TD>{{ article.author?.first_name }} {{ article.author?.last_name }}</TD>
                        <TD>
                            <Badge :variant="article.is_published ? 'default' : 'secondary'">
                                {{ article.is_published ? 'Published' : 'Draft' }}
                            </Badge>
                        </TD>
                        <TD>
                            <DivFlexCenter class="sm:gap-3">
                                <!-- Show Button (using edit for now as show placeholder) -->
                                <!-- <ShowButton
                                    v-if="hasAccess('view knowledge base articles')"
                                    :isLink="true"
                                    :href="`/manage-knowledge-base/show/${article.id}`"
                                /> -->
                                <EditButton
                                    v-if="hasAccess('edit knowledge base articles')"
                                    :isLink="true"
                                    :href="`/manage-knowledge-base/edit/${article.id}`"
                                />
                                <DeleteButton
                                    @click="
                                        deleteModel(
                                            route('manage-knowledge-base.destroy', article.id),
                                            'article'
                                        )
                                    "
                                />
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <DivFlexCol class="sm:hidden gap-3">
                <DivFlexCol
                    class="rounded-lg border min-h-20 p-3"
                    v-for="article in articles.data"
                    :key="article.id"
                >
                    <MobileTableHeading
                        :title="article.title"
                    >
                        <EditButton
                            v-if="hasAccess('edit knowledge base articles')"
                            :isLink="true"
                            :href="`/manage-knowledge-base/edit/${article.id}`"
                        />
                        <DeleteButton
                            @click="
                                deleteModel(
                                    route('manage-knowledge-base.destroy', article.id),
                                    'article'
                                )
                            "
                        />
                    </MobileTableHeading>
                    <LabelXS>{{ article.category }}</LabelXS>
                </DivFlexCol>
            </DivFlexCol>

            <Pagination :data="articles" />
        </TableContainer>
    </Layout>
</template>
