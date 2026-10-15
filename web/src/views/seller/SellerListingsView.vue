<script setup>
import { onMounted, ref } from 'vue'
import api, { parseError } from '../../services/api'
import { formatMoney } from '../../utils/format'
import StatusBadge from '../../components/StatusBadge.vue'
import FormAlert from '../../components/FormAlert.vue'
import SellerNav from './SellerNav.vue'

const items = ref([])
const loading = ref(true)
const error = ref('')
const msg = ref('')

async function load() {
  try { items.value = (await api.get('/seller/listings')).data.data } catch (e) { error.value = parseError(e).message } finally { loading.value = false }
}
onMounted(load)

async function act(l, action) {
  error.value = ''; msg.value = ''
  try {
    if (action === 'delete') { if (!confirm(`Excluir "${l.title}"?`)) return; await api.delete(`/seller/listings/${l.id}`) } else await api.post(`/seller/listings/${l.id}/${action}`)
    msg.value = action === 'submit' ? 'Enviada! Se for aprovada, ela entra no catálogo.' : 'Atualizado.'
    await load()
  } catch (e) { error.value = parseError(e).message }
}
const minPrice = (l) => (l.prices?.length ? formatMoney(Math.min(...l.prices.map((p) => p.price_cents))) : '—')
</script>

<template>
  <h1 class="h4 mb-3">Minhas publicações</h1>
  <SellerNav />
  <FormAlert :message="error" /><FormAlert :message="msg" type="success" />
  <div v-if="loading" class="text-center py-5"><div class="spinner-border"></div></div>
  <div v-else-if="!items.length" class="text-center text-muted py-5 empty-state">Você ainda não publicou nada. <RouterLink to="/seller/listings/new">Criar a primeira publicação</RouterLink></div>
  <div v-else class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th></th><th>Título</th><th>Status</th><th>Preço mín.</th><th>Vendas</th><th></th></tr></thead>
    <tbody>
      <tr v-for="l in items" :key="l.id" class="listing-row">
        <td style="width: 70px"><img v-if="l.media?.thumbnail_url" :src="l.media.thumbnail_url" alt="" style="width: 60px; height: 45px; object-fit: cover; border-radius: 4px" /></td>
        <td>{{ l.title }}<div v-if="l.rejection_reason" class="small text-danger">Motivo: {{ l.rejection_reason }}</div></td>
        <td><StatusBadge :status="l.status" /></td>
        <td>{{ minPrice(l) }}</td>
        <td>{{ l.sales_count }}</td>
        <td class="text-end text-nowrap">
          <RouterLink class="btn btn-sm btn-outline-secondary me-1" :to="`/seller/listings/${l.id}`">Editar</RouterLink>
          <button v-if="['draft', 'rejected', 'archived'].includes(l.status)" class="btn btn-sm btn-primary me-1" @click="act(l, 'submit')">Enviar p/ análise</button>
          <button v-if="['published', 'pending_review'].includes(l.status)" class="btn btn-sm btn-outline-warning me-1" @click="act(l, 'unpublish')">Despublicar</button>
          <button v-if="['draft', 'rejected', 'archived'].includes(l.status) && !l.sales_count" class="btn btn-sm btn-outline-danger" @click="act(l, 'delete')">Excluir</button>
        </td>
      </tr>
    </tbody>
  </table></div></div>
</template>
