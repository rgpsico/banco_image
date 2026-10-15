<script setup>
import { ref } from 'vue'
import { useTenantStore } from '../stores/tenant'
import { useCollectionsStore } from '../stores/collections'
import { parseError } from '../services/api'
import CollectionNode from './CollectionNode.vue'
import NameDialog from './NameDialog.vue'

defineProps({ active: String })
const emit = defineEmits(['select'])
const tenants = useTenantStore()
const collections = useCollectionsStore()
const dialog = ref(null) // { mode: 'create'|'rename', node?, parent? }
const error = ref('')

async function save(name) {
  error.value = ''
  try {
    const d = dialog.value
    if (d.mode === 'create') await collections.create(tenants.currentId, name, d.parent?.id ?? null)
    else await collections.rename(tenants.currentId, d.node.id, name)
    dialog.value = null
  } catch (e) { error.value = parseError(e).message }
}

async function remove(node) {
  if (!confirm(`Excluir a pasta "${node.name}"? As mídias continuam na biblioteca.`)) return
  try {
    await collections.remove(tenants.currentId, node.id)
    if (node.id === dialog.value?.node?.id) dialog.value = null
    emit('select', '')
  } catch (e) { alert(parseError(e).message) }
}
</script>

<template>
  <div class="card shadow-sm">
    <div class="card-body p-2">
      <div class="d-flex justify-content-between align-items-center px-2 py-1">
        <strong class="small text-uppercase text-muted">Pastas</strong>
        <button v-if="tenants.can('editor')" class="btn btn-sm btn-outline-primary py-0" @click="dialog = { mode: 'create' }">Nova</button>
      </div>
      <ul class="list-unstyled mb-0">
        <li>
          <button class="btn btn-link text-start text-decoration-none text-reset w-100 p-1 px-2 folder-all" :class="{ 'fw-bold': !active }" @click="emit('select', '')">
            🗂 Todas as mídias
          </button>
        </li>
        <CollectionNode
          v-for="n in collections.tree" :key="n.id" :node="n" :active="active" :can-edit="tenants.can('editor')"
          @select="emit('select', $event)"
          @create-child="dialog = { mode: 'create', parent: $event }"
          @rename="dialog = { mode: 'rename', node: $event }"
          @remove="remove"
        />
      </ul>
      <p v-if="!collections.items.length && !collections.loading" class="small text-muted px-2 mt-2 mb-0">Nenhuma pasta ainda.</p>
    </div>

    <NameDialog
      v-if="dialog"
      :title="dialog.mode === 'rename' ? 'Renomear pasta' : (dialog.parent ? `Nova subpasta em ${dialog.parent.name}` : 'Nova pasta')"
      :initial="dialog.node?.name || ''" :error="error" :confirm-label="dialog.mode === 'rename' ? 'Salvar' : 'Criar'"
      @save="save" @cancel="dialog = null; error = ''"
    />
  </div>
</template>
