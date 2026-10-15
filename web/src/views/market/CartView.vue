<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { parseError } from '../../services/api'
import { useAuthStore } from '../../stores/auth'
import { useCartStore } from '../../stores/cart'
import { formatMoney } from '../../utils/format'
import FormAlert from '../../components/FormAlert.vue'
import LoadingButton from '../../components/LoadingButton.vue'

const auth = useAuthStore()
const cart = useCartStore()
const router = useRouter()
const route = useRoute()
const busy = ref(false)
const error = ref('')
const order = ref(null) // pedido criado, aguardando o pagamento

async function checkout() {
  error.value = ''
  if (!auth.isAuthenticated) return router.push({ name: 'login', query: { redirect: route.fullPath } })
  busy.value = true
  try {
    const items = cart.items.map((i) => ({ listing: i.listingId, license: i.license }))
    // Idempotency-Key: se o clique se repetir, o servidor devolve o MESMO pedido.
    const { data } = await api.post('/orders', { items }, { headers: { 'Idempotency-Key': cart.key() } })
    order.value = data.data
    await api.post(`/orders/${order.value.id}/pay`)
  } catch (e) { error.value = parseError(e).message } finally { busy.value = false }
}

async function confirmSimulated() {
  busy.value = true; error.value = ''
  try {
    await api.post(`/orders/${order.value.id}/simulate-payment`)
    cart.clear()
    router.push({ name: 'purchases', query: { novo: '1' } })
  } catch (e) { error.value = parseError(e).message } finally { busy.value = false }
}
</script>

<template>
  <h1 class="h4 mb-3">Carrinho</h1>

  <div v-if="!cart.count && !order" class="text-center text-muted py-5 empty-state">
    <div class="fs-1" aria-hidden="true">🛒</div>
    <p>Seu carrinho está vazio.</p>
    <RouterLink to="/market" class="btn btn-primary btn-sm">Explorar o marketplace</RouterLink>
  </div>

  <div v-else class="row g-4">
    <div class="col-lg-8">
      <FormAlert :message="error" />

      <div v-if="!order" class="card shadow-sm">
        <ul class="list-group list-group-flush">
          <li v-for="(i, idx) in cart.items" :key="i.listingId + i.license" class="list-group-item d-flex gap-3 align-items-center cart-item">
            <img :src="i.thumbnail_url" :alt="i.title" style="width: 72px; height: 54px; object-fit: cover; border-radius: 6px" />
            <div class="flex-grow-1">
              <div class="fw-semibold">{{ i.title }}</div>
              <div class="small text-muted">Licença {{ i.licenseName }}</div>
            </div>
            <div class="fw-semibold">{{ formatMoney(i.price_cents) }}</div>
            <button class="btn btn-sm btn-outline-danger" aria-label="Remover" @click="cart.remove(idx)">✕</button>
          </li>
        </ul>
      </div>

      <div v-else class="card shadow-sm border-warning">
        <div class="card-body">
          <h2 class="h6">Pedido criado · aguardando pagamento</h2>
          <div class="alert alert-warning small">
            <strong>Pagamento de teste.</strong> Este ambiente usa um gateway simulado: nenhum valor real é cobrado.
            O pagamento real (Pix/cartão pelo Asaas) entra na fase de produção.
          </div>
          <ul class="mb-3">
            <li v-for="it in order.items" :key="it.id">{{ it.title }} · {{ it.license_name }} · {{ formatMoney(it.price_cents) }}</li>
          </ul>
          <LoadingButton type="button" :loading="busy" variant="success" @click="confirmSimulated">Confirmar pagamento de teste · {{ formatMoney(order.total_cents) }}</LoadingButton>
        </div>
      </div>
    </div>

    <div v-if="!order" class="col-lg-4">
      <div class="card shadow-sm"><div class="card-body">
        <div class="d-flex justify-content-between mb-2"><span>Itens</span><span>{{ cart.count }}</span></div>
        <div class="d-flex justify-content-between fs-5 fw-bold mb-3"><span>Total</span><span>{{ formatMoney(cart.total) }}</span></div>
        <LoadingButton type="button" :loading="busy" class="w-100" @click="checkout">{{ auth.isAuthenticated ? 'Finalizar compra' : 'Entrar para comprar' }}</LoadingButton>
        <div class="form-text mt-2">Você recebe o arquivo original e a licença escolhida logo após o pagamento.</div>
      </div></div>
    </div>
  </div>
</template>
