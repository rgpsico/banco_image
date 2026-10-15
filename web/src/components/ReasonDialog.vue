<script setup>
import { ref } from 'vue'
const props = defineProps({ title: String, label: { type: String, default: 'Motivo' }, confirmLabel: { type: String, default: 'Confirmar' }, error: String, minLength: { type: Number, default: 5 } })
const emit = defineEmits(['confirm', 'cancel'])
const text = ref('')
</script>

<template>
  <div class="modal d-block" style="background: rgba(0,0,0,.45)" @click.self="emit('cancel')">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" @submit.prevent="emit('confirm', text)">
        <div class="modal-header"><h2 class="modal-title h6">{{ title }}</h2></div>
        <div class="modal-body">
          <label class="form-label small">{{ label }}</label>
          <textarea v-model="text" class="form-control" rows="3" maxlength="500" :class="{ 'is-invalid': error }" required></textarea>
          <div v-if="error" class="invalid-feedback">{{ error }}</div>
          <slot />
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="emit('cancel')">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm" :disabled="text.trim().length < minLength">{{ confirmLabel }}</button>
        </div>
      </form>
    </div>
  </div>
</template>
