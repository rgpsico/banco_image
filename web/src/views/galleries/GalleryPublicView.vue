<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../services/api'

// Galeria pública: quem tem o link vê as fotos do dia (hoje por padrão) e pode trocar de data. Sem login, sem compra.
const route = useRoute()
const token = route.params.token
const info = ref(null)
const state = ref('loading') // loading | ready | gone
const date = ref('')
const photos = ref([])
const loadingPhotos = ref(false)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const light = ref(null) // índice da foto aberta em tela cheia

const hasMore = computed(() => page.value < lastPage.value)
const otherDays = computed(() => (info.value?.days || []).filter((d) => d.date !== date.value).slice(0, 14))
const current = computed(() => (light.value === null ? null : photos.value[light.value]))

const fmt = (iso) => new Date(iso + 'T12:00:00').toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit' })

async function loadPhotos(reset = true) {
  if (reset) { photos.value = []; page.value = 1 }
  loadingPhotos.value = true
  try {
    const { data } = await api.get(`/galleries/${token}/photos`, { params: { date: date.value, page: page.value } })
    photos.value = reset ? data.data : photos.value.concat(data.data)
    lastPage.value = data.meta.last_page
    total.value = data.meta.total
  } catch { /* mantém o que já está na tela */ } finally { loadingPhotos.value = false }
}

async function pick(d) {
  if (!d || d === date.value) return
  date.value = d
  await loadPhotos(true)
}

async function more() { page.value += 1; await loadPhotos(false) }

const open = (i) => { light.value = i }
const close = () => { light.value = null }
const step = (n) => { if (light.value === null) return; const i = light.value + n; if (i >= 0 && i < photos.value.length) light.value = i; if (i >= photos.value.length - 2 && hasMore.value && !loadingPhotos.value) more() }

function onKey(e) { if (light.value === null) return; if (e.key === 'Escape') close(); else if (e.key === 'ArrowRight') step(1); else if (e.key === 'ArrowLeft') step(-1) }
let touchX = null
const onTouchStart = (e) => { touchX = e.changedTouches[0].clientX }
const onTouchEnd = (e) => { if (touchX === null) return; const dx = e.changedTouches[0].clientX - touchX; if (Math.abs(dx) > 50) step(dx < 0 ? 1 : -1); touchX = null }

onMounted(async () => {
  // Esta página não deve aparecer em buscas.
  const meta = document.createElement('meta'); meta.name = 'robots'; meta.content = 'noindex, nofollow'; document.head.appendChild(meta)
  window.addEventListener('keydown', onKey)
  try {
    info.value = (await api.get(`/galleries/${token}`)).data.data
    document.title = `${info.value.title} · ${info.value.project}`
    date.value = info.value.today // sempre abre no dia de hoje
    state.value = 'ready'
    await loadPhotos(true)
  } catch { state.value = 'gone' }
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="gal">
    <div v-if="state === 'loading'" class="gal-center"><div class="spinner-border text-light" role="status"></div></div>

    <div v-else-if="state === 'gone'" class="gal-center text-center">
      <div class="gal-big" aria-hidden="true">🔗</div>
      <h1 class="h4">Esta galeria não está disponível</h1>
      <p class="text-secondary">O link pode ter sido trocado ou a galeria foi desativada. Peça o link atualizado para quem te enviou.</p>
    </div>

    <template v-else>
      <header class="gal-head">
        <div class="gal-proj">{{ info.project }}</div>
        <h1 class="gal-title">{{ info.title }}</h1>
        <p v-if="info.description" class="gal-desc">{{ info.description }}</p>
      </header>

      <section class="gal-dates" aria-label="Escolher a data">
        <button type="button" class="gal-pill" :class="{ on: date === info.today }" @click="pick(info.today)">Hoje · {{ fmt(info.today) }}</button>
        <label class="gal-pill gal-pill-date" :class="{ on: date !== info.today }">
          <span>{{ date !== info.today ? fmt(date) : 'Outro dia' }}</span>
          <input type="date" :max="info.today" :value="date" aria-label="Escolher outro dia" @change="pick($event.target.value)" />
        </label>
      </section>
      <div v-if="otherDays.length" class="gal-days" aria-label="Dias com fotos">
        <button v-for="d in otherDays" :key="d.date" type="button" class="gal-day" @click="pick(d.date)">{{ fmt(d.date) }} <small>{{ d.count }}</small></button>
      </div>

      <div v-if="loadingPhotos && !photos.length" class="gal-center-sm"><div class="spinner-border spinner-border-sm text-light"></div></div>

      <div v-else-if="!photos.length" class="gal-empty">
        <div class="gal-big" aria-hidden="true">📷</div>
        <p v-if="!info.days.length">Ainda não há fotos nesta galeria.</p>
        <p v-else-if="date === info.today">Ainda não há fotos de hoje. Veja os outros dias acima.</p>
        <p v-else>Não há fotos neste dia. Escolha outra data.</p>
      </div>

      <template v-else>
        <p class="gal-count">{{ total }} {{ total === 1 ? 'foto' : 'fotos' }} · {{ fmt(date) }}</p>
        <div class="gal-grid">
          <button v-for="(p, i) in photos" :key="p.id" type="button" class="gal-cell" :aria-label="`Abrir foto das ${p.time}`" @click="open(i)">
            <img :src="p.thumb_url" :width="p.width" :height="p.height" loading="lazy" alt="" />
            <span class="gal-time">{{ p.time }}</span>
          </button>
        </div>
        <div v-if="hasMore" class="text-center my-4"><button type="button" class="gal-more" :disabled="loadingPhotos" @click="more">{{ loadingPhotos ? 'Carregando…' : 'Ver mais fotos' }}</button></div>
      </template>

      <footer class="gal-foot">Fotos com marca d'água. {{ info.allow_download ? 'Toque na foto para ampliar e baixar.' : 'Toque na foto para ampliar.' }}</footer>
    </template>

    <!-- Foto em tela cheia -->
    <div v-if="current" class="gal-light" role="dialog" aria-modal="true" aria-label="Foto ampliada" @click.self="close" @touchstart="onTouchStart" @touchend="onTouchEnd">
      <button type="button" class="gal-x" aria-label="Fechar" @click="close">✕</button>
      <button v-if="light > 0" type="button" class="gal-nav gal-prev" aria-label="Foto anterior" @click="step(-1)">‹</button>
      <img :key="current.id" :src="current.view_url" alt="" class="gal-view" />
      <button v-if="light < photos.length - 1" type="button" class="gal-nav gal-next" aria-label="Próxima foto" @click="step(1)">›</button>
      <div class="gal-bar">
        <span>{{ light + 1 }} / {{ photos.length }}{{ hasMore ? '+' : '' }} · {{ current.time }}</span>
        <a v-if="current.download_url" :href="current.download_url" class="gal-dl" download>⬇ Baixar foto</a>
      </div>
    </div>
  </div>
</template>

<style scoped>
.gal { min-height: 100vh; background: #0f1115; color: #eef1f6; padding: 18px 14px 48px; max-width: 1200px; margin: 0 auto; }
.gal-center { min-height: 70vh; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.gal-center-sm { display: flex; justify-content: center; padding: 40px 0; }
.gal-big { font-size: 56px; margin-bottom: 8px; }
.gal-head { padding: 8px 2px 14px; }
.gal-proj { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: #8da2c0; }
.gal-title { font-size: 26px; font-weight: 800; margin: 4px 0 0; line-height: 1.15; }
.gal-desc { color: #aab4c5; margin: 6px 0 0; }
.gal-dates { display: flex; gap: 8px; flex-wrap: wrap; margin: 6px 0 10px; }
.gal-pill { background: #1a1f29; color: #eef1f6; border: 1px solid #2a3242; border-radius: 999px; padding: 10px 18px; font-size: 14px; font-weight: 600; min-height: 44px; cursor: pointer; display: inline-flex; align-items: center; position: relative; }
.gal-pill.on { background: #ff7a1a; border-color: #ff7a1a; color: #111; }
.gal-pill-date input { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; }
.gal-days { display: flex; gap: 8px; overflow-x: auto; padding: 2px 0 12px; scrollbar-width: thin; }
.gal-day { flex: none; background: transparent; color: #aab4c5; border: 1px solid #2a3242; border-radius: 10px; padding: 8px 12px; font-size: 13px; cursor: pointer; min-height: 40px; }
.gal-day small { color: #ff9a4d; font-weight: 700; margin-left: 4px; }
.gal-count { color: #8da2c0; font-size: 13px; margin: 6px 2px 10px; }
.gal-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 6px; }
.gal-cell { position: relative; aspect-ratio: 1; padding: 0; border: 0; background: #1a1f29; border-radius: 8px; overflow: hidden; cursor: pointer; }
.gal-cell img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .15s; }
.gal-cell:hover img { transform: scale(1.04); }
.gal-time { position: absolute; left: 6px; bottom: 6px; background: rgba(0,0,0,.6); color: #fff; font-size: 11px; padding: 2px 7px; border-radius: 6px; }
.gal-empty { text-align: center; color: #aab4c5; padding: 50px 10px; }
.gal-more { background: #1a1f29; color: #eef1f6; border: 1px solid #2a3242; border-radius: 999px; padding: 12px 28px; min-height: 46px; cursor: pointer; font-weight: 600; }
.gal-foot { text-align: center; color: #6f7c92; font-size: 12px; margin-top: 30px; }
.gal-light { position: fixed; inset: 0; background: rgba(5,7,10,.985); z-index: 2000; display: flex; align-items: center; justify-content: center; }
.gal-view { max-width: 100%; max-height: calc(100vh - 90px); object-fit: contain; user-select: none; }
.gal-x { position: absolute; top: 10px; right: 12px; background: rgba(255,255,255,.12); color: #fff; border: 0; width: 44px; height: 44px; border-radius: 50%; font-size: 18px; cursor: pointer; z-index: 2; }
.gal-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,.12); color: #fff; border: 0; width: 48px; height: 64px; font-size: 34px; cursor: pointer; border-radius: 10px; z-index: 2; }
.gal-prev { left: 8px; } .gal-next { right: 8px; }
.gal-bar { position: absolute; left: 0; right: 0; bottom: 0; padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px)); display: flex; justify-content: space-between; align-items: center; gap: 12px; background: linear-gradient(to top, rgba(0,0,0,.7), transparent); font-size: 13px; color: #cbd3e0; }
.gal-dl { background: #ff7a1a; color: #111; text-decoration: none; font-weight: 700; padding: 10px 18px; border-radius: 999px; min-height: 44px; display: inline-flex; align-items: center; }
@media (max-width: 480px) { .gal-grid { grid-template-columns: repeat(3, 1fr); gap: 4px; } .gal-title { font-size: 22px; } .gal-nav { width: 40px; height: 56px; } }
</style>
