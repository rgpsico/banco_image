<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useTenantStore } from '../stores/tenant'

const auth = useAuthStore()
const tenants = useTenantStore()
const router = useRouter()

onMounted(() => { if (!tenants.tenants.length) tenants.fetch() })

async function logout() {
  await auth.logout(); tenants.reset(); router.push({ name: 'login' })
}
</script>

<template>
  <nav class="navbar navbar-expand-md bg-white border-bottom">
    <div class="container">
      <RouterLink class="navbar-brand brand" to="/">MediaHub</RouterLink>
      <div class="d-flex flex-wrap flex-md-nowrap gap-3 align-items-center ms-auto">
        <select
          v-if="tenants.tenants.length"
          class="form-select form-select-sm"
          style="width: 220px"
          aria-label="Projeto"
          :value="tenants.currentId"
          @change="tenants.select($event.target.value)"
        >
          <option v-for="t in tenants.tenants" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
        <RouterLink v-if="tenants.current" class="nav-link p-0" to="/library">Biblioteca</RouterLink>
        <RouterLink v-if="tenants.current" class="nav-link p-0" to="/members">Membros</RouterLink>
        <RouterLink v-if="tenants.current" class="nav-link p-0" to="/settings">Configurações</RouterLink>
        <span class="text-muted small">{{ auth.user?.name }}</span>
        <button class="btn btn-outline-secondary btn-sm" @click="logout">Sair</button>
      </div>
    </div>
  </nav>
</template>
