<script setup>
import { onMounted, ref, watch } from 'vue'
import api, { parseError } from '../../services/api'
import { formatDate, formatMoney } from '../../utils/format'
import StatusBadge from '../../components/StatusBadge.vue'
import FormAlert from '../../components/FormAlert.vue'
import ReasonDialog from '../../components/ReasonDialog.vue'

const tab = ref('review')
const listings = ref([])
const reports = ref([])
const sellers = ref([])
const orders = ref([])
const payouts = ref([])
const users = ref([])
const userQuery = ref('')
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
    else if (tab.value === 'orders') orders.value = (await api.get('/admin/orders')).data.data
    else if (tab.value === 'users') users.value = (await api.get('/admin/users', { params: { q: userQuery.value || undefined } })).data.data
    else if (tab.value === 'payouts') payouts.value = (await api.get('/admin/payouts')).data.data
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
async function setAdmin(u, isAdmin) {
  error.value = ''
  try { await api.patch(`/admin/users/${u.id}`, { is_admin: isAdmin }); await load() } catch (e) { error.value = parseError(e).message }
}
async function toggleAutoApprove(s) {
  try { await api.patch(`/admin/sellers/${s.slug}`, { auto_approve: !s.auto_approve }); await load() } catch (e) { error.value = parseError(e).message }
}

const orderLabel = { pending: 'Aguardando', paid: 'Pago', expired: 'Expirado', cancelled: 'Cancelado', refunded: 'Reembolsado' }
const refundTarget = ref(null) // pedido em confirmação de reembolso
async function refund() {
  try { await api.post(`/admin/orders/${refundTarget.value.id}/refund`); refundTarget.value = null; await load() } catch (e) { error.value = parseError(e).message; refundTarget.value = null }
}
async function markPaid(p) {
  const note = prompt(`Registrar o repasse de ${formatMoney(p.owed_cents)} para ${p.display_name}. Observação (ex.: Pix 15/10):`, '')
  if (note === null) return
  try { await api.post(`/admin/payouts/${p.seller}/mark-paid`, { note: note.trim() || null }); await load() } catch (e) { error.value = parseError(e).message }
}
async function setWallet(s) {
  const raw = prompt('Carteira Asaas (walletId) deste vendedor para o split automático. Vazio = repasse manual:', '')
  if (raw === null) return
  try { await api.patch(`/admin/sellers/${s.slug}`, { asaas_wallet_id: raw.trim() === '' ? null : raw.trim() }); await load() } catch (e) { error.value = parseError(e).message }
}
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
      <li class="nav-item"><button class="nav-link" :class="{ active: tab === 'orders' }" @click="tab = 'orders'">Pedidos</button></li>
      <li class="nav-item"><button class="nav-link" :class="{ active: tab === 'users' }" @click="tab = 'users'">Usuários</button></li>
      <li class="nav-item"><button class="nav-link" :class="{ active: tab === 'payouts' }" @click="tab = 'payouts'">Repasses</button></li>
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

    <template v-else-if="tab === 'orders'">
      <div v-if="!orders.length" class="text-center text-muted py-5 empty-state">Nenhum pedido ainda.</div>
      <div v-else class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Pedido</th><th>Comprador</th><th>Itens</th><th>Total</th><th>Comissão</th><th>Status</th><th></th></tr></thead>
        <tbody><tr v-for="o in orders" :key="o.id" class="order-row">
          <td class="small">{{ formatDate(o.created_at) }}<div class="text-muted">{{ o.gateway || '—' }}</div></td>
          <td class="small">{{ o.buyer.name }}<div class="text-muted">{{ o.buyer.email }}</div></td>
          <td class="small"><div v-for="(i, k) in o.items" :key="k">{{ i.title }}</div></td>
          <td>{{ formatMoney(o.total_cents) }}</td>
          <td>{{ formatMoney(o.platform_fee_cents) }}</td>
          <td><span class="badge text-bg-secondary">{{ orderLabel[o.status] || o.status }}</span></td>
          <td class="text-end"><button v-if="o.status === 'paid'" class="btn btn-sm btn-outline-danger" @click="refundTarget = o">Reembolsar</button></td>
        </tr></tbody>
      </table></div></div>
      <div v-if="refundTarget" class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,.5)" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
          <div class="modal-header"><h2 class="modal-title h6">Reembolsar pedido</h2></div>
          <div class="modal-body small">Devolver <strong>{{ formatMoney(refundTarget.total_cents) }}</strong> para {{ refundTarget.buyer.name }}? Os downloads deste pedido serão encerrados. Esta ação não pode ser desfeita.</div>
          <div class="modal-footer"><button class="btn btn-sm btn-outline-secondary" @click="refundTarget = null">Cancelar</button><button class="btn btn-sm btn-danger" @click="refund">Confirmar reembolso</button></div>
        </div></div>
      </div>
    </template>

    <template v-else-if="tab === 'users'">
      <p class="small text-muted">Administradores aprovam publicações, tratam denúncias, reembolsam pedidos e registram repasses. Promova só quem você confia.</p>
      <form class="input-group mb-3" style="max-width: 420px" @submit.prevent="load">
        <input v-model="userQuery" class="form-control" type="search" placeholder="Buscar por nome ou e-mail" aria-label="Buscar usuário" />
        <button class="btn btn-outline-secondary" type="submit">Buscar</button>
      </form>
      <div v-if="!users.length" class="text-center text-muted py-5 empty-state">Nenhum usuário encontrado.</div>
      <div v-else class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Usuário</th><th>Acesso</th><th></th></tr></thead>
        <tbody><tr v-for="u in users" :key="u.id" class="user-row">
          <td>{{ u.name }} <span v-if="u.is_me" class="badge text-bg-light">você</span><div class="small text-muted">{{ u.email }}</div></td>
          <td><span class="badge" :class="u.is_admin ? 'text-bg-primary' : 'text-bg-light'">{{ u.is_admin ? 'Administrador' : 'Usuário' }}</span>
            <span v-if="u.fixed_admin" class="small text-muted ms-1">(fixo no servidor)</span></td>
          <td class="text-end">
            <button v-if="!u.is_admin" class="btn btn-sm btn-outline-primary" @click="setAdmin(u, true)">Tornar administrador</button>
            <button v-else-if="!u.is_me && !u.fixed_admin" class="btn btn-sm btn-outline-danger" @click="setAdmin(u, false)">Remover acesso</button>
          </td>
        </tr></tbody>
      </table></div></div>
    </template>

    <template v-else-if="tab === 'payouts'">
      <p class="small text-muted">Vendas pagas que ainda precisam ser repassadas manualmente (vendedores sem carteira Asaas). Quem tem carteira recebe o split automático.</p>
      <div v-if="!payouts.length" class="text-center text-muted py-5 empty-state">Nada a repassar.</div>
      <div v-else class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Vendedor</th><th>Vendas</th><th>A repassar</th><th></th></tr></thead>
        <tbody><tr v-for="p in payouts" :key="p.seller" class="payout-row">
          <td>{{ p.display_name }}<div class="small text-muted">{{ p.seller }}</div></td><td>{{ p.sales }}</td><td class="fw-semibold">{{ formatMoney(p.owed_cents) }}</td>
          <td class="text-end"><button class="btn btn-sm btn-success" @click="markPaid(p)">Marcar como repassado</button></td>
        </tr></tbody>
      </table></div></div>
    </template>

    <div v-else class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Vendedor</th><th>Status</th><th>Publicações</th><th>Comissão</th><th></th></tr></thead>
      <tbody><tr v-for="s in sellers" :key="s.slug" class="seller-row">
        <td>{{ s.display_name }}<div class="small text-muted">{{ s.slug }}</div></td>
        <td><span class="badge" :class="s.status === 'active' ? 'text-bg-success' : 'text-bg-danger'">{{ s.status === 'active' ? 'Ativo' : 'Suspenso' }}</span></td>
        <td>{{ s.listings }}</td>
        <td>{{ s.effective_commission_bps / 100 }}% <span v-if="s.commission_bps == null" class="small text-muted">(padrão)</span></td>
        <td class="text-end"><button class="btn btn-sm me-1" :class="s.has_wallet ? 'btn-outline-success' : 'btn-outline-secondary'" @click="setWallet(s)">{{ s.has_wallet ? 'Carteira ✓' : 'Carteira' }}</button>
          <button class="btn btn-sm me-1" :class="s.auto_approve ? 'btn-outline-success' : 'btn-outline-secondary'" :title="s.auto_approve ? 'As publicações dele entram direto na vitrine. Clique para exigir análise.' : 'Clique para publicar as fotos dele sem análise (vendedor de confiança).'" @click="toggleAutoApprove(s)">{{ s.auto_approve ? 'Aprovação automática ✓' : 'Aprovação automática' }}</button>
          <button class="btn btn-sm btn-outline-secondary me-1" @click="setCommission(s)">Comissão</button>
          <button class="btn btn-sm" :class="s.status === 'active' ? 'btn-outline-danger' : 'btn-outline-success'" @click="setStatus(s, s.status === 'active' ? 'suspended' : 'active')">{{ s.status === 'active' ? 'Suspender' : 'Reativar' }}</button></td>
      </tr></tbody>
    </table></div></div>

    <ReasonDialog v-if="dialog" :title="dialog.kind === 'reject' ? 'Recusar publicação' : 'Suspender publicação'" label="Motivo (o vendedor vai ver)" confirm-label="Confirmar" :error="dialogError" @confirm="confirm" @cancel="dialog = null; dialogError = ''" />
  </template>
</template>
