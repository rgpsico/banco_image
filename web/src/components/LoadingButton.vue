<script setup>
defineProps({ loading: Boolean, variant: { type: String, default: 'primary' }, type: { type: String, default: 'submit' } })
</script>

<template>
  <button :type="type" :class="`btn btn-${variant}`" :disabled="loading">
    <span v-if="loading" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
    <slot />
  </button>
</template>
