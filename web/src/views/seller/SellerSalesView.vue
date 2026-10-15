<script setup>
import { onMounted, ref } from 'vue'
import api, { parseError } from '../../services/api'
import { formatDate, formatMoney } from '../../utils/format'
import FormAlert from '../../components/FormAlert.vue'
import PaginationBar from '../../components/PaginationBar.vue'
import SellerNav from './SellerNav.vue'

const tab = ref('sales')
const sales = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const report = ref(null)
const from = ref('')
const to = ref('')
const loading = ref(true)
const error = ref('')

async function loadSales(page = 1) {
  try { const { data } = await api.get('/seller/sales', { params: { page } }); sales.value = data.data; meta.value = data.meta } catch (e) { error.value = parseError(e).message } finally { loading.value = false }
}
async function loadReport() {
  const params = {}; if (from.value) params.from = from.value; if (to.value) params.to = to.value
  try { report.value = (await api.get('/seller/reports/commissions', { params })).data.data } catch (e) { error.value = parseError(e).message }
}
onMounted(() => { loadSales(); loadReport() })
</script>

<template>
  <h1 class="h4 mb-3">Vendas e comissões</h1>
  <SellerNav />
  <FormAlert :message="error" />
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link" :class="{ active: tab === 'sales' }" @click="tab = 'sales'">Histórico de vendas</button></li>
    <li class="nav-item"><button class="nav-link" :class="{ active: tab === 'commissions' }" @click="tab = 'commissions'">Relatório de comissões</button></li>
  </ul>

  <template v-if="tab === 'sales'">
    <div v-if="loading" class="text-center py-4"><div class="spinner-border"></div></div>
    <div v-else-if="!sales.length" class="text-center text-muted py-5 empty-state">Nenhuma venda confirmada ainda.</div>
    <div v-else class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Mídia</th><th>Licença</th><th>Comprador</th><th class="text-end">Preço</th><th class="text-end">Comissão</th><th class="text-end">Você recebe</th><th>Data</th></tr></thead>
      <tbody><tr v-for="s in sales" :key="s.id" class="sale-row">
        <td>{{ s.title }}</td><td>{{ s.license_name }}</td><td>{{ s.buyer }}</td>
        <td class="text-end">{{ formatMoney(s.price_cents) }}</td>
        <td class="text-end text-muted">{{ formatMoney(s.platform_fee_cents) }} <span class="small">({{ s.commission_bps / 100 }}%)</span></td>
        <td class="text-end fw-semibold text-success">{{ formatMoney(s.earnings_cents) }}</td><td>{{ formatDate(s.paid_at) }}</td>
      </tr></tbody>
    </table></div></div>
    <PaginationBar :page="meta.current_page" :last="meta.last_page" :total="meta.total" @go="loadSales($event)" />
  </template>

  <template v-else>
    <form class="row g-2 align-items-end mb-3" @submit.prevent="loadReport">
      <div class="col-auto"><label class="form-label small mb-0">De</label><input v-model="from" type="date" class="form-control form-control-sm" /></div>
      <div class="col-auto"><label class="form-label small mb-0">Até</label><input v-model="to" type="date" class="form-control form-control-sm" /></div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Filtrar</button></div>
    </form>
    <div v-if="report" class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Mês</th><th class="text-end">Vendas</th><th class="text-end">Bruto</th><th class="text-end">Comissão</th><th class="text-end">Líquido</th></tr></thead>
      <tbody>
        <tr v-for="r in report.rows" :key="r.month" class="commission-row"><td>{{ r.month }}</td><td class="text-end">{{ r.sales }}</td><td class="text-end">{{ formatMoney(r.gross_cents) }}</td><td class="text-end text-muted">{{ formatMoney(r.platform_fee_cents) }}</td><td class="text-end fw-semibold">{{ formatMoney(r.earnings_cents) }}</td></tr>
        <tr v-if="!report.rows.length"><td colspan="5" class="text-center text-muted py-3">Sem vendas no período.</td></tr>
      </tbody>
      <tfoot><tr class="table-light fw-bold"><td>Total</td><td class="text-end">{{ report.totals.sales }}</td><td class="text-end">{{ formatMoney(report.totals.gross_cents) }}</td><td class="text-end">{{ formatMoney(report.totals.platform_fee_cents) }}</td><td class="text-end">{{ formatMoney(report.totals.earnings_cents) }}</td></tr></tfoot>
    </table></div></div>
    <div class="form-text mt-2">O repasse automático ao vendedor (split) entra com o Asaas na fase de produção.</div>
  </template>
</template>
