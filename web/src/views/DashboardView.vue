<script setup>
import { onMounted, ref } from 'vue'
import { useTenantStore } from '../stores/tenant'
import { parseError } from '../services/api'
import FormField from '../components/FormField.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingButton from '../components/LoadingButton.vue'

const tenants = useTenantStore()
const name = ref('')
const loading = ref(false)
const error = ref('')
const fields = ref({})

onMounted(() => tenants.fetch())

async function createTenant() {
  loading.value = true; error.value = ''; fields.value = {}
  try { await tenants.create(name.value); name.value = '' } catch (e) {
    const p = parseError(e); error.value = p.message; fields.value = p.fields
  } finally { loading.value = false }
}

const mb = (b) => (b / 1024 / 1024).toFixed(1)
const gb = (b) => (b / 1024 / 1024 / 1024).toFixed(1)
</script>

<template>
  <div v-if="tenants.loading" class="text-center py-5"><div class="spinner-border" role="status"></div></div>

  <div v-else class="row g-4">
    <div class="col-lg-8">
      <h1 class="h4 mb-3">Seus projetos</h1>

      <div v-if="!tenants.tenants.length" class="alert alert-info">
        Você ainda não tem nenhum projeto. Crie o primeiro ao lado, por exemplo <strong>SurfGestão</strong>.
      </div>

      <div class="row g-3">
        <div v-for="t in tenants.tenants" :key="t.id" class="col-md-6">
          <div class="card h-100 shadow-sm" :class="{ 'border-primary': t.id === tenants.currentId }">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start">
                <h2 class="h6 mb-1">{{ t.name }}</h2>
                <span class="badge text-bg-secondary role-badge">{{ t.role }}</span>
              </div>
              <div class="text-muted small mb-3">/{{ t.slug }}</div>
              <div class="small">Armazenamento: {{ mb(t.storage.used_bytes) }} MB de {{ gb(t.storage.quota_bytes) }} GB</div>
              <div class="progress mt-1" style="height: 6px">
                <div class="progress-bar" :style="{ width: Math.min(100, (t.storage.used_bytes / t.storage.quota_bytes) * 100) + '%' }"></div>
              </div>
              <button class="btn btn-sm mt-3" :class="t.id === tenants.currentId ? 'btn-primary' : 'btn-outline-primary'" @click="tenants.select(t.id)">
                {{ t.id === tenants.currentId ? 'Projeto ativo' : 'Usar este projeto' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6">Novo projeto</h2>
          <FormAlert :message="error" />
          <form @submit.prevent="createTenant">
            <FormField v-model="name" label="Nome do projeto" :error="fields.name" required />
            <LoadingButton :loading="loading">Criar projeto</LoadingButton>
          </form>
          <div class="form-text mt-2">Cada projeto tem biblioteca, usuários e arquivos totalmente separados.</div>
        </div>
      </div>
    </div>
  </div>
</template>
