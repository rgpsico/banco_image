<script setup>
import { computed, onMounted, ref } from 'vue'
import api, { parseError } from '../../services/api'
import { useSellerStore } from '../../stores/seller'
import { formatMoney } from '../../utils/format'
import SellerNav from './SellerNav.vue'

const seller = useSellerStore()
const stats = ref(null)
const error = ref('')
onMounted(async () => { try { stats.value = (await api.get('/seller/stats')).data.data } catch (e) { error.value = parseError(e).message } })
const max = computed(() => Math.max(1, ...(stats.value?.last_30_days || []).map((d) => d.earnings_cents)))
const LABEL = { draft: 'Rascunhos', pending_review: 'Em análise', published: 'Publicadas', rejected: 'Recusadas', suspended: 'Suspensas', archived: 'Arquivadas' }
</script>

<template>
  <h1 class="h4 mb-1">Painel do vendedor</h1>
  <p class="text-muted" v-if="seller.profile">{{ seller.profile.display_name }} ·
    <RouterLink :to="{ name: 'seller-public', params: { slug: seller.profile.slug } }">ver meu perfil público</RouterLink></p>
  <SellerNav />
  <div v-if="error" class="alert alert-danger">{{ error }}</div>
  <div v-else-if="!stats" class="text-center py-5"><div class="spinner-border"></div></div>

  <template v-else>
    <div class="row g-3 mb-4">
      <div class="col-6 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Vendas</div><div class="fs-4 fw-bold" data-k="sales">{{ stats.sales_count }}</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Vendido (bruto)</div><div class="fs-4 fw-bold">{{ formatMoney(stats.gross_cents) }}</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Comissão da plataforma</div><div class="fs-4 fw-bold">{{ formatMoney(stats.platform_fee_cents) }}</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card shadow-sm border-success"><div class="card-body"><div class="small text-muted">Você recebe</div><div class="fs-4 fw-bold text-success" data-k="earnings">{{ formatMoney(stats.earnings_cents) }}</div></div></div></div>
    </div>

    <div class="card shadow-sm mb-4"><div class="card-body">
      <h2 class="h6">Ganhos nos últimos 30 dias</h2>
      <div class="d-flex align-items-end gap-1" style="height: 110px">
        <div v-for="d in stats.last_30_days" :key="d.date" class="flex-fill bar" :title="`${d.date}: ${formatMoney(d.earnings_cents)} (${d.sales} venda(s))`"
             :style="{ height: Math.max(3, (d.earnings_cents / max) * 100) + '%', background: d.earnings_cents ? '#198754' : '#dee2e6' }"></div>
      </div>
    </div></div>

    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h6">Suas publicações</h2>
      <div v-if="!Object.keys(stats.listings).length" class="text-muted small">Nenhuma ainda. <RouterLink to="/seller/listings/new">Crie a primeira</RouterLink>.</div>
      <span v-for="(n, s) in stats.listings" :key="s" class="badge text-bg-light me-2">{{ LABEL[s] || s }}: {{ n }}</span>
      <div class="small text-muted mt-2">{{ stats.views_total }} visualizações no total</div>
    </div></div>
  </template>
</template>
