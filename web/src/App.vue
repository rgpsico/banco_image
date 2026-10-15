<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { useTenantStore } from './stores/tenant'
import { useSellerStore } from './stores/seller'
import { setUnauthorizedHandler } from './services/api'
import AppNavbar from './components/AppNavbar.vue'

const auth = useAuthStore()
const tenants = useTenantStore()
const seller = useSellerStore()
const router = useRouter()

onMounted(() => {
  // Token expirado/revogado em qualquer chamada: volta para o login.
  setUnauthorizedHandler(() => { auth.clear(); tenants.reset(); seller.reset(); router.push({ name: 'login' }) })
})
</script>

<template>
  <AppNavbar />
  <main class="container py-4">
    <RouterView />
  </main>
</template>
