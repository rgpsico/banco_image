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

const tenants = useTenantStore()
const media = useMediaStore()
const collections = useCollectionsStore()
const selected = ref(null)
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
      <CollectionSidebar :active="media.filters.collection" @select="selectCollection" />
      <div v-if="quota" class="card shadow-sm mt-3">
        <div class="card-body p-3">
          <div class="small fw-semibold">Armazenamento</div>
          <div class="progress my-1" style="height: 6px"><div class="progress-bar" :class="{ 'bg-danger': quotaPct > 90 }" :style="{ width: quotaPct + '%' }"></div></div>
          <div class="small text-muted">{{ formatBytes(quota.used_bytes) }} de {{ formatBytes(quota.quota_bytes) }}</div>
        </div>
      </div>
    </div>

    <div class="col-lg-9">
      <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <h1 class="h4 mb-0 me-auto">Biblioteca · {{ tenants.current.name }}</h1>
        <input v-model="search" class="form-control form-control-sm" style="width: 220px" type="search" placeholder="Buscar por nome" aria-label="Buscar" @input="onSearch" />
        <select v-model="media.filters.type" class="form-select form-select-sm" style="width: 150px" aria-label="Tipo" @change="media.filters.page = 1">
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
        <div v-else>Sua biblioteca está vazia. Envie a primeira imagem ou vídeo acima.</div>
      </div>
      <MediaGrid v-else :items="media.items" @open="selected = $event" />

      <PaginationBar :page="media.meta.current_page" :last="media.meta.last_page" :total="media.meta.total" @go="media.filters.page = $event" />
    </div>

    <MediaModal v-if="selected" :media="selected" @close="selected = null" @changed="onMediaChanged" @deleted="selected = null; refreshAll()" />
  </div>
</template>
