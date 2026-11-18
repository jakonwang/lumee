<script setup lang="ts">
/**
 * Trend line: this period (brand green, 2px, light area) vs the previous period (gray dashed) on ONE axis.
 * Crosshair + tooltip on hover across the full plot height; legend above so identity never relies on color.
 */
import { computed, ref } from 'vue'
import { t } from '@/i18n'

const props = defineProps<{
  points: { label: string; tip: string; value: number; prev: number; prevTip?: string }[]
  format: (v: number) => string
  integer?: boolean
  height?: number
  compare?: boolean
}>()

const W = 760
const H = computed(() => props.height ?? 260)
const pad = { top: 14, right: 12, bottom: 26, left: 56 }
const plotW = W - pad.left - pad.right
const plotH = computed(() => H.value - pad.top - pad.bottom)

const ticks = computed(() => {
  const max = Math.max(...props.points.map((p) => Math.max(p.value, props.compare ? p.prev : 0)), 0)
  if (max <= 0) return [0, 1]
  const rough = max / 4
  const mag = 10 ** Math.floor(Math.log10(rough))
  let step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= rough)!
  if (props.integer) step = Math.max(1, Math.ceil(step))
  const top = Math.ceil(max / step) * step
  return Array.from({ length: Math.round(top / step) + 1 }, (_, i) => i * step)
})
const yMax = computed(() => ticks.value[ticks.value.length - 1] || 1)
const y = (v: number) => pad.top + plotH.value - (v / yMax.value) * plotH.value
const x = (i: number) => pad.left + (props.points.length <= 1 ? plotW / 2 : (i / (props.points.length - 1)) * plotW)

const line = (key: 'value' | 'prev') => props.points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p[key]).toFixed(1)}`).join(' ')
const area = computed(() => props.points.length
  ? `${line('value')} L${x(props.points.length - 1)},${y(0)} L${x(0)},${y(0)} Z` : '')
const labelEvery = computed(() => Math.ceil(props.points.length / 8))

const hover = ref<number | null>(null)
function onMove(e: MouseEvent) {
  const svg = (e.currentTarget as SVGElement).closest('svg')!
  const r = svg.getBoundingClientRect()
  const px = ((e.clientX - r.left) / r.width) * W
  const i = props.points.length <= 1 ? 0 : Math.round(((px - pad.left) / plotW) * (props.points.length - 1))
  hover.value = Math.max(0, Math.min(props.points.length - 1, i))
}
const tip = computed(() => (hover.value === null ? null : props.points[hover.value]))
const tipLeft = computed(() => (hover.value === null ? 0 : (x(hover.value) / W) * 100))

function compact(v: number) {
  if (v >= 1_000_000) return `${+(v / 1_000_000).toFixed(1)}M`
  if (v >= 10_000) return `${Math.round(v / 1000)}K`
  return v.toLocaleString()
}
</script>

<template>
  <div class="chart">
    <div class="legend">
      <span><i class="sw cur" />{{ t('本期') }}</span>
      <span v-if="compare"><i class="sw prev" />{{ t('上期') }}</span>
    </div>
    <div class="plot" @mouseleave="hover = null">
      <svg :viewBox="`0 0 ${W} ${H}`" role="img" :aria-label="t('趋势折线图')">
        <g v-for="it in ticks" :key="it">
          <line :x1="pad.left" :x2="W - pad.right" :y1="y(it)" :y2="y(it)" class="grid" />
          <text :x="pad.left - 8" :y="y(it) + 4" text-anchor="end" class="tick">{{ compact(it) }}</text>
        </g>
        <template v-for="(p, i) in points" :key="'l' + i">
          <text v-if="(i % labelEvery === 0 && points.length - 1 - i >= labelEvery) || i === points.length - 1" :x="x(i)" :y="H - 8"
            :text-anchor="i === 0 ? 'start' : i === points.length - 1 ? 'end' : 'middle'" class="tick">{{ p.label }}</text>
        </template>
        <path :d="area" class="area" />
        <path v-if="compare" :d="line('prev')" class="line prev" />
        <path :d="line('value')" class="line cur" />
        <template v-if="hover !== null">
          <line :x1="x(hover)" :x2="x(hover)" :y1="pad.top" :y2="y(0)" class="cross" />
          <circle v-if="compare" :cx="x(hover)" :cy="y(points[hover].prev)" r="4" class="dot prev" />
          <circle :cx="x(hover)" :cy="y(points[hover].value)" r="4.5" class="dot cur" />
        </template>
        <rect :x="pad.left - 6" :y="pad.top" :width="plotW + 12" :height="plotH" fill="transparent" @mousemove="onMove" />
      </svg>
      <div v-if="tip" class="tip" :style="{ left: `${Math.min(88, Math.max(12, tipLeft))}%` }">
        <div class="tip-label">{{ tip.tip }}</div>
        <div class="tip-row"><i class="sw cur" /><span class="tip-value">{{ format(tip.value) }}</span></div>
        <div v-if="compare" class="tip-row muted"><i class="sw prev" />{{ tip.prevTip ? `${tip.prevTip} · ` : '' }}{{ format(tip.prev) }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.chart { position: relative; }
.legend { display: flex; gap: 16px; font-size: 12px; color: var(--ink-muted); margin-bottom: 6px; }
.legend span { display: inline-flex; align-items: center; gap: 6px; }
.sw { display: inline-block; width: 14px; height: 0; border-top: 2px solid var(--brand-600); }
.sw.prev { border-top: 2px dashed #A3ADA7; }
.plot { position: relative; }
svg { width: 100%; height: auto; display: block; overflow: visible; }
.grid { stroke: var(--border); stroke-width: 1; vector-effect: non-scaling-stroke; }
.tick { font-size: 11px; fill: var(--ink-muted); }
.area { fill: var(--brand-600); opacity: .07; }
.line { fill: none; stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; vector-effect: non-scaling-stroke; }
.line.cur { stroke: var(--brand-600); }
.line.prev { stroke: #A3ADA7; stroke-dasharray: 4 4; }
.cross { stroke: var(--ink-muted); stroke-width: 1; stroke-dasharray: 2 3; vector-effect: non-scaling-stroke; }
.dot { stroke: var(--surface); stroke-width: 2; }
.dot.cur { fill: var(--brand-600); }
.dot.prev { fill: #A3ADA7; }
.tip {
  position: absolute; top: 0; transform: translateX(-50%); pointer-events: none;
  background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px;
  box-shadow: 0 4px 16px rgba(16, 24, 20, .1); font-size: 12px; white-space: nowrap; z-index: 2;
}
.tip-label { color: var(--ink-muted); margin-bottom: 2px; }
.tip-row { display: flex; align-items: center; gap: 6px; }
.tip-value { font-weight: 600; font-size: 14px; color: var(--ink); font-variant-numeric: tabular-nums; }
.muted { color: var(--ink-muted); }
</style>
