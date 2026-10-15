<script setup>
defineProps({ page: Number, last: Number, total: Number })
const emit = defineEmits(['go'])
</script>

<template>
  <div v-if="last > 1" class="d-flex justify-content-between align-items-center mt-4">
    <span class="small text-muted">{{ total }} itens</span>
    <div class="btn-group btn-group-sm">
      <button class="btn btn-outline-secondary" :disabled="page <= 1" @click="emit('go', page - 1)">Anterior</button>
      <span class="btn btn-outline-secondary disabled">{{ page }} / {{ last }}</span>
      <button class="btn btn-outline-secondary" :disabled="page >= last" @click="emit('go', page + 1)">Próxima</button>
    </div>
  </div>
</template>
