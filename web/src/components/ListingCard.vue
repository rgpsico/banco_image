<script setup>
import { formatMoney } from '../utils/format'
defineProps({ listing: Object })
</script>

<template>
  <RouterLink :to="{ name: 'listing', params: { id: listing.id } }" class="card listing-card h-100 border-0 shadow-sm text-decoration-none text-reset">
    <div class="thumb">
      <img :src="listing.thumbnail_url" :alt="listing.title" loading="lazy" />
      <span v-if="listing.type === 'video'" class="badge text-bg-dark badge-type">Vídeo</span>
    </div>
    <div class="card-body p-2">
      <div class="small fw-semibold text-truncate" :title="listing.title">{{ listing.title }}</div>
      <div class="d-flex justify-content-between small text-muted">
        <span class="text-truncate me-2">{{ listing.seller.name }}</span>
        <span class="text-nowrap fw-semibold text-body">a partir de {{ formatMoney(listing.min_price_cents) }}</span>
      </div>
    </div>
  </RouterLink>
</template>

<style scoped>
.listing-card { overflow: hidden; background: #fff; transition: transform .1s, box-shadow .1s; }
.listing-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.14) !important; }
.thumb { position: relative; aspect-ratio: 4 / 3; background: #e9edf5; }
.thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.badge-type { position: absolute; top: 6px; left: 6px; }
</style>
