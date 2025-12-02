<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    article: Object
});

const form = useForm({
    title: props.article.title,
    category: props.article.category,
    content: props.article.content,
    is_published: !!props.article.is_published,
});

const submit = () => {
    form.put(route('knowledge-base.update', props.article.id));
};
</script>

<template>
    <Layout heading="Edit Knowledge Base Article">
        <template #header-actions>
            <BackButton :href="route('knowledge-base.index')" />
        </template>

        <Card class="max-w-4xl mx-auto">
            <CardHeader>
                <CardTitle>Edit Article</CardTitle>
                <CardDescription>Update the article details.</CardDescription>
            </CardHeader>
            <CardContent>
                <form @submit.prevent="submit" class="space-y-6">
                    <div class="grid grid-cols-1 gap-4">
                        <InputContainer>
                            <Label for="title">Title</Label>
                            <Input id="title" v-model="form.title" :class="{'border-red-500': form.errors.title}" />
                            <FormError :message="form.errors.title" />
                        </InputContainer>

                         <InputContainer>
                            <Label for="category">Category</Label>
                            <Input id="category" v-model="form.category" :class="{'border-red-500': form.errors.category}" />
                            <FormError :message="form.errors.category" />
                        </InputContainer>
                        
                        <InputContainer>
                            <Label for="content">Content</Label>
                            <Textarea id="content" v-model="form.content" rows="10" :class="{'border-red-500': form.errors.content}" />
                             <FormError :message="form.errors.content" />
                        </InputContainer>

                        <div class="flex items-center space-x-2">
                            <Checkbox id="is_published" v-model="form.is_published" :binary="true" />
                            <Label for="is_published">Publish immediately</Label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-4">
                         <Button type="button" variant="outline" @click="form.reset()">Reset</Button>
                         <Button type="submit" :disabled="form.processing">Update Article</Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </Layout>
</template>
