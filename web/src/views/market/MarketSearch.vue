<script setup>
import { reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../services/api'
import { reaisToCents } from '../../utils/format'
import ListingCard from '../../components/ListingCard.vue'
import PaginationBar from '../../components/PaginationBar.vue'

const route = useRoute()
const router = useRouter()
const items = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const categories = ref([])
const loading = ref(false)
const error = ref('')
const f = reactive({ q: '', category: '', type: '', sort: 'newest', min: '', max: '' })

function fromQuery() {
  const q = route.query
  Object.assign(f, { q: q.q || '', category: q.category || '', type: q.type || '', sort: q.sort || 'newest', min: q.min || '', max: q.max || '' })
}

async function load() {
  loading.value = true; error.value = ''
  const params = { page: route.query.page || 1, sort: f.sort }
  if (f.q) params.q = f.q
  if (f.category) params.category = f.category
  if (f.type) params.type = f.type
  if (reaisToCents(f.min)) params.min_price = reaisToCents(f.min)
  if (reaisToCents(f.max)) params.max_price = reaisToCents(f.max)
  try {
    const { data } = await api.get('/market/listings', { params })
    items.value = data.data; meta.value = data.meta
  } catch (e) { error.value = e.response?.data?.message || 'Erro ao buscar.' } finally { loading.value = false }
}

function apply(page = 1) {
  const query = {}
  for (const k of ['q', 'category', 'type', 'min', 'max']) if (f[k]) query[k] = f[k]
  if (f.sort !== 'newest') query.sort = f.sort
  if (page > 1) query.page = page
  router.push({ name: 'market-search', query })
}

watch(() => route.query, () => { fromQuery(); load() }, { immediate: true })
api.get('/market/categories').then((r) => { categories.value = r.data.data }).catch(() => {})
</script>

<template>
  <h1 class="h4 mb-3">Buscar mídias</h1>
  <form class="row g-2 mb-4 align-items-end" @submit.prevent="apply()">
    <div class="col-md-4"><input v-model="f.q" class="form-control" type="search" placeholder="Buscar por título, descrição ou tag" aria-label="Buscar" /></div>
    <div class="col-6 col-md-2">
      <select v-model="f.category" class="form-select" aria-label="Categoria" @change="apply()">
        <option value="">Todas as categorias</option>
        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <select v-model="f.type" class="form-select" aria-label="Tipo" @change="apply()">
        <option value="">Foto e vídeo</option><option value="image">Fotos</option><option value="video">Vídeos</option>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <select v-model="f.sort" class="form-select" aria-label="Ordenar" @change="apply()">
        <option value="newest">Mais recentes</option><option value="popular">Mais vendidas</option>
        <option value="price_asc">Menor preço</option><option value="price_desc">Maior preço</option>
      </select>
    </div>
    <div class="col-3 col-md-1"><input v-model="f.min" class="form-control" inputmode="decimal" placeholder="R$ mín" aria-label="Preço mínimo" /></div>
    <div class="col-3 col-md-1"><input v-model="f.max" class="form-control" inputmode="decimal" placeholder="R$ máx" aria-label="Preço máximo" /></div>
    <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Aplicar filtros</button></div>
  </form>

  <div v-if="error" class="alert alert-danger">{{ error }}</div>
  <div v-if="loading" class="text-center py-5"><div class="spinner-border"></div></div>
  <div v-else-if="!items.length" class="text-center text-muted py-5 empty-state">Nada encontrado com esses filtros.</div>
  <div v-else class="row g-3"><div v-for="l in items" :key="l.id" class="col-6 col-md-4 col-lg-3"><ListingCard :listing="l" /></div></div>

  <PaginationBar :page="meta.current_page" :last="meta.last_page" :total="meta.total" @go="apply($event)" />
</template>
