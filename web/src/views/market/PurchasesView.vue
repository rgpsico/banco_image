<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api, { parseError } from '../../services/api'
import { formatDate, formatMoney } from '../../utils/format'
import FormAlert from '../../components/FormAlert.vue'

const route = useRoute()
const items = ref([])
const loading = ref(true)
const error = ref('')
const busy = ref(null)
const terms = ref(null)

async function load() {
  try { items.value = (await api.get('/purchases')).data.data } catch (e) { error.value = parseError(e).message } finally { loading.value = false }
}
onMounted(load)

async function download(item) {
  busy.value = item.id; error.value = ''
  try {
    const { data } = await api.post(`/purchases/${item.id}/download-url`)
    window.location.href = data.data.url
    item.downloads_remaining = data.data.downloads_remaining
  } catch (e) { error.value = parseError(e).message } finally { setTimeout(() => { busy.value = null }, 800) }
}
</script>

<template>
  <h1 class="h4 mb-3">Minhas compras</h1>
  <FormAlert v-if="route.query.novo" message="Pagamento confirmado! Seus arquivos estão liberados abaixo." type="success" />
  <FormAlert :message="error" />
  <div v-if="loading" class="text-center py-5"><div class="spinner-border"></div></div>
  <div v-else-if="!items.length" class="text-center text-muted py-5 empty-state">Você ainda não comprou nada. <RouterLink to="/market">Explorar o marketplace</RouterLink></div>

  <div v-else class="row g-3">
    <div v-for="i in items" :key="i.id" class="col-md-6">
      <div class="card shadow-sm purchase-item h-100"><div class="card-body d-flex gap-3">
        <img :src="i.thumbnail_url" :alt="i.title" style="width: 110px; height: 82px; object-fit: cover; border-radius: 8px" />
        <div class="flex-grow-1">
          <div class="fw-semibold">{{ i.title }}</div>
          <div class="small text-muted">Licença {{ i.license_name }} · {{ formatMoney(i.price_cents) }} · {{ formatDate(i.purchased_at) }}</div>
          <div class="small mt-1">{{ i.downloads_remaining }} download(s) restante(s)</div>
          <div class="d-flex gap-2 mt-2">
            <button class="btn btn-primary btn-sm" :disabled="busy === i.id || i.downloads_remaining < 1" @click="download(i)">Baixar original</button>
            <button class="btn btn-outline-secondary btn-sm" @click="terms = terms === i.id ? null : i.id">Ver licença</button>
          </div>
          <div v-if="terms === i.id" class="small text-muted mt-2">{{ i.license_terms }}</div>
        </div>
      </div></div>
    </div>
  </div>
</template>
