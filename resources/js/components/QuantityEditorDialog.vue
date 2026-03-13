<script setup>
import Dialog from "primevue/dialog";

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
        :visible="modelValue"
        @update:visible="$emit('update:modelValue', $event)"
        modal
        header="Edit Quantity"
        :style="{ width: '600px' }"
        :breakpoints="{ '1199px': '75vw', '575px': '90vw' }"
    >
        <div class="space-y-4">
            <p class="text-sm text-gray-600">Please input all the required fields.</p>

            <InputContainer>
                <LabelXS>Quantity</LabelXS>
                <Input
                    type="number"
                    :value="quantity"
                    @input="$emit('update:quantity', $event.target.value)"
                />
                <FormError>{{ error }}</FormError>
            </InputContainer>
        </div>

        <template #footer>
            <Button
                @click="$emit('confirm')"
                :disabled="isLoading"
                type="submit"
                class="gap-2"
            >
                Confirm
                <span v-if="isLoading"><Loading /></span>
            </Button>
        </template>
    </Dialog>
</template>
