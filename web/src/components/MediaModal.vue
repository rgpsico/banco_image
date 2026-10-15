<script setup>
import { computed, ref, watch } from 'vue'
import { useTenantStore } from '../stores/tenant'
import { useMediaStore } from '../stores/media'
import { useCollectionsStore } from '../stores/collections'
import { parseError } from '../services/api'
import { formatBytes, formatDate } from '../utils/format'

const props = defineProps({ media: Object })
const emit = defineEmits(['close', 'changed', 'deleted'])
const tenants = useTenantStore()
const store = useMediaStore()
const collections = useCollectionsStore()

const name = ref(props.media.name)
const error = ref('')
const busy = ref(false)
const inFolders = ref(new Set(props.media.collections || []))
const canEdit = computed(() => tenants.can('editor'))

watch(() => props.media, (m) => { name.value = m.name; inFolders.value = new Set(m.collections || []) })

async function rename() {
  if (name.value === props.media.name) return
  error.value = ''
  try { await store.rename(tenants.currentId, props.media.id, name.value); emit('changed') } catch (e) { error.value = parseError(e).message }
}

async function download() {
  busy.value = true; error.value = ''
  try { window.location.href = await store.downloadUrl(tenants.currentId, props.media.id) } catch (e) { error.value = parseError(e).message } finally { setTimeout(() => { busy.value = false }, 800) }
}

async function remove() {
  if (!confirm(`Excluir "${props.media.name}" definitivamente?`)) return
  busy.value = true; error.value = ''
  try { await store.remove(tenants.currentId, props.media.id); emit('deleted') } catch (e) { error.value = parseError(e).message; busy.value = false }
}

async function toggleFolder(c, checked) {
  error.value = ''
  try {
    if (checked) await collections.attach(tenants.currentId, c.id, [props.media.id])
    else await collections.detach(tenants.currentId, c.id, props.media.id)
    checked ? inFolders.value.add(c.id) : inFolders.value.delete(c.id)
    await collections.fetch(tenants.currentId)
    emit('changed')
  } catch (e) { error.value = parseError(e).message }
}
</script>

<template>
  <div class="modal d-block overflow-auto" style="background: rgba(0,0,0,.6)" @click.self="emit('close')">
    <div class="modal-dialog modal-xl modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header py-2">
          <h2 class="modal-title h6 text-truncate">{{ media.name }}</h2>
          <button type="button" class="btn-close" aria-label="Fechar" @click="emit('close')"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-lg-8">
              <div class="preview d-flex align-items-center justify-content-center">
                <video v-if="media.type === 'video'" :src="media.stream_url" controls preload="metadata" class="mw-100 mh-100"></video>
                <img v-else-if="media.preview_url || media.thumbnail_url" :src="media.preview_url || media.thumbnail_url" :alt="media.name" class="mw-100 mh-100" />
                <div v-else class="text-muted">Prévia indisponível</div>
              </div>
            </div>
            <div class="col-lg-4">
              <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>

              <label class="form-label small mb-1">Nome</label>
              <div class="input-group input-group-sm mb-3">
                <input v-model="name" class="form-control" maxlength="150" :disabled="!canEdit" @keyup.enter="rename" />
                <button v-if="canEdit" class="btn btn-outline-secondary" :disabled="name === media.name || !name.trim()" @click="rename">Salvar</button>
              </div>

              <dl class="row small mb-3">
                <dt class="col-5 text-muted">Tipo</dt><dd class="col-7">{{ media.mime_type }}</dd>
                <dt class="col-5 text-muted">Tamanho</dt><dd class="col-7">{{ formatBytes(media.size_bytes) }}</dd>
                <template v-if="media.width"><dt class="col-5 text-muted">Dimensões</dt><dd class="col-7">{{ media.width }} × {{ media.height }}</dd></template>
                <template v-if="media.metadata?.camera"><dt class="col-5 text-muted">Câmera</dt><dd class="col-7">{{ media.metadata.camera }}</dd></template>
                <template v-if="media.metadata?.taken_at"><dt class="col-5 text-muted">Capturada</dt><dd class="col-7">{{ media.metadata.taken_at }}</dd></template>
                <dt class="col-5 text-muted">Enviada</dt><dd class="col-7">{{ formatDate(media.created_at) }}</dd>
              </dl>

              <div v-if="collections.items.length" class="mb-3">
                <div class="small fw-semibold mb-1">Pastas</div>
                <div v-for="c in collections.items" :key="c.id" class="form-check small">
                  <input :id="'f' + c.id" type="checkbox" class="form-check-input" :checked="inFolders.has(c.id)" :disabled="!canEdit" @change="toggleFolder(c, $event.target.checked)" />
                  <label :for="'f' + c.id" class="form-check-label">{{ c.name }}</label>
                </div>
              </div>

              <div class="d-grid gap-2">
                <button class="btn btn-primary btn-sm" :disabled="busy" @click="download">Baixar original</button>
                <button v-if="canEdit" class="btn btn-outline-danger btn-sm" :disabled="busy" @click="remove">Excluir</button>
              </div>
              <div class="form-text mt-2">O download usa um link temporário e seguro, válido por poucos minutos.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.preview { background: #0f1115; border-radius: 8px; min-height: 320px; max-height: 70vh; overflow: hidden; }
.preview img, .preview video { max-height: 70vh; }
</style>
