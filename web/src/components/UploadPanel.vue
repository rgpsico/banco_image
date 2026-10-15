<script setup>
import { ref, computed } from 'vue'
import { useTenantStore } from '../stores/tenant'
import { uploadFile, validateFile, ACCEPT_ATTR } from '../services/upload'
import { parseError } from '../services/api'
import { formatBytes } from '../utils/format'

const emit = defineEmits(['uploaded'])
const tenants = useTenantStore()
const queue = ref([]) // { id, file, progress, status: queued|uploading|done|error|cancelled, error, controller }
const dragging = ref(false)
const input = ref(null)
const CONCURRENCY = 2
let seq = 0

const active = computed(() => queue.value.some((q) => ['queued', 'uploading'].includes(q.status)))

function add(files) {
  for (const file of files) {
    const item = { id: ++seq, file, progress: 0, status: 'queued', error: '', controller: null }
    const problem = validateFile(file)
    if (problem) { item.status = 'error'; item.error = problem }
    queue.value.unshift(item)
  }
  pump()
}

function onDrop(e) { dragging.value = false; add([...e.dataTransfer.files]) }
function onPick(e) { add([...e.target.files]); e.target.value = '' }

async function pump() {
  while (queue.value.filter((q) => q.status === 'uploading').length < CONCURRENCY) {
    const next = [...queue.value].reverse().find((q) => q.status === 'queued')
    if (!next) return
    run(next)
  }
}

async function run(item) {
  item.status = 'uploading'
  item.controller = new AbortController()
  try {
    await uploadFile(tenants.currentId, item.file, { signal: item.controller.signal, onProgress: (p) => { item.progress = p } })
    item.status = 'done'
    emit('uploaded')
  } catch (e) {
    if (item.status !== 'cancelled') { item.status = 'error'; item.error = parseError(e).message }
  } finally { pump() }
}

function cancel(item) {
  item.status = 'cancelled'
  item.controller?.abort()
}

function clearFinished() { queue.value = queue.value.filter((q) => ['queued', 'uploading'].includes(q.status)) }
const label = { queued: 'Na fila', uploading: 'Enviando', done: 'Concluído', error: 'Erro', cancelled: 'Cancelado' }
</script>

<template>
  <div class="mb-3">
    <div
      class="dropzone text-center p-4" :class="{ drag: dragging }"
      @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop"
    >
      <div class="mb-2">Arraste imagens e vídeos aqui, ou</div>
      <button class="btn btn-primary btn-sm" type="button" @click="input.click()">Escolher arquivos</button>
      <input ref="input" type="file" class="d-none" multiple :accept="ACCEPT_ATTR" @change="onPick" />
      <div class="small text-muted mt-2">JPG, PNG, WebP e GIF até 25 MB · MP4, WebM e MOV até 500 MB</div>
    </div>

    <div v-if="queue.length" class="card shadow-sm mt-3">
      <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="small fw-semibold">Envios <span v-if="active" class="spinner-border spinner-border-sm ms-1"></span></span>
        <button class="btn btn-sm btn-link p-0" @click="clearFinished">Limpar concluídos</button>
      </div>
      <ul class="list-group list-group-flush upload-list">
        <li v-for="q in queue" :key="q.id" class="list-group-item py-2 upload-row" :data-status="q.status">
          <div class="d-flex justify-content-between small">
            <span class="text-truncate me-2">{{ q.file.name }} <span class="text-muted">({{ formatBytes(q.file.size) }})</span></span>
            <span :class="{ 'text-danger': q.status === 'error', 'text-success': q.status === 'done' }">{{ label[q.status] }}</span>
          </div>
          <div v-if="['uploading', 'queued', 'done'].includes(q.status)" class="progress mt-1" style="height: 6px">
            <div class="progress-bar" :class="{ 'bg-success': q.status === 'done' }" :style="{ width: Math.round(q.progress * 100) + '%' }"></div>
          </div>
          <div v-if="q.error" class="small text-danger mt-1">{{ q.error }}</div>
          <button v-if="['queued', 'uploading'].includes(q.status)" class="btn btn-sm btn-link text-danger p-0 mt-1" @click="cancel(q)">Cancelar</button>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.upload-list { max-height: 220px; overflow-y: auto; }
.dropzone { border: 2px dashed #b9c2d8; border-radius: 12px; background: #fff; transition: all .15s; }
.dropzone.drag { border-color: #0d6efd; background: #eef4ff; }
</style>
