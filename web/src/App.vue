<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { useTenantStore } from './stores/tenant'
import { setUnauthorizedHandler } from './services/api'
import AppNavbar from './components/AppNavbar.vue'

const auth = useAuthStore()
const tenants = useTenantStore()
const router = useRouter()

onMounted(() => {
  // Token expirado/revogado em qualquer chamada: volta para o login.
  setUnauthorizedHandler(() => { auth.clear(); tenants.reset(); router.push({ name: 'login' }) })
})
</script>

<template>
  <AppNavbar v-if="auth.isAuthenticated" />
  <main class="container py-4">
    <RouterView />
  </main>
</template>
