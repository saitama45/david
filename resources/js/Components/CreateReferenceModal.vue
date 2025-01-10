<script setup>
const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    form: {
        type: Object,
        required: true,
    },
    isLoading: {
        type: Boolean,
        required: true,
    },
    handleCreate: {
        type: Function,
        required: true,
    },
    isCreateModalVisible: {
        type: Boolean,
        required: true,
    },
});

const emit = defineEmits(["update:isCreateModalVisible"]);
</script>
<template>
    <Dialog
        :open="isCreateModalVisible"
        @update:open="(value) => emit('update:isCreateModalVisible', value)"
    >
        <DialogContent class="sm:max-w-[425px]">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    Input all important fields.
                </DialogDescription>
            </DialogHeader>
            <div class="space-y-5">
                <div class="flex flex-col space-y-1">
                    <Label class="text-xs">Name</Label>
                    <Input v-model="form.name" />
                    <FormError>{{ form.errors.name }}</FormError>
                </div>
                <div class="flex flex-col space-y-1">
                    <Label class="text-xs">Remarks</Label>
                    <Textarea v-model="form.remarks" />
                    <FormError>{{ form.errors.remarks }}</FormError>
                </div>
                <div class="flex justify-end">
                    <Button @click="handleCreate" class="gap-2">
                        Create
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
