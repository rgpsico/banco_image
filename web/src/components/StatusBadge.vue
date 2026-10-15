<script setup>
import { computed } from 'vue'
const props = defineProps({ status: String })
const MAP = {
  draft: ['Rascunho', 'secondary'], pending_review: ['Em análise', 'warning'], published: ['Publicada', 'success'],
  rejected: ['Recusada', 'danger'], suspended: ['Suspensa', 'danger'], archived: ['Arquivada', 'dark'],
}
const info = computed(() => MAP[props.status] || [props.status, 'secondary'])
</script>

<template><span class="badge" :class="`text-bg-${info[1]}`">{{ info[0] }}</span></template>
