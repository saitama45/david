<script setup>
const props = defineProps({
    modelValue: {
        type: Boolean,
        required: true,
    },
    quantity: {
        type: [Number, String],
        required: true,
    },
    error: {
        type: String,
        default: "",
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
});

defineEmits(["update:modelValue", "update:quantity", "confirm"]);
</script>

<template>
    <Dialog
        :open="modelValue"
        @update:open="$emit('update:modelValue', $event)"
    >
        <DialogContent class="sm:max-w-[600px]">
            <DialogHeader>
                <DialogTitle>Edit Quantity</DialogTitle>
                <DialogDescription>
                    Please input all the required fields.
                </DialogDescription>
            </DialogHeader>

            <InputContainer>
                <LabelXS>Quantity</LabelXS>
                <Input
                    type="number"
                    :value="quantity"
                    @input="$emit('update:quantity', $event.target.value)"
                />
                <FormError>{{ error }}</FormError>
            </InputContainer>

            <DialogFooter>
                <Button
                    @click="$emit('confirm')"
                    :disabled="isLoading"
                    type="submit"
                    class="gap-2"
                >
                    Confirm
                    <span v-if="isLoading"><Loading /></span>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
