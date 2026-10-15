<script setup>
import { onMounted, ref, watch } from 'vue'
import api, { parseError } from '../services/api'
import { useTenantStore } from '../stores/tenant'
import { formatBytes } from '../utils/format'

const emit = defineEmits(['pick', 'cancel'])
const tenants = useTenantStore()
const tenantId = ref(tenants.currentId || tenants.tenants[0]?.id || '')
const items = ref([])
const loading = ref(false)
const error = ref('')

async function load() {
  if (!tenantId.value) { items.value = []; return }
  loading.value = true; error.value = ''
  try {
    const { data } = await api.get(`/tenants/${tenantId.value}/media`, { params: { per_page: 48 } })
    items.value = data.data.filter((m) => m.status === 'ready' && m.thumbnail_url)
  } catch (e) { error.value = parseError(e).message } finally { loading.value = false }
}
onMounted(load)
watch(tenantId, load)
</script>

<template>
  <div class="modal d-block overflow-auto" style="background: rgba(0,0,0,.55)" @click.self="emit('cancel')">
    <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 me-3">Escolha a mídia que será vendida</h2>
        <select v-model="tenantId" class="form-select form-select-sm" style="width: 220px" aria-label="Projeto">
          <option v-for="t in tenants.tenants" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
        <button type="button" class="btn-close ms-auto" aria-label="Fechar" @click="emit('cancel')"></button>
      </div>
      <div class="modal-body">
        <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>
        <div v-if="loading" class="text-center py-4"><div class="spinner-border"></div></div>
        <div v-else-if="!items.length" class="text-center text-muted py-4">
          Nenhuma mídia pronta neste projeto. <RouterLink to="/library" @click="emit('cancel')">Envie fotos na Biblioteca</RouterLink> e volte aqui.
        </div>
        <div v-else class="row g-2">
          <div v-for="m in items" :key="m.id" class="col-6 col-md-3 col-lg-2">
            <button class="card pick-card w-100 border-0 shadow-sm text-start" @click="emit('pick', m)">
              <img :src="m.thumbnail_url" :alt="m.name" style="aspect-ratio: 4/3; object-fit: cover; width: 100%" />
              <div class="p-1 small text-truncate">{{ m.name }}</div>
              <div class="px-1 pb-1 small text-muted">{{ formatBytes(m.size_bytes) }}</div>
            </button>
          </div>
        </div>
      </div>
    </div></div>
  </div>
</template>

<style scoped>
.pick-card { overflow: hidden; background: #fff; }
.pick-card:hover { outline: 2px solid #0d6efd; }
</style>
