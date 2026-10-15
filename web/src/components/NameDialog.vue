<script setup>
import { ref, watch } from 'vue'

const props = defineProps({ title: String, initial: { type: String, default: '' }, confirmLabel: { type: String, default: 'Salvar' }, error: String })
const emit = defineEmits(['save', 'cancel'])
const name = ref(props.initial)
watch(() => props.initial, (v) => { name.value = v })
</script>

<template>
  <div class="modal d-block" style="background: rgba(0,0,0,.45)" @click.self="emit('cancel')">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" @submit.prevent="emit('save', name)">
        <div class="modal-header"><h2 class="modal-title h6">{{ title }}</h2></div>
        <div class="modal-body">
          <input v-model="name" class="form-control" :class="{ 'is-invalid': error }" maxlength="120" autofocus required />
          <div v-if="error" class="invalid-feedback">{{ error }}</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="emit('cancel')">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">{{ confirmLabel }}</button>
        </div>
      </form>
    </div>
  </div>
</template>
