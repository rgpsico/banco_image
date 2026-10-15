<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useTenantStore } from '../stores/tenant'
import api, { parseError } from '../services/api'
import { formatDate } from '../utils/format'
import FormField from '../components/FormField.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingButton from '../components/LoadingButton.vue'

const tenants = useTenantStore()
const router = useRouter()
const name = ref('')
const loading = ref(false)
const error = ref('')
const ok = ref('')

watch(() => tenants.current, (t) => { name.value = t?.name || '' }, { immediate: true })

async function save() {
  loading.value = true; error.value = ''; ok.value = ''
  try { await tenants.update(tenants.currentId, name.value); ok.value = 'Salvo.' } catch (e) { error.value = parseError(e).message } finally { loading.value = false }
}

// ---- Chaves de API do projeto (para outros sistemas lerem as imagens) ----
const keys = ref([])
const keyName = ref('')
const keyDays = ref('')
const keyBusy = ref(false)
const keyError = ref('')
const newKey = ref(null) // { name, key } — a chave completa só existe aqui, logo após criar
const copied = ref(false)
const revokeTarget = ref(null)
const baseUrl = computed(() => window.location.origin + '/api/v1/integration')

async function loadKeys() {
  keyError.value = ''
  if (!tenants.currentId || !tenants.can('admin')) { keys.value = []; return }
  try { keys.value = (await api.get(`/tenants/${tenants.currentId}/api-keys`)).data.data } catch (e) { keyError.value = parseError(e).message }
}
watch(() => tenants.currentId, () => { newKey.value = null; loadKeys() }, { immediate: true })

async function createKey() {
  keyBusy.value = true; keyError.value = ''; copied.value = false
  try {
    const { data } = await api.post(`/tenants/${tenants.currentId}/api-keys`, { name: keyName.value, expires_in_days: keyDays.value ? Number(keyDays.value) : null })
    newKey.value = { name: data.data.name, key: data.data.key }
    keyName.value = ''; keyDays.value = ''
    await loadKeys()
  } catch (e) { keyError.value = parseError(e).message } finally { keyBusy.value = false }
}

async function copyKey() {
  try { await navigator.clipboard.writeText(newKey.value.key); copied.value = true } catch { document.getElementById('new-api-key')?.select() }
}

async function revokeKey() {
  try { await api.delete(`/tenants/${tenants.currentId}/api-keys/${revokeTarget.value.id}`); revokeTarget.value = null; await loadKeys() } catch (e) { keyError.value = parseError(e).message; revokeTarget.value = null }
}
const statusLabel = { active: 'Ativa', revoked: 'Revogada', expired: 'Expirada' }

async function destroy() {
  if (!confirm(`Excluir o projeto "${tenants.current.name}"? Esta ação afeta todos os membros.`)) return
  try { await tenants.remove(tenants.currentId); router.push({ name: 'dashboard' }) } catch (e) { error.value = parseError(e).message }
}
</script>

<template>
  <div v-if="!tenants.current" class="alert alert-info">Selecione ou crie um projeto primeiro.</div>
  <div v-else style="max-width: 760px">
    <h1 class="h4 mb-3">Configurações do projeto</h1>
    <FormAlert :message="error" />
    <FormAlert :message="ok" type="success" />
    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <form @submit.prevent="save">
          <FormField v-model="name" label="Nome do projeto" :required="true" />
          <LoadingButton :loading="loading" :disabled="!tenants.can('admin')">Salvar</LoadingButton>
          <div v-if="!tenants.can('admin')" class="form-text">Somente administradores podem alterar.</div>
        </form>
      </div>
    </div>
    <div v-if="tenants.can('admin')" class="card shadow-sm mb-4" id="api-keys">
      <div class="card-body">
        <h2 class="h6">Chaves de API</h2>
        <p class="small text-muted">
          Deixe outro sistema seu (ex.: SurfGestão, Comunidade PPG) buscar as imagens <strong>deste projeto</strong>. Cada chave é <strong>somente leitura</strong>
          e só enxerga este projeto. Crie uma chave por sistema, para poder revogar uma sem afetar as outras.
        </p>
        <FormAlert :message="keyError" />

        <div v-if="newKey" class="alert alert-warning">
          <strong>Chave "{{ newKey.name }}" criada.</strong> Copie agora: por segurança ela <strong>não será mostrada de novo</strong>.
          <div class="input-group my-2">
            <input id="new-api-key" class="form-control font-monospace" readonly :value="newKey.key" @focus="$event.target.select()" />
            <button class="btn btn-dark" type="button" @click="copyKey">{{ copied ? 'Copiada ✓' : 'Copiar' }}</button>
          </div>
          <div class="small">Guarde-a no arquivo de configuração do outro sistema (nunca no código nem no navegador).</div>
          <button class="btn btn-sm btn-outline-secondary mt-2" type="button" @click="newKey = null">Já guardei</button>
        </div>

        <form class="row g-2 align-items-end mb-3" @submit.prevent="createKey">
          <div class="col-sm-5"><label class="form-label small mb-1" for="key-name">Nome (para qual sistema?)</label>
            <input id="key-name" v-model="keyName" class="form-control" placeholder="Ex.: SurfGestão" maxlength="80" required /></div>
          <div class="col-sm-4"><label class="form-label small mb-1" for="key-days">Validade</label>
            <select id="key-days" v-model="keyDays" class="form-select">
              <option value="">Sem expiração</option><option value="30">30 dias</option><option value="90">90 dias</option><option value="365">1 ano</option>
            </select></div>
          <div class="col-sm-3"><LoadingButton :loading="keyBusy" class="w-100">Criar chave</LoadingButton></div>
        </form>

        <div v-if="keys.length" class="table-responsive mb-3"><table class="table table-sm align-middle mb-0">
          <thead><tr><th>Nome</th><th>Chave</th><th>Último uso</th><th>Status</th><th></th></tr></thead>
          <tbody><tr v-for="k in keys" :key="k.id" class="api-key-row">
            <td>{{ k.name }}<div class="small text-muted">por {{ k.created_by || '—' }} · {{ formatDate(k.created_at) }}</div></td>
            <td class="font-monospace small">{{ k.prefix }}…</td>
            <td class="small">{{ k.last_used_at ? formatDate(k.last_used_at) : 'nunca' }}<div v-if="k.expires_at" class="text-muted">expira {{ formatDate(k.expires_at) }}</div></td>
            <td><span class="badge" :class="k.status === 'active' ? 'text-bg-success' : 'text-bg-secondary'">{{ statusLabel[k.status] || k.status }}</span></td>
            <td class="text-end"><button v-if="k.status === 'active'" class="btn btn-sm btn-outline-danger" @click="revokeTarget = k">Revogar</button></td>
          </tr></tbody>
        </table></div>
        <p v-else class="small text-muted">Nenhuma chave criada ainda.</p>

        <details class="small">
          <summary>Como o outro sistema usa a chave</summary>
          <p class="mt-2 mb-1">Envie a chave no cabeçalho <code>Authorization</code> (sempre pelo servidor do outro sistema, nunca no navegador do visitante):</p>
<pre class="bg-light border rounded p-2 mb-2" style="white-space: pre-wrap">curl -H "Authorization: Bearer SUA_CHAVE" \
  "{{ baseUrl }}/media?search=surf&amp;per_page=20"</pre>
          <p class="mb-1">Cada imagem volta com links prontos (miniatura, prévia e original) que funcionam direto em <code>&lt;img src&gt;</code> e expiram em 1 hora (ajuste com <code>url_ttl</code>, em segundos). Outras rotas: <code>/me</code>, <code>/media/{id}</code>, <code>/collections</code> e <code>/media/{id}/file/original</code> (arquivo direto, exige a chave).</p>
        </details>
      </div>
    </div>

    <div v-if="revokeTarget" class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,.5)" role="dialog" aria-modal="true">
      <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title h6">Revogar chave</h2></div>
        <div class="modal-body small">Revogar a chave <strong>{{ revokeTarget.name }}</strong> ({{ revokeTarget.prefix }}…)? O sistema que a usa <strong>perde o acesso na hora</strong>. Não dá para desfazer; é preciso criar outra chave.</div>
        <div class="modal-footer"><button class="btn btn-sm btn-outline-secondary" @click="revokeTarget = null">Cancelar</button><button class="btn btn-sm btn-danger" @click="revokeKey">Revogar</button></div>
      </div></div>
    </div>

    <div v-if="tenants.can('owner')" class="card border-danger shadow-sm">
      <div class="card-body">
        <h2 class="h6 text-danger">Zona de perigo</h2>
        <p class="small text-muted">Excluir o projeto o torna inacessível para todos os membros.</p>
        <button class="btn btn-outline-danger btn-sm" @click="destroy">Excluir projeto</button>
      </div>
    </div>
  </div>
</template>
