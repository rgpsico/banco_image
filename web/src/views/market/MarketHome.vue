<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../services/api'
import ListingCard from '../../components/ListingCard.vue'

const router = useRouter()
const q = ref('')
const categories = ref([])
const latest = ref([])
const popular = ref([])
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    const [c, l, p] = await Promise.all([
      api.get('/market/categories'),
      api.get('/market/listings', { params: { per_page: 8 } }),
      api.get('/market/listings', { params: { per_page: 4, sort: 'popular' } }),
    ])
    categories.value = c.data.data; latest.value = l.data.data; popular.value = p.data.data.filter((x) => x.sales_count > 0)
  } catch { error.value = 'Não foi possível carregar o marketplace.' } finally { loading.value = false }
})

const search = () => router.push({ name: 'market-search', query: q.value ? { q: q.value } : {} })
</script>

<template>
  <section class="hero rounded-4 p-4 p-md-5 mb-4 text-white">
    <h1 class="display-6 fw-bold">Fotos e vídeos de fotógrafos locais</h1>
    <p class="lead mb-4">Compre com licença clara e baixe o original em alta qualidade. Quer vender as suas? <RouterLink to="/seller/join" class="link-light">Torne-se vendedor</RouterLink>.</p>
    <form class="input-group input-group-lg" style="max-width: 640px" @submit.prevent="search">
      <input v-model="q" class="form-control" type="search" placeholder="Buscar: praia, surf, trilha…" aria-label="Buscar" />
      <button class="btn btn-warning" type="submit">Buscar</button>
    </form>
  </section>

  <div v-if="error" class="alert alert-danger">{{ error }}</div>
  <div v-if="loading" class="text-center py-5"><div class="spinner-border"></div></div>

  <template v-else>
    <div class="d-flex flex-wrap gap-2 mb-4">
      <RouterLink v-for="c in categories" :key="c.id" class="btn btn-outline-secondary btn-sm" :to="{ name: 'market-search', query: { category: c.id } }">
        {{ c.label }} <span class="text-muted">({{ c.count }})</span>
      </RouterLink>
    </div>

    <div v-if="!latest.length" class="text-center text-muted py-5 empty-state">
      <div class="fs-1" aria-hidden="true">🖼</div>
      <p class="mb-1">Ainda não há mídias à venda.</p>
      <RouterLink to="/seller/join">Seja o primeiro vendedor</RouterLink>
    </div>

    <template v-else>
      <div v-if="popular.length" class="mb-5">
        <h2 class="h5 mb-3">Mais vendidas</h2>
        <div class="row g-3"><div v-for="l in popular" :key="l.id" class="col-6 col-md-3"><ListingCard :listing="l" /></div></div>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Mais recentes</h2>
        <RouterLink :to="{ name: 'market-search' }" class="small">Ver tudo</RouterLink>
      </div>
      <div class="row g-3"><div v-for="l in latest" :key="l.id" class="col-6 col-md-4 col-lg-3"><ListingCard :listing="l" /></div></div>
    </template>
  </template>
</template>

<style scoped>
.hero { background: linear-gradient(135deg, #1d3557, #457b9d); }
</style>
