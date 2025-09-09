<script setup>
import { defineProps, defineEmits } from 'vue';
import Button from '@/components/ui/button/Button.vue';

const props = defineProps({
  show: {
    type: Boolean,
    required: true,
  },
  title: {
    type: String,
    default: 'Confirm Action',
  },
  message: {
    type: String,
    default: 'Are you sure you want to proceed?',
  },
});

const emit = defineEmits(['confirm', 'cancel']);

const confirmAction = () => {
  emit('confirm');
};

const cancelAction = () => {
  emit('cancel');
};
</script>

<template>
  <teleport to="body">
    <div v-if="show" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex justify-center items-center p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-auto">
        <div class="p-6">
          <h2 class="text-lg font-bold text-gray-900">{{ title }}</h2>
          <p class="mt-2 text-sm text-gray-600">{{ message }}</p>
        </div>
        <div class="bg-gray-50 px-6 py-4 flex justify-end space-x-3 rounded-b-lg">
          <Button variant="ghost" @click="cancelAction">Cancel</Button>
          <Button @click="confirmAction">Confirm</Button>
        </div>
      </div>
    </div>
  </teleport>
</template>
