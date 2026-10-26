<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useTenantStore } from '../stores/tenant'
import { useSellerStore } from '../stores/seller'
import Icon from './Icon.vue'

// Layout do painel do projeto: menu lateral no computador e abas fixas embaixo no celular.
const auth = useAuthStore()
const tenants = useTenantStore()
const seller = useSellerStore()
const route = useRoute()
const router = useRouter()
const menu = ref(false)

// O layout carrega o que o menu antigo carregava: lista de projetos e perfil de vendedor.
function boot() {
  if (!auth.isAuthenticated) return
  if (!tenants.tenants.length) tenants.fetch()
  if (!seller.loaded) seller.fetch()
}
onMounted(boot)
watch(() => auth.isAuthenticated, boot)

const nav = [
  { to: '/', label: 'Visão geral', icon: 'home', exact: true },
  { to: '/library', label: 'Biblioteca', icon: 'image' },
  { to: '/galleries', label: 'Galerias', icon: 'link' },
  { to: '/members', label: 'Membros', icon: 'users' },
  { to: '/settings', label: 'Configurações', icon: 'settings' },
]
const isActive = (item) => (item.exact ? route.path === item.to : route.path.startsWith(item.to))
const sellerLink = computed(() => (seller.profile ? '/seller' : '/seller/join'))
const firstName = computed(() => (auth.user?.name || '').split(' ')[0])
const initial = computed(() => (auth.user?.name || '?').trim().charAt(0).toUpperCase())

async function logout() {
  await auth.logout(); tenants.reset(); seller.reset(); menu.value = false; router.push({ name: 'login' })
}
</script>

<template>
  <div class="shell">
    <!-- Menu lateral (computador) -->
    <aside class="side d-none d-lg-flex">
      <RouterLink class="side-brand" to="/"><span class="logo-dot"></span>MediaHub</RouterLink>

      <div v-if="tenants.tenants.length" class="side-project">
        <label class="side-label" for="proj-desktop">Projeto</label>
        <div class="select-wrap">
          <select id="proj-desktop" class="form-select" :value="tenants.currentId" @change="tenants.select($event.target.value)">
            <option v-for="t in tenants.tenants" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
        </div>
      </div>

      <nav class="side-nav" aria-label="Painel do projeto">
        <RouterLink v-for="n in nav" :key="n.to" :to="n.to" class="side-link" :class="{ on: isActive(n) }"><Icon :name="n.icon" />{{ n.label }}</RouterLink>
      </nav>

      <div class="side-sep">Outros</div>
      <nav class="side-nav" aria-label="Outras áreas">
        <RouterLink to="/market" class="side-link"><Icon name="shop" />Marketplace</RouterLink>
        <RouterLink to="/purchases" class="side-link"><Icon name="tag" />Minhas compras</RouterLink>
        <RouterLink :to="sellerLink" class="side-link"><Icon name="camera" />Vender fotos</RouterLink>
        <RouterLink v-if="auth.user?.is_admin" to="/admin" class="side-link"><Icon name="shield" />Moderação</RouterLink>
      </nav>

      <div class="side-user">
        <span class="avatar">{{ initial }}</span>
        <div class="side-user-name text-truncate">{{ auth.user?.name }}</div>
        <button class="btn btn-sm btn-outline-secondary" type="button" title="Sair" aria-label="Sair" @click="logout"><Icon name="logout" :size="16" /></button>
      </div>
    </aside>

    <div class="shell-main">
      <!-- Topo (celular) -->
      <header class="topbar d-lg-none">
        <RouterLink class="side-brand" to="/"><span class="logo-dot"></span>MediaHub</RouterLink>
        <div v-if="tenants.tenants.length" class="topbar-project">
          <select class="form-select form-select-sm" aria-label="Projeto" :value="tenants.currentId" @change="tenants.select($event.target.value)">
            <option v-for="t in tenants.tenants" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
        </div>
        <button class="btn btn-light btn-sm icon-btn" type="button" aria-label="Menu" @click="menu = true"><span class="avatar avatar-sm">{{ initial }}</span></button>
      </header>

      <main class="content"><slot /></main>
    </div>

    <!-- Abas (celular) -->
    <nav class="tabbar d-lg-none" aria-label="Painel do projeto">
      <RouterLink v-for="n in nav" :key="n.to" :to="n.to" class="tab" :class="{ on: isActive(n) }"><Icon :name="n.icon" :size="22" /><span>{{ n.label.replace('Visão geral', 'Início').replace('Configurações', 'Config.') }}</span></RouterLink>
    </nav>

    <!-- Menu da conta (celular) -->
    <div v-if="menu" class="sheet-backdrop d-lg-none" @click.self="menu = false">
      <div class="sheet" role="dialog" aria-modal="true" aria-label="Menu da conta">
        <div class="sheet-head"><span class="avatar">{{ initial }}</span><div><div class="fw-semibold">{{ auth.user?.name }}</div><div class="small text-muted">{{ auth.user?.email }}</div></div></div>
        <RouterLink to="/market" class="sheet-link" @click="menu = false"><Icon name="shop" />Marketplace</RouterLink>
        <RouterLink to="/purchases" class="sheet-link" @click="menu = false"><Icon name="tag" />Minhas compras</RouterLink>
        <RouterLink :to="sellerLink" class="sheet-link" @click="menu = false"><Icon name="camera" />Vender fotos</RouterLink>
        <RouterLink v-if="auth.user?.is_admin" to="/admin" class="sheet-link" @click="menu = false"><Icon name="shield" />Moderação</RouterLink>
        <button class="sheet-link text-danger" type="button" @click="logout"><Icon name="logout" />Sair</button>
        <button class="btn btn-light w-100 mt-2" type="button" @click="menu = false">Fechar</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.shell { min-height: 100vh; display: flex; }
.side { width: var(--mh-sidebar); flex: none; position: sticky; top: 0; height: 100vh; background: var(--mh-surface); border-right: 1px solid var(--mh-line); padding: 18px 14px; flex-direction: column; gap: 6px; overflow-y: auto; }
.side-brand { display: inline-flex; align-items: center; gap: 9px; font-weight: 800; font-size: 1.2rem; letter-spacing: -0.03em; color: var(--mh-ink); text-decoration: none; padding: 4px 8px 12px; }
.logo-dot { width: 22px; height: 22px; border-radius: 7px; background: linear-gradient(135deg, #ff9a4d, var(--mh-brand)); display: inline-block; box-shadow: 0 2px 6px rgba(242, 106, 27, .35); }
.side-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: var(--mh-muted); font-weight: 700; margin: 0 8px 4px; display: block; }
.side-project { margin-bottom: 10px; }
.side-nav { display: flex; flex-direction: column; gap: 2px; }
.side-link { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 10px; color: #344054; text-decoration: none; font-weight: 600; font-size: .95rem; }
.side-link:hover { background: #f3f4f8; color: var(--mh-ink); }
.side-link.on { background: var(--mh-brand-soft); color: var(--mh-brand-dark); }
.side-sep { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: var(--mh-muted); font-weight: 700; margin: 16px 12px 4px; }
.side-user { margin-top: auto; display: flex; align-items: center; gap: 10px; padding: 12px 8px 2px; border-top: 1px solid var(--mh-line); }
.side-user-name { flex: 1; font-size: .9rem; font-weight: 600; }
.avatar { width: 34px; height: 34px; border-radius: 50%; background: var(--mh-brand-soft); color: var(--mh-brand-dark); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; flex: none; }
.avatar-sm { width: 30px; height: 30px; font-size: .85rem; }

.shell-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.content { padding: 28px 32px 48px; max-width: 1180px; width: 100%; margin: 0 auto; }

.topbar { position: sticky; top: 0; z-index: 1020; display: flex; align-items: center; gap: 10px; padding: 8px 14px; background: rgba(255, 255, 255, .92); backdrop-filter: blur(8px); border-bottom: 1px solid var(--mh-line); }
.topbar .side-brand { padding: 0; font-size: 1.05rem; }
.topbar-project { flex: 1; min-width: 0; }
.topbar-project .form-select { min-height: 40px; }
.icon-btn { padding: 4px; border-radius: 50%; min-height: 0 !important; background: transparent; border: 0; }

.tabbar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1030; height: calc(var(--mh-tabbar) + env(safe-area-inset-bottom, 0px)); padding-bottom: env(safe-area-inset-bottom, 0px);
  background: rgba(255, 255, 255, .96); backdrop-filter: blur(10px); border-top: 1px solid var(--mh-line); display: grid; grid-template-columns: repeat(5, 1fr); }
.tab { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; color: #667085; text-decoration: none; font-size: .68rem; font-weight: 600; min-height: 48px; }
.tab.on { color: var(--mh-brand-dark); }
.tab.on svg { stroke-width: 2.2; }

.sheet-backdrop { position: fixed; inset: 0; z-index: 1050; background: rgba(16, 24, 40, .5); display: flex; align-items: flex-end; }
.sheet { background: #fff; width: 100%; border-radius: 18px 18px 0 0; padding: 18px 16px calc(16px + env(safe-area-inset-bottom, 0px)); }
.sheet-head { display: flex; gap: 12px; align-items: center; padding-bottom: 12px; margin-bottom: 6px; border-bottom: 1px solid var(--mh-line); }
.sheet-link { display: flex; align-items: center; gap: 14px; width: 100%; padding: 14px 6px; background: none; border: 0; text-align: left; color: var(--mh-ink); text-decoration: none; font-weight: 600; font-size: 1rem; }

@media (max-width: 991.98px) {
  .content { padding: 18px 14px calc(var(--mh-tabbar) + 28px + env(safe-area-inset-bottom, 0px)); }
}
</style>
