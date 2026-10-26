<script setup>
import { onMounted, ref, watch } from 'vue'
import QRCode from 'qrcode'
import api, { parseError } from '../../services/api'
import { useTenantStore } from '../../stores/tenant'
import { useCollectionsStore } from '../../stores/collections'
import FormAlert from '../../components/FormAlert.vue'
import LoadingButton from '../../components/LoadingButton.vue'

const tenants = useTenantStore()
const collections = useCollectionsStore()
const list = ref([])
const loading = ref(false)
const error = ref('')
const title = ref('')
const folder = ref('')
const creating = ref(false)
const copied = ref('')
const qr = ref(null) // { gallery, dataUrl }
const confirm = ref(null) // { kind: 'rotate' | 'delete', gallery }

async function load() {
  error.value = ''
  if (!tenants.currentId || !tenants.can('admin')) { list.value = []; return }
  loading.value = true
  try { list.value = (await api.get(`/tenants/${tenants.currentId}/galleries`)).data.data } catch (e) { error.value = parseError(e).message } finally { loading.value = false }
}
onMounted(() => { collections.fetch(tenants.currentId); load() })
watch(() => tenants.currentId, () => { collections.fetch(tenants.currentId); load() })

async function create() {
  creating.value = true; error.value = ''
  try {
    await api.post(`/tenants/${tenants.currentId}/galleries`, { title: title.value, collection: folder.value || null })
    title.value = ''; folder.value = ''
    await load()
  } catch (e) { error.value = parseError(e).message } finally { creating.value = false }
}

async function patch(g, body) {
  error.value = ''
  try { Object.assign(g, (await api.patch(`/tenants/${tenants.currentId}/galleries/${g.id}`, body)).data.data) } catch (e) { error.value = parseError(e).message; await load() }
}

async function copy(g) {
  try { await navigator.clipboard.writeText(g.public_url); copied.value = g.id; setTimeout(() => { copied.value = '' }, 2000) } catch { document.getElementById('url-' + g.id)?.select() }
}

const whatsapp = (g) => `https://wa.me/?text=${encodeURIComponent(`Oi! As fotos estão aqui: ${g.public_url}`)}`

async function showQr(g) { qr.value = { gallery: g, dataUrl: await QRCode.toDataURL(g.public_url, { width: 640, margin: 2, errorCorrectionLevel: 'M' }) } }

function downloadQr() {
  const a = document.createElement('a'); a.href = qr.value.dataUrl; a.download = `qr-${qr.value.gallery.title.replace(/[^\w]+/g, '-').toLowerCase()}.png`; a.click()
}

function printCard() {
  const g = qr.value.gallery
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))
  const w = window.open('', '_blank')
  if (!w) { error.value = 'O navegador bloqueou a janela de impressão. Permita pop-ups e tente de novo.'; return }
  w.document.write(`<!doctype html><meta charset="utf-8"><title>QR · ${esc(g.title)}</title><style>
    @page { size: A5; margin: 10mm } body { font-family: Helvetica, Arial, sans-serif; text-align: center; margin: 0; padding: 14mm 8mm }
    h1 { font-size: 30px; margin: 0 0 6px } p { margin: 6px 0; color: #444 } img { width: 78mm; height: 78mm; margin: 12px auto; display: block }
    .u { font-size: 12px; color: #666; word-break: break-all } .t { font-size: 20px; font-weight: 700 }</style>
    <h1>${esc(g.title)}</h1><p class="t">Escaneie para ver suas fotos</p><img src="${qr.value.dataUrl}" alt="QR code da galeria">
    <p>Aponte a câmera do celular para o código.</p><p class="u">${esc(g.public_url)}</p><script>onload=()=>setTimeout(()=>print(),300)<\/script>`)
  w.document.close()
}

async function runConfirm() {
  const { kind, gallery } = confirm.value
  error.value = ''
  try {
    if (kind === 'rotate') await api.post(`/tenants/${tenants.currentId}/galleries/${gallery.id}/rotate-link`)
    else await api.delete(`/tenants/${tenants.currentId}/galleries/${gallery.id}`)
    confirm.value = null; await load()
  } catch (e) { error.value = parseError(e).message; confirm.value = null }
}
</script>

<template>
  <div v-if="!tenants.current" class="alert alert-info">Selecione ou crie um projeto primeiro.</div>
  <div v-else-if="!tenants.can('admin')" class="alert alert-warning">Somente administradores do projeto gerenciam as galerias.</div>
  <div v-else style="max-width: 880px">
    <h1 class="h4 mb-1">Galerias</h1>
    <p class="text-muted small">Crie uma galeria e envie o link (ou o QR code) para os seus clientes. Eles veem as fotos <strong>por data</strong>, começando por hoje, sem login e sem pagamento. As fotos aparecem com <strong>marca d'água</strong> e o <strong>download fica desligado</strong>, a não ser que você libere.</p>
    <FormAlert :message="error" />

    <form class="card shadow-sm mb-4" @submit.prevent="create"><div class="card-body row g-2 align-items-end">
      <div class="col-md-5"><label class="form-label small mb-1" for="g-title">Nome da galeria</label>
        <input id="g-title" v-model="title" class="form-control" placeholder="Ex.: Passeio de domingo" maxlength="120" required /></div>
      <div class="col-md-4"><label class="form-label small mb-1" for="g-folder">Quais fotos?</label>
        <select id="g-folder" v-model="folder" class="form-select">
          <option value="">Todas as fotos do projeto</option>
          <option v-for="c in collections.items" :key="c.id" :value="c.id">Só a pasta: {{ c.name }}</option>
        </select></div>
      <div class="col-md-3"><LoadingButton :loading="creating" class="w-100">Criar galeria</LoadingButton></div>
    </div></form>

    <div v-if="loading" class="text-center py-4"><div class="spinner-border"></div></div>
    <div v-else-if="!list.length" class="text-center text-muted py-5 empty-state">
      <div class="fs-1" aria-hidden="true">🖼️</div><p class="mb-1">Nenhuma galeria ainda.</p><p class="small">Crie a primeira acima e mande o link para os seus clientes.</p>
    </div>

    <div v-for="g in list" :key="g.id" class="card shadow-sm mb-3 gallery-item" :class="{ 'opacity-75': !g.is_active }"><div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div><h2 class="h6 mb-0">{{ g.title }}</h2>
          <div class="small text-muted">{{ g.collection ? `Pasta: ${g.collection.name}` : 'Todas as fotos do projeto' }} · {{ g.photos_count }} {{ g.photos_count === 1 ? 'foto' : 'fotos' }} · {{ g.views_count }} {{ g.views_count === 1 ? 'visita' : 'visitas' }}</div></div>
        <span class="badge" :class="g.is_active ? 'text-bg-success' : 'text-bg-secondary'">{{ g.is_active ? 'Ativa' : 'Desativada' }}</span>
      </div>

      <div class="input-group my-3">
        <input :id="'url-' + g.id" class="form-control font-monospace small" readonly :value="g.public_url" @focus="$event.target.select()" aria-label="Link da galeria" />
        <button class="btn btn-dark" type="button" @click="copy(g)">{{ copied === g.id ? 'Copiado ✓' : 'Copiar' }}</button>
      </div>
      <div class="d-flex flex-wrap gap-2 mb-3">
        <a class="btn btn-sm btn-outline-secondary" :href="g.public_url" target="_blank" rel="noopener">Abrir</a>
        <a class="btn btn-sm btn-success" :href="whatsapp(g)" target="_blank" rel="noopener">Enviar pelo WhatsApp</a>
        <button class="btn btn-sm btn-outline-primary" type="button" @click="showQr(g)">QR code</button>
      </div>

      <div class="d-flex flex-wrap gap-4 small">
        <div class="form-check form-switch"><input :id="'dl-' + g.id" class="form-check-input" type="checkbox" role="switch" :checked="g.allow_download" @change="patch(g, { allow_download: $event.target.checked })" />
          <label class="form-check-label" :for="'dl-' + g.id">Permitir baixar as fotos</label></div>
        <div class="form-check form-switch"><input :id="'wm-' + g.id" class="form-check-input" type="checkbox" role="switch" :checked="g.watermark" @change="patch(g, { watermark: $event.target.checked })" />
          <label class="form-check-label" :for="'wm-' + g.id">Marca d'água nas fotos</label></div>
        <div class="form-check form-switch"><input :id="'on-' + g.id" class="form-check-input" type="checkbox" role="switch" :checked="g.is_active" @change="patch(g, { is_active: $event.target.checked })" />
          <label class="form-check-label" :for="'on-' + g.id">Galeria ativa</label></div>
      </div>
      <p v-if="g.allow_download" class="small text-warning mb-0 mt-2">Atenção: com o download liberado, quem tem o link baixa o <strong>original sem marca d'água</strong>.</p>

      <div class="mt-3 pt-2 border-top d-flex gap-3 small">
        <button class="btn btn-link btn-sm p-0" type="button" @click="confirm = { kind: 'rotate', gallery: g }">Trocar o link</button>
        <button class="btn btn-link btn-sm p-0 text-danger" type="button" @click="confirm = { kind: 'delete', gallery: g }">Excluir galeria</button>
      </div>
    </div></div>

    <!-- QR code -->
    <div v-if="qr" class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,.55)" role="dialog" aria-modal="true" @click.self="qr = null">
      <div class="modal-dialog modal-dialog-centered"><div class="modal-content text-center">
        <div class="modal-header"><h2 class="modal-title h6">QR code · {{ qr.gallery.title }}</h2><button type="button" class="btn-close" aria-label="Fechar" @click="qr = null"></button></div>
        <div class="modal-body"><img :src="qr.dataUrl" alt="QR code da galeria" style="width: 260px; max-width: 100%" />
          <p class="small text-muted mt-2 mb-0">Quem escanear abre a galeria na data de hoje. Imprima o cartão ou baixe a imagem para colocar onde quiser.</p></div>
        <div class="modal-footer justify-content-center"><button class="btn btn-primary" type="button" @click="printCard">Imprimir cartão</button><button class="btn btn-outline-secondary" type="button" @click="downloadQr">Baixar imagem</button></div>
      </div></div>
    </div>

    <!-- Confirmações -->
    <div v-if="confirm" class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,.55)" role="dialog" aria-modal="true">
      <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title h6">{{ confirm.kind === 'rotate' ? 'Trocar o link' : 'Excluir galeria' }}</h2></div>
        <div class="modal-body small">
          <template v-if="confirm.kind === 'rotate'">O link atual de <strong>{{ confirm.gallery.title }}</strong> <strong>deixa de funcionar na hora</strong>, e o QR code antigo também. Você receberá um link novo para enviar de novo.</template>
          <template v-else>Excluir <strong>{{ confirm.gallery.title }}</strong>? O link e o QR code param de funcionar. As fotos continuam na biblioteca.</template>
        </div>
        <div class="modal-footer"><button class="btn btn-sm btn-outline-secondary" @click="confirm = null">Cancelar</button><button class="btn btn-sm btn-danger" @click="runConfirm">{{ confirm.kind === 'rotate' ? 'Trocar o link' : 'Excluir' }}</button></div>
      </div></div>
    </div>
  </div>
</template>
