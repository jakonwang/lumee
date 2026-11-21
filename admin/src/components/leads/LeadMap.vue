<script setup lang="ts">
/**
 * Leads on a map: one dot per business, shade = grade, size = score. Hover for name and score,
 * click to open the lead. Light basemap so the brand-green dots carry the information.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { GRADE_COLORS, SEGMENT_LABELS, grade, type MapLead } from '@/api/leads'
import { t } from '@/i18n'

const props = defineProps<{ leads: MapLead[]; loading?: boolean }>()
const emit = defineEmits<{ (e: 'open', id: number): void }>()

const el = ref<HTMLDivElement | null>(null)
let map: L.Map | null = null
let layer: L.LayerGroup | null = null

function esc(s: string) {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!)
}

function draw(fit: boolean) {
  if (!map || !layer) return
  layer.clearLayers()
  // Low scores first so the best leads sit on top
  const sorted = [...props.leads].sort((a, b) => a.score - b.score)
  for (const l of sorted) {
    const g = grade(l.score)
    const m = L.circleMarker([l.lat, l.lng], {
      radius: 5 + Math.round(l.score / 14), color: '#fff', weight: 1.5, fillColor: GRADE_COLORS[g], fillOpacity: 0.92,
    })
    const contact = [l.has_email ? t('有邮箱') : '', l.has_phone ? t('有电话') : ''].filter(Boolean).join(' · ')
    m.bindTooltip(`<b>${esc(l.name)}</b><br><span class="tt-sub">${g} · ${l.score} · ${esc(SEGMENT_LABELS[l.segment])}${contact ? ` · ${contact}` : ''}</span>`, {
      direction: 'top', offset: [0, -6], className: 'lead-tt',
    })
    m.on('click', () => emit('open', l.id))
    m.addTo(layer)
  }
  if (fit && sorted.length) {
    map.fitBounds(L.latLngBounds(sorted.map((l) => [l.lat, l.lng] as [number, number])), { padding: [40, 40], maxZoom: 13 })
  }
}

onMounted(() => {
  map = L.map(el.value!, { zoomControl: true, attributionControl: true, worldCopyJump: true }).setView([20, 0], 2)
  // Tile server is configurable (VITE_MAP_TILES) — for heavy production use, point it at a paid provider
  L.tileLayer(import.meta.env.VITE_MAP_TILES || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map)
  layer = L.layerGroup().addTo(map)
  // Leaflet focuses the map on mousedown (for keyboard panning); without this the page jumps and the click misses
  const box = map.getContainer()
  box.focus = (opts?: FocusOptions) => HTMLElement.prototype.focus.call(box, { ...opts, preventScroll: true })
  draw(true)
})
watch(() => props.leads, (now, before) => draw(!before?.length || now.length !== before.length))
onBeforeUnmount(() => { map?.remove(); map = null })
</script>

<template>
  <div class="wrap">
    <div ref="el" class="map" />
    <div class="legend">
      <div class="lg-title">{{ t('评分等级') }}</div>
      <div v-for="(c, g) in GRADE_COLORS" :key="g" class="lg-row"><span class="dot" :style="{ background: c }" />{{ g }}<span class="muted">{{ { A: '≥ 75', B: '55–74', C: '35–54', D: '< 35' }[g] }}</span></div>
    </div>
    <div v-if="!loading && !leads.length" class="empty">{{ t('当前筛选下没有带坐标的线索') }}</div>
  </div>
</template>

<style scoped>
.wrap { position: relative; }
.map { height: 560px; width: 100%; background: #F2F5F3; z-index: 0; }
/* Muted basemap so the green lead dots carry the colour */
.map :deep(.leaflet-tile-pane) { filter: grayscale(1) contrast(.92) brightness(1.06); opacity: .85; }
.legend { position: absolute; right: 12px; bottom: 28px; z-index: 500; background: rgba(255, 255, 255, .95); border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; font-size: 12px; display: grid; gap: 4px; box-shadow: var(--shadow-soft); }
.lg-title { font-weight: 600; margin-bottom: 2px; }
.lg-row { display: flex; align-items: center; gap: 6px; }
.lg-row .muted { color: var(--ink-muted); margin-left: auto; padding-left: 10px; }
.dot { width: 10px; height: 10px; border-radius: 50%; border: 1.5px solid #fff; box-shadow: 0 0 0 1px #DCE3DF; }
.empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; z-index: 400; pointer-events: none; color: var(--ink-muted); font-size: 13px; }
:global(.lead-tt) { font-family: var(--font-body); font-size: 12.5px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 4px 14px rgba(16, 24, 20, .12); }
:global(.lead-tt .tt-sub) { color: var(--ink-muted); }
@media (max-width: 760px) { .map { height: 420px; } }
</style>
