<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useTenantStore } from '../stores/tenant'
import api, { parseError } from '../services/api'
import { formatBytes, formatDate } from '../utils/format'
import FormField from '../components/FormField.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingButton from '../components/LoadingButton.vue'
import Icon from '../components/Icon.vue'

const auth = useAuthStore()
const tenants = useTenantStore()
const name = ref('')
const creating = ref(false)
const showNew = ref(false)
const error = ref('')
const fields = ref({})
const ov = ref(null) // visão geral do projeto atual

const firstName = computed(() => (auth.user?.name || '').split(' ')[0])
const pct = (t) => Math.min(100, Math.round((t.storage.used_bytes / t.storage.quota_bytes) * 100))

async function loadOverview() {
  ov.value = null
  if (!tenants.currentId) return
  try { ov.value = (await api.get(`/tenants/${tenants.currentId}/overview`)).data.data } catch { ov.value = null }
}
onMounted(async () => { await tenants.fetch(); loadOverview() })
watch(() => tenants.currentId, loadOverview)

async function createTenant() {
  creating.value = true; error.value = ''; fields.value = {}
  try { await tenants.create(name.value); name.value = ''; showNew.value = false } catch (e) {
    const p = parseError(e); error.value = p.message; fields.value = p.fields
  } finally { creating.value = false }
}

// Três passos para o primeiro resultado. Cada um se marca sozinho quando é cumprido.
const steps = computed(() => {
  const o = ov.value || {}
  return [
    { done: (o.photos || 0) > 0, title: 'Envie as suas fotos', text: 'Suba as fotos na biblioteca, direto do celular ou do computador.', to: '/library', cta: 'Enviar fotos', icon: 'upload' },
    { done: (o.galleries || 0) > 0, title: 'Crie uma galeria', text: 'Uma galeria vira um link e um QR code para os seus clientes.', to: '/galleries', cta: 'Criar galeria', icon: 'link' },
    { done: (o.gallery_views || 0) > 0, title: 'Compartilhe o link', text: 'Mande pelo WhatsApp ou imprima o QR. Aqui aparece quando alguém abrir.', to: '/galleries', cta: 'Ver o link', icon: 'qr' },
  ]
})
const nextStep = computed(() => steps.value.findIndex((s) => !s.done))
</script>

<template>
  <div v-if="tenants.loading && !tenants.tenants.length" class="text-center py-5"><div class="spinner-border" role="status"></div></div>

  <div v-else>
    <div class="page-head">
      <div><h1>Olá, {{ firstName }} 👋</h1><p>Organize as fotos dos seus projetos e compartilhe galerias com os seus clientes.</p></div>
      <div class="page-actions"><button class="btn btn-primary" type="button" @click="showNew = !showNew"><Icon name="plus" :size="18" /> Novo projeto</button></div>
    </div>

    <form v-if="showNew || !tenants.tenants.length" class="card mb-4" @submit.prevent="createTenant"><div class="card-body">
      <h2 class="h6 mb-1">{{ tenants.tenants.length ? 'Novo projeto' : 'Comece criando o seu primeiro projeto' }}</h2>
      <p class="small text-muted mb-3">Um projeto é um espaço separado, com biblioteca, galerias e pessoas próprias. Ex.: o nome de um cliente ou do seu estúdio.</p>
      <FormAlert :message="error" />
      <div class="row g-2 align-items-end">
        <div class="col-md-8"><FormField v-model="name" label="Nome do projeto" :error="fields.name" required /></div>
        <div class="col-md-4 mb-3"><LoadingButton :loading="creating" class="w-100">Criar projeto</LoadingButton></div>
      </div>
    </div></form>

    <!-- Projeto atual: números e primeiros passos -->
    <section v-if="tenants.current" class="mb-4">
      <div class="d-flex align-items-center gap-2 mb-2"><h2 class="h5 mb-0">{{ tenants.current.name }}</h2><span class="badge text-bg-light border role-badge">{{ tenants.current.role }}</span></div>

      <div v-if="ov" class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card stat"><div class="num">{{ ov.photos }}</div><div class="lbl">Fotos</div></div></div>
        <div class="col-6 col-md-3"><div class="card stat"><div class="num">{{ ov.galleries }}</div><div class="lbl">{{ ov.galleries === 1 ? 'Galeria' : 'Galerias' }}</div></div></div>
        <div class="col-6 col-md-3"><div class="card stat"><div class="num">{{ ov.gallery_views }}</div><div class="lbl">Visitas nas galerias</div></div></div>
        <div class="col-6 col-md-3"><div class="card stat"><div class="num fs-6 pt-2">{{ ov.last_upload_at ? formatDate(ov.last_upload_at) : '—' }}</div><div class="lbl">Último envio</div></div></div>
      </div>

      <div class="card"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h3 class="h6 mb-0">{{ nextStep === -1 ? 'Tudo pronto 🎉' : 'Primeiros passos' }}</h3>
          <span class="small text-muted">{{ steps.filter((s) => s.done).length }} de 3</span>
        </div>
        <ol class="steps list-unstyled mb-0">
          <li v-for="(s, i) in steps" :key="s.title" class="step" :class="{ done: s.done, now: i === nextStep }">
            <span class="step-mark"><Icon v-if="s.done" name="check" :size="16" /><template v-else>{{ i + 1 }}</template></span>
            <div class="step-body"><div class="fw-semibold">{{ s.title }}</div><div class="small text-muted">{{ s.text }}</div></div>
            <RouterLink v-if="!s.done" class="btn btn-sm" :class="i === nextStep ? 'btn-primary' : 'btn-outline-secondary'" :to="s.to">{{ s.cta }}</RouterLink>
          </li>
        </ol>
      </div></div>
    </section>

    <!-- Todos os projetos -->
    <section v-if="tenants.tenants.length">
      <h2 class="h5 mb-3">Seus projetos</h2>
      <div class="row g-3">
        <div v-for="t in tenants.tenants" :key="t.id" class="col-md-6 col-xl-4">
          <div class="card h-100" :class="{ 'border-primary': t.id === tenants.currentId }"><div class="card-body d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <h3 class="h6 mb-0">{{ t.name }}</h3><span class="badge text-bg-light border role-badge">{{ t.role }}</span>
            </div>
            <div class="small text-muted mt-3 d-flex justify-content-between"><span>Armazenamento</span><span>{{ formatBytes(t.storage.used_bytes) }} de {{ formatBytes(t.storage.quota_bytes) }}</span></div>
            <div class="progress mt-1" style="height: 6px"><div class="progress-bar" :class="{ 'bg-danger': pct(t) > 90 }" :style="{ width: pct(t) + '%' }"></div></div>
            <div class="mt-auto pt-3 d-flex gap-2">
              <button v-if="t.id !== tenants.currentId" class="btn btn-sm btn-outline-primary flex-fill" type="button" @click="tenants.select(t.id)">Abrir este projeto</button>
              <template v-else><RouterLink class="btn btn-sm btn-primary flex-fill" to="/library">Biblioteca</RouterLink><RouterLink class="btn btn-sm btn-outline-primary flex-fill" to="/galleries">Galerias</RouterLink></template>
            </div>
          </div></div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.step { display: flex; align-items: center; gap: 14px; padding: 12px 0; border-top: 1px solid var(--mh-line); }
.step:first-child { border-top: 0; padding-top: 0; }
.step-mark { width: 32px; height: 32px; border-radius: 50%; flex: none; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; background: #edf0f6; color: var(--mh-muted); }
.step.now .step-mark { background: var(--mh-brand); color: #fff; }
.step.done .step-mark { background: #d1fae5; color: #047857; }
.step-body { flex: 1; min-width: 0; }
.step.done .step-body .fw-semibold { color: var(--mh-muted); }
@media (max-width: 575.98px) { .step { flex-wrap: wrap; } .step .btn { width: 100%; margin-left: 46px; width: calc(100% - 46px); } }
</style>
