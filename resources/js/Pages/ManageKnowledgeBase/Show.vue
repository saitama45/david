<script setup>
import { computed } from 'vue';

const props = defineProps({
    article: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout heading="Article Details">
        <Card class="p-5">
            <section class="grid grid-cols-1 gap-5">
                <InputContainer>
                    <LabelXS>Title</LabelXS>
                    <SpanBold class="font-bold">{{ article.title }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Category</LabelXS>
                    <SpanBold>{{ article.category ?? "Uncategorized" }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Status</LabelXS>
                    <Badge :variant="article.is_published ? 'default' : 'secondary'">
                        {{ article.is_published ? 'Published' : 'Draft' }}
                    </Badge>
                </InputContainer>
                 <InputContainer>
                    <LabelXS>Author</LabelXS>
                    <SpanBold>{{ article.author?.first_name }} {{ article.author?.last_name }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Created At</LabelXS>
                    <SpanBold>{{ new Date(article.created_at).toLocaleString() }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Content</LabelXS>
                    <div class="prose max-w-none whitespace-pre-wrap mt-2 p-4 bg-gray-50 rounded-md">
                        {{ article.content }}
                    </div>
                </InputContainer>
            </section>
        </Card>
        <BackButton routeName="manage-knowledge-base.index" />
    </Layout>
</template>
