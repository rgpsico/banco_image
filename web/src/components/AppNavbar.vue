<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useTenantStore } from '../stores/tenant'
import { useCartStore } from '../stores/cart'
import { useSellerStore } from '../stores/seller'

const auth = useAuthStore()
const tenants = useTenantStore()
const cart = useCartStore()
const seller = useSellerStore()
const router = useRouter()
const open = ref(false)
const sellerLink = computed(() => (seller.profile ? '/seller' : '/seller/join'))

function boot() {
  if (!auth.isAuthenticated) return
  if (!tenants.tenants.length) tenants.fetch()
  if (!seller.loaded) seller.fetch()
}
onMounted(boot)
watch(() => auth.isAuthenticated, boot)

async function logout() {
  await auth.logout(); tenants.reset(); seller.reset(); open.value = false; router.push({ name: 'login' })
}
</script>

<template>
  <nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container">
      <RouterLink class="navbar-brand brand" to="/market">MediaHub</RouterLink>
      <button class="navbar-toggler" type="button" aria-label="Menu" @click="open = !open"><span class="navbar-toggler-icon"></span></button>

      <div class="navbar-collapse" :class="{ collapse: !open }">
        <ul class="navbar-nav me-auto gap-lg-2 align-items-lg-center" @click="open = false">
          <li class="nav-item"><RouterLink class="nav-link" to="/market">Marketplace</RouterLink></li>
          <template v-if="auth.isAuthenticated">
            <li class="nav-item"><RouterLink class="nav-link" to="/purchases">Minhas compras</RouterLink></li>
            <li class="nav-item"><RouterLink class="nav-link" :to="sellerLink">Vender</RouterLink></li>
            <li v-if="auth.user?.is_admin" class="nav-item"><RouterLink class="nav-link" to="/admin">Moderação</RouterLink></li>
          </template>
        </ul>

        <div class="d-flex flex-wrap gap-3 align-items-center" @click="open = false">
          <RouterLink class="nav-link p-0 position-relative" to="/cart" aria-label="Carrinho">
            🛒 Carrinho <span v-if="cart.count" class="badge rounded-pill text-bg-primary">{{ cart.count }}</span>
          </RouterLink>
          <template v-if="auth.isAuthenticated">
            <span class="text-muted small">{{ auth.user?.name }}</span>
            <button class="btn btn-outline-secondary btn-sm" @click.stop="logout">Sair</button>
          </template>
          <template v-else>
            <RouterLink class="btn btn-outline-primary btn-sm" to="/login">Entrar</RouterLink>
            <RouterLink class="btn btn-primary btn-sm" to="/register">Criar conta</RouterLink>
          </template>
        </div>
      </div>
    </div>
  </nav>

  <div v-if="auth.isAuthenticated && tenants.tenants.length" class="project-bar border-bottom bg-light">
    <div class="container d-flex flex-wrap gap-3 align-items-center py-1 small">
      <span class="text-muted">Projeto:</span>
      <select class="form-select form-select-sm" style="width: 200px" aria-label="Projeto" :value="tenants.currentId" @change="tenants.select($event.target.value)">
        <option v-for="t in tenants.tenants" :key="t.id" :value="t.id">{{ t.name }}</option>
      </select>
      <template v-if="tenants.current">
        <RouterLink class="text-decoration-none" to="/library">Biblioteca</RouterLink>
        <RouterLink class="text-decoration-none" to="/members">Membros</RouterLink>
        <RouterLink class="text-decoration-none" to="/settings">Configurações</RouterLink>
      </template>
      <RouterLink class="text-decoration-none ms-auto" to="/">Todos os projetos</RouterLink>
    </div>
  </div>
</template>
