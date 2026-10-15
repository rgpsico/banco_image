<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { parseError } from '../../services/api'
import { centsToReais, reaisToCents, formatBytes } from '../../utils/format'
import FormAlert from '../../components/FormAlert.vue'
import LoadingButton from '../../components/LoadingButton.vue'
import MediaPicker from '../../components/MediaPicker.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import SellerNav from './SellerNav.vue'

const route = useRoute()
const router = useRouter()
const id = computed(() => route.params.id)
const isNew = computed(() => !id.value)
const listing = ref(null)
const media = ref(null)
const licenses = ref([])
const categories = ref([])
const picking = ref(false)
const saving = ref(false)
const error = ref('')
const msg = ref('')
const fields = ref({})
const form = reactive({ title: '', description: '', category: '', tags: '', has_people: false, model_release_confirmed: false, rights_declared: false })
const prices = reactive({}) // { personal: { on: true, value: '9,90' } }

const editable = computed(() => isNew.value || ['draft', 'rejected', 'archived'].includes(listing.value?.status))
const onlyPrices = computed(() => listing.value?.status === 'published')

onMounted(async () => {
  const [l, c] = await Promise.all([api.get('/market/licenses'), api.get('/market/categories')])
  licenses.value = l.data.data; categories.value = c.data.data
  for (const lic of licenses.value) prices[lic.code] = { on: false, value: '' }
  if (isNew.value) { picking.value = true; return }
  try {
    const d = (await api.get(`/seller/listings/${id.value}`)).data.data
    listing.value = d; media.value = d.media
    Object.assign(form, { title: d.title, description: d.description || '', category: d.category || '', tags: (d.tags || []).join(', '), has_people: d.has_people, model_release_confirmed: d.model_release_confirmed, rights_declared: !!d.rights_declared_at })
    for (const p of d.prices) prices[p.license] = { on: true, value: centsToReais(p.price_cents) }
  } catch (e) { error.value = parseError(e).message }
})

function payload() {
  const body = {
    title: form.title, description: form.description, category: form.category || null,
    tags: form.tags.split(',').map((t) => t.trim()).filter(Boolean),
    has_people: form.has_people, model_release_confirmed: form.model_release_confirmed, rights_declared: form.rights_declared,
    prices: licenses.value.filter((l) => prices[l.code].on).map((l) => ({ license: l.code, price_cents: reaisToCents(prices[l.code].value) })),
  }
  if (onlyPrices.value) return { prices: body.prices, tags: body.tags }
  return body
}

async function save(thenSubmit = false) {
  error.value = ''; msg.value = ''; fields.value = {}
  const body = payload()
  if (body.prices.some((p) => !p.price_cents)) { error.value = 'Informe um preço válido (ex.: 19,90) para cada licença marcada.'; return }
  saving.value = true
  try {
    if (isNew.value) {
      const { data } = await api.post('/seller/listings', { media_id: media.value.id, ...body })
      listing.value = data.data
      // O rascunho já existe: daqui em diante a tela está em modo de edição (mesmo que o envio falhe).
      router.replace(`/seller/listings/${data.data.id}`)
      if (thenSubmit) listing.value = (await api.post(`/seller/listings/${data.data.id}/submit`)).data.data
      msg.value = thenSubmit ? 'Enviada para análise.' : 'Rascunho salvo.'
      return
    }
    listing.value = (await api.patch(`/seller/listings/${id.value}`, body)).data.data
    if (thenSubmit) listing.value = (await api.post(`/seller/listings/${id.value}/submit`)).data.data
    msg.value = thenSubmit ? 'Enviada para análise.' : 'Salvo.'
  } catch (e) { const p = parseError(e); error.value = p.message; fields.value = p.fields } finally { saving.value = false }
}
</script>

<template>
  <h1 class="h4 mb-3">{{ isNew ? 'Nova publicação' : 'Editar publicação' }} <StatusBadge v-if="listing" :status="listing.status" class="ms-2 fs-6" /></h1>
  <SellerNav />
  <FormAlert :message="error" /><FormAlert :message="msg" type="success" />
  <div v-if="listing?.rejection_reason" class="alert alert-danger small"><strong>Motivo da recusa/suspensão:</strong> {{ listing.rejection_reason }}</div>

  <div v-if="isNew && !media" class="text-center py-4">
    <button class="btn btn-primary" @click="picking = true">Escolher mídia da minha biblioteca</button>
  </div>
  <MediaPicker v-if="picking" @pick="media = $event; picking = false; if (!form.title) form.title = $event.name.replace(/\.[^.]+$/, '')" @cancel="picking = false" />

  <div v-if="media" class="row g-4">
    <div class="col-lg-4">
      <div class="card shadow-sm"><img :src="media.preview_url || media.thumbnail_url" class="card-img-top" alt="" />
        <div class="card-body small text-muted">{{ media.name }} · {{ formatBytes(media.size_bytes) }}<br />Você vê a imagem sem marca; o catálogo mostra a versão com marca d'água.</div></div>
    </div>
    <div class="col-lg-8">
      <fieldset :disabled="!editable && !onlyPrices">
        <div class="card shadow-sm mb-3"><div class="card-body">
          <div class="mb-3"><label class="form-label">Título</label><input v-model="form.title" class="form-control" :class="{ 'is-invalid': fields.title }" maxlength="150" :disabled="onlyPrices" /><div class="invalid-feedback">{{ fields.title }}</div></div>
          <div class="mb-3"><label class="form-label">Descrição</label><textarea v-model="form.description" class="form-control" rows="3" maxlength="2000" :disabled="onlyPrices"></textarea><div v-if="fields.description" class="text-danger small">{{ fields.description }}</div></div>
          <div class="row g-3">
            <div class="col-md-5"><label class="form-label">Categoria</label>
              <select v-model="form.category" class="form-select" :disabled="onlyPrices"><option value="">Escolha…</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option></select>
              <div v-if="fields.category" class="text-danger small">{{ fields.category }}</div></div>
            <div class="col-md-7"><label class="form-label">Tags (separe por vírgula)</label><input v-model="form.tags" class="form-control" placeholder="praia, verão, rio" /></div>
          </div>
        </div></div>

        <div class="card shadow-sm mb-3"><div class="card-body">
          <h2 class="h6">Licenças e preços</h2>
          <div v-if="fields.prices" class="text-danger small mb-2">{{ fields.prices }}</div>
          <div v-for="l in licenses" :key="l.code" class="row g-2 align-items-center mb-2 price-row">
            <div class="col-sm-6"><div class="form-check">
              <input :id="'p-' + l.code" v-model="prices[l.code].on" class="form-check-input" type="checkbox" />
              <label :for="'p-' + l.code" class="form-check-label"><strong>{{ l.name }}</strong><div class="small text-muted">{{ l.description }}</div></label>
            </div></div>
            <div class="col-sm-4"><div class="input-group input-group-sm"><span class="input-group-text">R$</span>
              <input v-model="prices[l.code].value" class="form-control" inputmode="decimal" placeholder="0,00" :disabled="!prices[l.code].on" /></div></div>
          </div>
          <div class="form-text">Mínimo R$ 2,00. A plataforma retém a comissão sobre cada venda.</div>
        </div></div>

        <div class="card shadow-sm mb-3"><div class="card-body">
          <div class="form-check mb-2"><input id="people" v-model="form.has_people" class="form-check-input" type="checkbox" :disabled="onlyPrices" /><label for="people" class="form-check-label">Há pessoas reconhecíveis na mídia</label></div>
          <div v-if="form.has_people" class="form-check mb-2 ms-3"><input id="release" v-model="form.model_release_confirmed" class="form-check-input" type="checkbox" :disabled="onlyPrices" /><label for="release" class="form-check-label small">Tenho a <strong>autorização de uso de imagem</strong> dessas pessoas</label>
            <div v-if="fields.model_release_confirmed" class="text-danger small">{{ fields.model_release_confirmed }}</div></div>
          <div class="form-check"><input id="rights" v-model="form.rights_declared" class="form-check-input" type="checkbox" :disabled="onlyPrices || !!listing?.rights_declared_at" /><label for="rights" class="form-check-label small">Declaro ser o <strong>autor ou titular dos direitos</strong> desta mídia</label>
            <div v-if="fields.rights_declared" class="text-danger small">{{ fields.rights_declared }}</div></div>
        </div></div>
      </fieldset>

      <div v-if="fields.media" class="alert alert-warning small">{{ fields.media }}</div>
      <div class="d-flex gap-2">
        <LoadingButton type="button" variant="outline-primary" :loading="saving" @click="save(false)">{{ onlyPrices ? 'Salvar preços' : 'Salvar rascunho' }}</LoadingButton>
        <LoadingButton v-if="!onlyPrices && editable" type="button" :loading="saving" @click="save(true)">Salvar e enviar para análise</LoadingButton>
        <RouterLink class="btn btn-link" to="/seller/listings">Voltar</RouterLink>
      </div>
    </div>
  </div>
</template>
