<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useTenantStore } from '../stores/tenant'
import { useMediaStore } from '../stores/media'
import { useCollectionsStore } from '../stores/collections'
import { formatBytes } from '../utils/format'
import UploadPanel from '../components/UploadPanel.vue'
import MediaGrid from '../components/MediaGrid.vue'
import MediaModal from '../components/MediaModal.vue'
import CollectionSidebar from '../components/CollectionSidebar.vue'
import PaginationBar from '../components/PaginationBar.vue'
import Icon from '../components/Icon.vue'

const tenants = useTenantStore()
const media = useMediaStore()
const collections = useCollectionsStore()
const selected = ref(null)
const wide = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 992px)').matches)
const search = ref(media.filters.search)
let debounce = null
let poll = null

const quota = computed(() => tenants.current?.storage)
const quotaPct = computed(() => (quota.value ? Math.min(100, (quota.value.used_bytes / quota.value.quota_bytes) * 100) : 0))

async function load(opts) { await media.fetch(tenants.currentId, opts); schedulePoll() }
async function refreshAll() { await Promise.all([load({ silent: true }), collections.fetch(tenants.currentId), tenants.fetch()]) }

// Miniaturas são geradas em segundo plano: reconsulta enquanto houver itens "processing".
function schedulePoll() {
  clearTimeout(poll)
  if (media.items.some((m) => m.status === 'processing')) poll = setTimeout(() => load({ silent: true }), 2500)
}

watch(() => tenants.currentId, () => { media.reset(); search.value = ''; selected.value = null; load(); collections.fetch(tenants.currentId) }, { immediate: true })
watch(() => media.filters.type, () => load())
watch(() => media.filters.collection, () => load())
watch(() => media.filters.page, () => load())

function onSearch() {
  clearTimeout(debounce)
  debounce = setTimeout(() => { media.setFilter({ search: search.value }); load() }, 350)
}

function selectCollection(id) { media.setFilter({ collection: id }) }
function onMediaChanged() { load({ silent: true }); if (selected.value) selected.value = media.items.find((m) => m.id === selected.value.id) || selected.value }
onBeforeUnmount(() => { clearTimeout(poll); clearTimeout(debounce) })
</script>

<template>
  <div v-if="!tenants.current" class="alert alert-info">Selecione ou crie um projeto primeiro.</div>

  <div v-else class="row g-4">
    <div class="col-lg-3">
      <details class="folders" :open="wide">
        <summary class="d-lg-none"><Icon name="folder" :size="18" /> Pastas<span v-if="media.filters.collection" class="badge text-bg-primary ms-2">filtrando</span></summary>
        <CollectionSidebar :active="media.filters.collection" @select="selectCollection" />
      </details>
      <div v-if="quota" class="card mt-3 d-none d-lg-block">
        <div class="card-body p-3">
          <div class="small fw-semibold">Armazenamento</div>
          <div class="progress my-1" style="height: 6px"><div class="progress-bar" :class="{ 'bg-danger': quotaPct > 90 }" :style="{ width: quotaPct + '%' }"></div></div>
          <div class="small text-muted">{{ formatBytes(quota.used_bytes) }} de {{ formatBytes(quota.quota_bytes) }}</div>
        </div>
      </div>
    </div>

    <div class="col-lg-9">
      <div class="page-head">
        <div><h1>Biblioteca</h1><p>{{ tenants.current.name }}</p></div>
      </div>
      <div class="filters mb-3">
        <input v-model="search" class="form-control" type="search" placeholder="Buscar por nome ou tag" aria-label="Buscar" @input="onSearch" />
        <select v-model="media.filters.type" class="form-select" aria-label="Tipo" @change="media.filters.page = 1">
          <option value="">Todos os tipos</option>
          <option value="image">Imagens</option>
          <option value="video">Vídeos</option>
        </select>
      </div>

      <UploadPanel v-if="tenants.can('editor')" @uploaded="refreshAll" />
      <div v-else class="alert alert-secondary py-2 small">Você tem acesso de leitura (viewer): pode ver e baixar, mas não enviar arquivos.</div>

      <div v-if="media.error" class="alert alert-danger">{{ media.error }}</div>
      <div v-if="media.loading" class="text-center py-5"><div class="spinner-border"></div></div>
      <div v-else-if="!media.items.length" class="text-center text-muted py-5 empty-state">
        <div class="fs-1" aria-hidden="true">🖼</div>
        <div v-if="media.filters.search || media.filters.type || media.filters.collection">Nada encontrado com esses filtros.</div>
        <div v-else>Sua biblioteca está vazia. Envie a primeira foto ou vídeo no quadro acima.</div>
      </div>
      <MediaGrid v-else :items="media.items" @open="selected = $event" />

      <PaginationBar :page="media.meta.current_page" :last="media.meta.last_page" :total="media.meta.total" @go="media.filters.page = $event" />
    </div>

    <MediaModal v-if="selected" :media="selected" @close="selected = null" @changed="onMediaChanged" @deleted="selected = null; refreshAll()" />
  </div>
</template>

<style scoped>
.filters { display: grid; grid-template-columns: 1fr 180px; gap: 8px; }
.folders summary { cursor: pointer; font-weight: 600; padding: 10px 14px; background: #fff; border: 1px solid var(--mh-line); border-radius: var(--mh-radius); list-style: none; display: flex; align-items: center; gap: 8px; }
.folders summary::-webkit-details-marker { display: none; }
.folders[open] summary { margin-bottom: 8px; }
@media (max-width: 575.98px) { .filters { grid-template-columns: 1fr; } }
</style>
