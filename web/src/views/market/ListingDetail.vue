<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { parseError } from '../../services/api'
import { useAuthStore } from '../../stores/auth'
import { useCartStore } from '../../stores/cart'
import { useSellerStore } from '../../stores/seller'
import { formatBytes, formatMoney } from '../../utils/format'
import FormAlert from '../../components/FormAlert.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const cart = useCartStore()
const seller = useSellerStore()
const listing = ref(null)
const license = ref('')
const loading = ref(true)
const notFound = ref(false)
const reporting = ref(false)
const report = ref({ reason: 'copyright', details: '' })
const reportMsg = ref('')
const reportErr = ref('')

async function load() {
  loading.value = true; notFound.value = false
  try {
    listing.value = (await api.get(`/market/listings/${route.params.id}`)).data.data
    license.value = listing.value.prices[0]?.license || ''
  } catch { notFound.value = true } finally { loading.value = false }
}
onMounted(load)
watch(() => route.params.id, load)

const selected = computed(() => listing.value?.prices.find((p) => p.license === license.value))
const inCart = computed(() => listing.value && cart.has(listing.value.id, license.value))
const isMine = computed(() => seller.profile && listing.value && seller.profile.slug === listing.value.seller.slug)

function add(goCart = false) {
  const p = selected.value
  cart.add({ listingId: listing.value.id, license: p.license, licenseName: p.name, title: listing.value.title, price_cents: p.price_cents, thumbnail_url: listing.value.thumbnail_url })
  if (goCart) router.push({ name: 'cart' })
}

async function sendReport() {
  reportErr.value = ''
  if (!auth.isAuthenticated) return router.push({ name: 'login', query: { redirect: route.fullPath } })
  try {
    reportMsg.value = (await api.post(`/market/listings/${listing.value.id}/reports`, report.value)).data.message
    reporting.value = false
  } catch (e) { reportErr.value = parseError(e).message }
}

const duration = (s) => (s ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` : null)
</script>

<template>
  <div v-if="loading" class="text-center py-5"><div class="spinner-border"></div></div>
  <div v-else-if="notFound" class="text-center py-5"><h1 class="h4">Mídia não encontrada</h1><RouterLink to="/market">Voltar ao marketplace</RouterLink></div>

  <div v-else class="row g-4">
    <div class="col-lg-8">
      <div class="preview-box rounded-3 overflow-hidden">
        <img :src="listing.image_url" :alt="listing.title" class="w-100" />
      </div>
      <div class="small text-muted mt-2">Prévia com marca d'água. Após a compra você baixa o arquivo original, sem marca.</div>
    </div>

    <div class="col-lg-4">
      <h1 class="h4">{{ listing.title }}</h1>
      <div class="mb-2">por <RouterLink :to="{ name: 'seller-public', params: { slug: listing.seller.slug } }">{{ listing.seller.name }}</RouterLink></div>
      <p v-if="listing.description" class="text-muted">{{ listing.description }}</p>
      <div class="mb-3">
        <RouterLink v-if="listing.category" class="badge text-bg-light me-1 text-decoration-none" :to="{ name: 'market-search', query: { category: listing.category } }">{{ listing.category_label }}</RouterLink>
        <span v-for="t in listing.tags" :key="t" class="badge text-bg-secondary me-1">{{ t }}</span>
      </div>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h2 class="h6">Escolha a licença</h2>
          <div v-for="p in listing.prices" :key="p.license" class="form-check border rounded p-2 ps-4 mb-2" :class="{ 'border-primary bg-light': license === p.license }">
            <input :id="'lic-' + p.license" v-model="license" class="form-check-input" type="radio" name="license" :value="p.license" />
            <label class="form-check-label w-100" :for="'lic-' + p.license">
              <div class="d-flex justify-content-between"><strong>{{ p.name }}</strong><strong>{{ formatMoney(p.price_cents) }}</strong></div>
              <div class="small text-muted">{{ p.description }}</div>
            </label>
          </div>

          <div v-if="isMine" class="alert alert-info small py-2 mb-0">Esta é a sua publicação.</div>
          <div v-else class="d-grid gap-2">
            <button class="btn btn-primary" @click="add(true)">Comprar agora · {{ formatMoney(selected?.price_cents) }}</button>
            <button class="btn btn-outline-primary" :disabled="inCart" @click="add()">{{ inCart ? 'Já está no carrinho' : 'Adicionar ao carrinho' }}</button>
          </div>
        </div>
      </div>

      <dl class="row small mb-3">
        <dt class="col-5 text-muted">Tipo</dt><dd class="col-7">{{ listing.type === 'video' ? 'Vídeo' : 'Foto' }} · {{ listing.format }}</dd>
        <template v-if="listing.width"><dt class="col-5 text-muted">Resolução</dt><dd class="col-7">{{ listing.width }} × {{ listing.height }}</dd></template>
        <template v-if="duration(listing.duration_seconds)"><dt class="col-5 text-muted">Duração</dt><dd class="col-7">{{ duration(listing.duration_seconds) }}</dd></template>
        <dt class="col-5 text-muted">Tamanho</dt><dd class="col-7">{{ formatBytes(listing.size_bytes) }}</dd>
      </dl>

      <FormAlert :message="reportMsg" type="success" />
      <button v-if="!reportMsg" class="btn btn-link btn-sm text-muted p-0" @click="reporting = !reporting">Denunciar esta publicação</button>
      <div v-if="reporting" class="card card-body mt-2">
        <FormAlert :message="reportErr" />
        <label class="form-label small">Motivo</label>
        <select v-model="report.reason" class="form-select form-select-sm mb-2">
          <option value="copyright">Violação de direitos autorais</option><option value="inappropriate">Conteúdo impróprio</option>
          <option value="misleading">Enganosa</option><option value="other">Outro</option>
        </select>
        <textarea v-model="report.details" class="form-control form-control-sm mb-2" rows="3" maxlength="1000" placeholder="Detalhes (opcional)"></textarea>
        <button class="btn btn-danger btn-sm" @click="sendReport">Enviar denúncia</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.preview-box { background: #0f1115; }
.preview-box img { max-height: 75vh; object-fit: contain; display: block; }
</style>
