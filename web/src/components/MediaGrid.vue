<script setup>
import { formatBytes } from '../utils/format'
defineProps({ items: Array })
const emit = defineEmits(['open'])
</script>

<template>
  <div class="row g-3">
    <div v-for="m in items" :key="m.id" class="col-6 col-md-4 col-xl-3">
      <button class="card media-card h-100 w-100 text-start shadow-sm border-0" @click="emit('open', m)">
        <div class="thumb">
          <img v-if="m.thumbnail_url" :src="m.thumbnail_url" :alt="m.name" loading="lazy" />
          <div v-else-if="m.status === 'processing'" class="placeholder-box"><div class="spinner-border spinner-border-sm"></div><div class="small mt-1">Processando…</div></div>
          <div v-else-if="m.type === 'video'" class="placeholder-box"><span class="fs-1" aria-hidden="true">🎬</span></div>
          <div v-else class="placeholder-box text-danger small">Falha ao gerar miniatura</div>
          <span v-if="m.type === 'video'" class="badge text-bg-dark badge-type">Vídeo</span>
        </div>
        <div class="card-body p-2">
          <div class="small fw-semibold text-truncate" :title="m.name">{{ m.name }}</div>
          <div class="small text-muted">{{ formatBytes(m.size_bytes) }}<span v-if="m.width"> · {{ m.width }}×{{ m.height }}</span></div>
        </div>
      </button>
    </div>
  </div>
</template>

<style scoped>
.media-card { overflow: hidden; background: #fff; transition: transform .1s, box-shadow .1s; }
.media-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.12) !important; }
.thumb { position: relative; aspect-ratio: 4 / 3; background: #e9edf5; display: flex; align-items: center; justify-content: center; }
.thumb img { width: 100%; height: 100%; object-fit: cover; }
.placeholder-box { text-align: center; color: #6b7280; }
.badge-type { position: absolute; top: 6px; left: 6px; }
</style>
