<script setup>
import { computed, reactive, ref, watch } from 'vue'
import api, { parseError } from '../services/api'
import { useAuthStore } from '../stores/auth'
import { useTenantStore } from '../stores/tenant'
import FormAlert from '../components/FormAlert.vue'
import FormField from '../components/FormField.vue'
import LoadingButton from '../components/LoadingButton.vue'

const auth = useAuthStore()
const tenants = useTenantStore()
const members = ref([])
const loading = ref(false)
const error = ref('')
const form = reactive({ email: '', role: 'viewer' })
const adding = ref(false)
const fields = ref({})
const ROLES = ['viewer', 'editor', 'admin', 'owner']

const canManage = computed(() => tenants.can('admin'))
const assignable = computed(() => (tenants.role === 'owner' ? ROLES : ROLES.filter((r) => r !== 'owner')))

async function load() {
  if (!tenants.currentId) { members.value = []; return }
  loading.value = true; error.value = ''
  try { members.value = (await api.get(`/tenants/${tenants.currentId}/members`)).data.data } catch (e) { error.value = parseError(e).message } finally { loading.value = false }
}
watch(() => tenants.currentId, load, { immediate: true })

async function add() {
  adding.value = true; error.value = ''; fields.value = {}
  try {
    await api.post(`/tenants/${tenants.currentId}/members`, form)
    form.email = ''; form.role = 'viewer'; await load()
  } catch (e) { const p = parseError(e); error.value = p.message; fields.value = p.fields } finally { adding.value = false }
}

async function changeRole(m, role) {
  error.value = ''
  try { await api.patch(`/tenants/${tenants.currentId}/members/${m.id}`, { role }); await load() } catch (e) { error.value = parseError(e).message; await load() }
}

async function remove(m) {
  error.value = ''
  const self = m.id === auth.user?.id
  if (!confirm(self ? 'Sair deste projeto?' : `Remover ${m.name}?`)) return
  try {
    await api.delete(`/tenants/${tenants.currentId}/members/${m.id}`)
    if (self) { await tenants.fetch() } else { await load() }
  } catch (e) { error.value = parseError(e).message }
}
</script>

<template>
  <div v-if="!tenants.current" class="alert alert-info">Selecione ou crie um projeto primeiro.</div>
  <div v-else>
    <h1 class="h4 mb-3">Membros de {{ tenants.current.name }}</h1>
    <FormAlert :message="error" />

    <div class="card shadow-sm mb-4">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Nome</th><th>E-mail</th><th>Papel</th><th></th></tr></thead>
          <tbody>
            <tr v-if="loading"><td colspan="4" class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></td></tr>
            <tr v-for="m in members" :key="m.id">
              <td>{{ m.name }} <span v-if="m.id === auth.user?.id" class="badge text-bg-light">você</span></td>
              <td class="text-muted">{{ m.email }}</td>
              <td>
                <select v-if="canManage" class="form-select form-select-sm" style="width: 130px" :value="m.role" @change="changeRole(m, $event.target.value)">
                  <option v-for="r in assignable" :key="r" :value="r">{{ r }}</option>
                  <option v-if="!assignable.includes(m.role)" :value="m.role" disabled>{{ m.role }}</option>
                </select>
                <span v-else class="badge text-bg-secondary role-badge">{{ m.role }}</span>
              </td>
              <td class="text-end">
                <button v-if="canManage || m.id === auth.user?.id" class="btn btn-sm btn-outline-danger" @click="remove(m)">
                  {{ m.id === auth.user?.id ? 'Sair' : 'Remover' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="canManage" class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6">Adicionar membro</h2>
        <form class="row g-2 align-items-end" @submit.prevent="add">
          <div class="col-md-6"><FormField v-model="form.email" label="E-mail da pessoa" type="email" :error="fields.email" required /></div>
          <div class="col-md-3 mb-3">
            <label class="form-label">Papel</label>
            <select v-model="form.role" class="form-select"><option v-for="r in assignable" :key="r" :value="r">{{ r }}</option></select>
          </div>
          <div class="col-md-3 mb-3"><LoadingButton :loading="adding">Adicionar</LoadingButton></div>
        </form>
        <div class="form-text">A pessoa precisa ter criado uma conta antes.</div>
      </div>
    </div>
  </div>
</template>
