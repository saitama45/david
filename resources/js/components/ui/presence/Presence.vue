<script setup>
import { computed, watch, ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
  present: { type: Boolean, default: true },
  forceMount: { type: Boolean, default: false },
});

const emit = defineEmits(['mount', 'unmount']);

const isPresent = ref(false);

const shouldRender = computed(() => {
  return props.forceMount || props.present;
});

const presenceState = computed(() => {
  return isPresent.value ? 'open' : 'closed';
});

watch(() => props.present, (newValue) => {
  if (newValue) {
    isPresent.value = true;
    emit('mount');
  } else {
    // Small delay for animation
    setTimeout(() => {
      if (!props.present) {
        isPresent.value = false;
        emit('unmount');
      }
    }, 200);
  }
}, { immediate: true });

onMounted(() => {
  if (props.present) {
    isPresent.value = true;
    emit('mount');
  }
});

onUnmounted(() => {
  emit('unmount');
});
</script>

<template>
  <div
    v-if="shouldRender"
    :data-state="presenceState"
    :data-present="isPresent"
  >
    <slot v-if="isPresent || forceMount" />
  </div>
</template>