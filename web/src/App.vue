<script setup>
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { useTenantStore } from './stores/tenant'
import { useSellerStore } from './stores/seller'
import { setUnauthorizedHandler } from './services/api'
import AppNavbar from './components/AppNavbar.vue'
import ProjectShell from './components/ProjectShell.vue'

const auth = useAuthStore()
const tenants = useTenantStore()
const seller = useSellerStore()
const router = useRouter()
const route = useRoute()

onMounted(() => {
  // Token expirado/revogado em qualquer chamada: volta para o login.
  setUnauthorizedHandler(() => { auth.clear(); tenants.reset(); seller.reset(); router.push({ name: 'login' }) })
})
</script>

<template>
  <!-- Galeria pública (meta.bare): tela cheia, sem menu do MediaHub -->
  <RouterView v-if="route.meta.bare" />
  <!-- Painel do projeto: menu lateral (computador) e abas (celular) -->
  <ProjectShell v-else-if="route.meta.layout === 'project'"><RouterView /></ProjectShell>
  <template v-else>
    <AppNavbar />
    <main class="container py-4">
      <RouterView />
    </main>
  </template>
</template>
