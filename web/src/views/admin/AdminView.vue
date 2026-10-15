<script setup>
import { onMounted, ref, watch } from 'vue'
import api, { parseError } from '../../services/api'
import { formatMoney } from '../../utils/format'
import StatusBadge from '../../components/StatusBadge.vue'
import FormAlert from '../../components/FormAlert.vue'
import ReasonDialog from '../../components/ReasonDialog.vue'

const tab = ref('review')
const listings = ref([])
const reports = ref([])
const sellers = ref([])
const loading = ref(false)
const error = ref('')
const forbidden = ref(false)
const dialog = ref(null) // { kind: 'reject'|'suspend', id }
const dialogError = ref('')

async function load() {
  loading.value = true; error.value = ''
  try {
    if (tab.value === 'review') listings.value = (await api.get('/admin/listings', { params: { status: 'pending_review' } })).data.data
    else if (tab.value === 'reports') reports.value = (await api.get('/admin/reports')).data.data
    else sellers.value = (await api.get('/admin/sellers')).data.data
  } catch (e) { if (e.response?.status === 404) forbidden.value = true; else error.value = parseError(e).message } finally { loading.value = false }
}
onMounted(load)
watch(tab, load)

async function approve(l) { try { await api.post(`/admin/listings/${l.id}/approve`); await load() } catch (e) { error.value = parseError(e).message } }
async function confirm(reason) {
  dialogError.value = ''
  try { await api.post(`/admin/listings/${dialog.value.id}/${dialog.value.kind}`, { reason }); dialog.value = null; await load() } catch (e) { dialogError.value = parseError(e).message }
}
async function resolve(r, action) { try { await api.post(`/admin/reports/${r.id}/resolve`, { action }); await load() } catch (e) { error.value = parseError(e).message } }
async function setStatus(s, status) { try { await api.patch(`/admin/sellers/${s.slug}`, { status }); await load() } catch (e) { error.value = parseError(e).message } }
async function setCommission(s) {
  const raw = prompt('Comissão para este vendedor em % (vazio = padrão da plataforma):', s.commission_bps != null ? s.commission_bps / 100 : '')
  if (raw === null) return
  try { await api.patch(`/admin/sellers/${s.slug}`, { commission_bps: raw.trim() === '' ? null : Math.round(parseFloat(raw.replace(',', '.')) * 100) }); await load() } catch (e) { error.value = parseError(e).message }
}
</script>

<template>
  <div v-if="forbidden" class="text-center py-5"><h1 class="h4">Página não encontrada</h1><RouterLink to="/market">Voltar</RouterLink></div>
  <template v-else>
    <h1 class="h4 mb-3">Moderação</h1>
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item"><button class="nav-link" :class="{ active: tab === 'review' }" @click="tab = 'review'">Em análise</button></li>
      <li class="nav-item"><button class="nav-link" :class="{ active: tab === 'reports' }" @click="tab = 'reports'">Denúncias</button></li>
      <li class="nav-item"><button class="nav-link" :class="{ active: tab === 'sellers' }" @click="tab = 'sellers'">Vendedores</button></li>
    </ul>
    <FormAlert :message="error" />
    <div v-if="loading" class="text-center py-4"><div class="spinner-border"></div></div>

    <template v-else-if="tab === 'review'">
      <div v-if="!listings.length" class="text-center text-muted py-5 empty-state">Nenhuma publicação aguardando análise.</div>
      <div v-for="l in listings" :key="l.id" class="card shadow-sm mb-3 review-item"><div class="card-body row g-3">
        <div class="col-md-3"><img :src="l.media.preview_url || l.media.thumbnail_url" alt="" class="w-100 rounded" /></div>
        <div class="col-md-9">
          <div class="d-flex justify-content-between"><h2 class="h6">{{ l.title }}</h2><StatusBadge :status="l.status" /></div>
          <div class="small text-muted mb-1">por {{ l.seller?.name }} · {{ l.category }} · {{ l.media.width }}×{{ l.media.height }}</div>
          <p class="small">{{ l.description }}</p>
          <div class="small mb-2">Preços: <span v-for="p in l.prices" :key="p.license" class="badge text-bg-light me-1">{{ p.name }} {{ formatMoney(p.price_cents) }}</span>
            <span v-if="l.has_people" class="badge text-bg-warning">Com pessoas {{ l.model_release_confirmed ? '(autorizado)' : '(SEM autorização)' }}</span></div>
          <button class="btn btn-success btn-sm me-2" @click="approve(l)">Aprovar</button>
          <button class="btn btn-outline-danger btn-sm" @click="dialog = { kind: 'reject', id: l.id }">Recusar</button>
        </div>
      </div></div>
    </template>

    <template v-else-if="tab === 'reports'">
      <div v-if="!reports.length" class="text-center text-muted py-5 empty-state">Nenhuma denúncia aberta.</div>
      <div v-for="r in reports" :key="r.id" class="card shadow-sm mb-2 report-item"><div class="card-body">
        <div class="d-flex justify-content-between"><strong>{{ r.listing.title }}</strong><StatusBadge :status="r.listing.status" /></div>
        <div class="small text-muted">{{ r.reason }} · por {{ r.reporter }} · vendedor {{ r.listing.seller }}</div>
        <p class="small mb-2">{{ r.details }}</p>
        <button class="btn btn-sm btn-outline-secondary me-2" @click="resolve(r, 'dismiss')">Dispensar</button>
        <button class="btn btn-sm btn-danger" @click="resolve(r, 'suspend_listing')">Suspender publicação</button>
      </div></div>
    </template>

    <div v-else class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Vendedor</th><th>Status</th><th>Publicações</th><th>Comissão</th><th></th></tr></thead>
      <tbody><tr v-for="s in sellers" :key="s.slug" class="seller-row">
        <td>{{ s.display_name }}<div class="small text-muted">{{ s.slug }}</div></td>
        <td><span class="badge" :class="s.status === 'active' ? 'text-bg-success' : 'text-bg-danger'">{{ s.status === 'active' ? 'Ativo' : 'Suspenso' }}</span></td>
        <td>{{ s.listings }}</td>
        <td>{{ s.effective_commission_bps / 100 }}% <span v-if="s.commission_bps == null" class="small text-muted">(padrão)</span></td>
        <td class="text-end"><button class="btn btn-sm btn-outline-secondary me-1" @click="setCommission(s)">Comissão</button>
          <button class="btn btn-sm" :class="s.status === 'active' ? 'btn-outline-danger' : 'btn-outline-success'" @click="setStatus(s, s.status === 'active' ? 'suspended' : 'active')">{{ s.status === 'active' ? 'Suspender' : 'Reativar' }}</button></td>
      </tr></tbody>
    </table></div></div>

    <ReasonDialog v-if="dialog" :title="dialog.kind === 'reject' ? 'Recusar publicação' : 'Suspender publicação'" label="Motivo (o vendedor vai ver)" confirm-label="Confirmar" :error="dialogError" @confirm="confirm" @cancel="dialog = null; dialogError = ''" />
  </template>
</template>
