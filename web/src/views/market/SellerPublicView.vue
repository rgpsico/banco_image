<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../services/api'
import ListingCard from '../../components/ListingCard.vue'
import PaginationBar from '../../components/PaginationBar.vue'

const route = useRoute()
const profile = ref(null)
const items = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const page = ref(1)
const loading = ref(true)
const notFound = ref(false)

async function load() {
  loading.value = true; notFound.value = false
  try {
    profile.value = (await api.get(`/market/sellers/${route.params.slug}`)).data.data
    const { data } = await api.get('/market/listings', { params: { seller: route.params.slug, page: page.value, per_page: 24 } })
    items.value = data.data; meta.value = data.meta
  } catch { notFound.value = true } finally { loading.value = false }
}
onMounted(load)
watch(() => route.params.slug, () => { page.value = 1; load() })
</script>

<template>
  <div v-if="loading" class="text-center py-5"><div class="spinner-border"></div></div>
  <div v-else-if="notFound" class="text-center py-5"><h1 class="h4">Vendedor não encontrado</h1><RouterLink to="/market">Voltar ao marketplace</RouterLink></div>
  <template v-else>
    <div class="card shadow-sm mb-4"><div class="card-body">
      <h1 class="h4 mb-1">{{ profile.display_name }}</h1>
      <div class="small text-muted mb-2">{{ profile.listings_count }} mídias · vendedor desde {{ profile.member_since }}</div>
      <p v-if="profile.bio" class="mb-0">{{ profile.bio }}</p>
    </div></div>
    <div v-if="!items.length" class="text-center text-muted py-4 empty-state">Este vendedor ainda não tem mídias publicadas.</div>
    <div v-else class="row g-3"><div v-for="l in items" :key="l.id" class="col-6 col-md-4 col-lg-3"><ListingCard :listing="l" /></div></div>
    <PaginationBar :page="meta.current_page" :last="meta.last_page" :total="meta.total" @go="page = $event; load()" />
  </template>
</template>
