<script setup>
import { computed } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps({
  class: {
    type: String,
    default: ''
  }
})

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props
  return delegated
})
</script>

<template>
  <div
    :class="cn('text-sm [&_p]:leading-relaxed', props.class)"
    v-bind="delegatedProps"
  >
    <slot />
  </div>
</template>