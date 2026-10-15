<script setup>
defineProps({ message: String, type: { type: String, default: 'danger' } })
</script>

<template>
  <div v-if="message" :class="`alert alert-${type}`" role="alert">{{ message }}</div>
</template>
