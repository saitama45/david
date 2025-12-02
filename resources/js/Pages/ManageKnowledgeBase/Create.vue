<script setup>
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import ToggleSwitch from 'primevue/toggleswitch';
import { router } from "@inertiajs/vue3";
import { QuillEditor } from '@vueup/vue-quill'; // Import QuillEditor
import { computed, reactive } from 'vue'; // Import computed and reactive

const toast = useToast();
const confirm = useConfirm();

const form = useForm({
    title: null,
    category: null,
    content: null,
    is_published: true, // Default to published
});

const handleCreate = () => {
    form.clearErrors();

    let isValid = true;

    if (!form.title) {
        form.setError('title', 'Title is required.');
        isValid = false;
    }
    if (!form.content) {
        form.setError('content', 'Content is required.');
        isValid = false;
    }

    if (!isValid) {
        toast.add({
            severity: "error",
            summary: "Validation Error",
            detail: "Please correct the highlighted fields.",
            life: 3000,
        });
        return;
    }

    confirm.require({
        message: "Are you sure you want to create this article?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Create",
            severity: "success",
        },
        accept: () => {
            form.post(route("manage-knowledge-base.store"), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "New Article Successfully Created",
                        life: 3000,
                    });
                },
                onError: (e) => {
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "Failed to create article.",
                        life: 3000,
                    });
                },
            });
        },
    });
};

const handleCancel = () => {
    router.get(route("manage-knowledge-base.index"));
};

const editorOptions = reactive({
    modules: {
        toolbar: [
            ['bold', 'italic', 'underline', 'strike'],        // toggled buttons
            ['blockquote', 'code-block'],

            [{ 'header': 1 }, { 'header': 2 }],               // custom button values
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            [{ 'script': 'sub'}, { 'script': 'super' }],      // superscript/subscript
            [{ 'indent': '-1'}, { 'indent': '+1' }],          // outdent/indent
            [{ 'direction': 'rtl' }],                         // text direction

            [{ 'size': ['small', false, 'large', 'huge'] }],  // custom dropdown
            [{ 'header': [1, 2, 3, 4, 5, 6, false] }],

            [{ 'color': [] }, { 'background': [] }],          // dropdown with defaults from theme
            [{ 'font': [] }],
            [{ 'align': [] }],

            ['link', 'image', 'video'],                        // link and image, video

            ['clean']                                         // remove formatting button
        ]
    },
    theme: 'snow'
});
</script>

<template>
    <Layout heading="Create Knowledge Base Article">
        <Card>
            <CardHeader>
                <CardTitle>Article Details</CardTitle>
                <CardDescription>Input all the important fields</CardDescription>
            </CardHeader>
            <CardContent>
                <section class="grid grid-cols-1 gap-5">
                    <InputContainer>
                        <LabelXS>Title</LabelXS>
                        <Input v-model="form.title" />
                        <FormError>{{ form.errors.title }}</FormError>
                    </InputContainer>
                    
                    <InputContainer>
                        <LabelXS>Category</LabelXS>
                        <Input v-model="form.category" placeholder="e.g. General, Billing, Technical" />
                        <FormError>{{ form.errors.category }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Content</LabelXS>
                        <QuillEditor
                            v-model:content="form.content"
                            :options="editorOptions"
                            contentType="html"
                            toolbar="full"
                            style="height: 320px;"
                        />
                        <FormError>{{ form.errors.content }}</FormError>
                    </InputContainer>

                    <InputContainer>
                         <div class="flex items-center space-x-2">
                            <ToggleSwitch v-model="form.is_published" inputId="is_published" />
                            <label for="is_published" class="text-sm font-medium text-gray-700">Publish Immediately</label>
                        </div>
                        <FormError>{{ form.errors.is_published }}</FormError>
                    </InputContainer>
                </section>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <Button @click="handleCancel" variant="outline">Cancel</Button>
                <Button @click="handleCreate" :disabled="form.processing">Create</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>