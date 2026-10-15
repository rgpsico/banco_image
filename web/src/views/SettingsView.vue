<script setup>
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useTenantStore } from '../stores/tenant'
import { parseError } from '../services/api'
import FormField from '../components/FormField.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingButton from '../components/LoadingButton.vue'

const tenants = useTenantStore()
const router = useRouter()
const name = ref('')
const loading = ref(false)
const error = ref('')
const ok = ref('')

watch(() => tenants.current, (t) => { name.value = t?.name || '' }, { immediate: true })

async function save() {
  loading.value = true; error.value = ''; ok.value = ''
  try { await tenants.update(tenants.currentId, name.value); ok.value = 'Salvo.' } catch (e) { error.value = parseError(e).message } finally { loading.value = false }
}

async function destroy() {
  if (!confirm(`Excluir o projeto "${tenants.current.name}"? Esta ação afeta todos os membros.`)) return
  try { await tenants.remove(tenants.currentId); router.push({ name: 'dashboard' }) } catch (e) { error.value = parseError(e).message }
}
</script>

<template>
  <div v-if="!tenants.current" class="alert alert-info">Selecione ou crie um projeto primeiro.</div>
  <div v-else style="max-width: 560px">
    <h1 class="h4 mb-3">Configurações do projeto</h1>
    <FormAlert :message="error" />
    <FormAlert :message="ok" type="success" />
    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <form @submit.prevent="save">
          <FormField v-model="name" label="Nome do projeto" :required="true" />
          <LoadingButton :loading="loading" :disabled="!tenants.can('admin')">Salvar</LoadingButton>
          <div v-if="!tenants.can('admin')" class="form-text">Somente administradores podem alterar.</div>
        </form>
      </div>
    </div>
    <div v-if="tenants.can('owner')" class="card border-danger shadow-sm">
      <div class="card-body">
        <h2 class="h6 text-danger">Zona de perigo</h2>
        <p class="small text-muted">Excluir o projeto o torna inacessível para todos os membros.</p>
        <button class="btn btn-outline-danger btn-sm" @click="destroy">Excluir projeto</button>
      </div>
    </div>
  </div>
</template>
