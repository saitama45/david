<script setup>
import { format } from 'date-fns';

const props = defineProps({
    article: Object
});
</script>

<template>
    <Layout heading="Knowledge Base Article">
        <template #header-actions>
            <BackButton :href="route('knowledge-base.index')" />

        </template>

        <Card class="max-w-4xl mx-auto">
            <CardHeader>
                <div class="flex justify-between items-start">
                    <div>
                         <CardTitle class="text-2xl font-bold">{{ article.title }}</CardTitle>
                         <CardDescription class="mt-2">
                            Category: <span class="font-medium text-foreground">{{ article.category || 'Uncategorized' }}</span>
                         </CardDescription>
                    </div>
                    <Badge :variant="article.is_published ? 'default' : 'secondary'">
                        {{ article.is_published ? 'Published' : 'Draft' }}
                    </Badge>
                </div>
                 <div class="flex items-center text-sm text-muted-foreground mt-4 gap-4">
                    <div class="flex items-center gap-1">
                        <span class="font-medium">Author:</span>
                        {{ article.author?.first_name }} {{ article.author?.last_name }}
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="font-medium">Date:</span>
                        {{ format(new Date(article.created_at), 'MMM dd, yyyy') }}
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div class="prose max-w-none mt-2 p-4 bg-gray-50 rounded-md" v-html="article.content">
                </div>
            </CardContent>
        </Card>
    </Layout>
</template>
