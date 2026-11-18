<script setup lang="ts">
/**
 * Single-series column chart (one value per day). No legend — the card title names the series.
 * Specs: columns ≤ 24px with a 2px surface gap, 4px rounded tops / square baseline,
 * hairline recessive grid, one y-axis with clean ticks, hover tooltip on a full-height hit area.
 */
import { computed, ref } from 'vue'

const props = defineProps<{
  points: { label: string; value: number; sub?: string }[]
  format: (v: number) => string
  height?: number
  /** Counts (stores, orders): whole-number ticks only */
  integer?: boolean
}>()

const W = 720
const H = computed(() => props.height ?? 240)
const pad = { top: 12, right: 8, bottom: 26, left: 52 }
const plotW = W - pad.left - pad.right
const plotH = computed(() => H.value - pad.top - pad.bottom)

// Clean y ticks: 1, 2, 2.5, 5 × 10^n
const ticks = computed(() => {
  const max = Math.max(...props.points.map((p) => p.value), 0)
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

const band = computed(() => plotW / Math.max(1, props.points.length))
const barW = computed(() => Math.max(2, Math.min(24, band.value - 2)))   // cap at 24, keep a 2px gap
const xAt = (i: number) => pad.left + i * band.value + (band.value - barW.value) / 2

// Rounded top (4px, clamped for short bars), square at the baseline
function barPath(i: number, v: number) {
  const x = xAt(i)
  const w = barW.value
  const top = y(v)
  const base = y(0)
  const r = Math.min(4, w / 2, base - top)
  if (base - top <= 0) return ''
  return `M${x},${base} V${top + r} Q${x},${top} ${x + r},${top} H${x + w - r} Q${x + w},${top} ${x + w},${top + r} V${base} Z`
}

// Show at most ~8 x labels so they never collide
const labelEvery = computed(() => Math.ceil(props.points.length / 8))

const hover = ref<number | null>(null)
const tip = computed(() => (hover.value === null ? null : props.points[hover.value]))
const tipLeft = computed(() => (hover.value === null ? 0 : ((xAt(hover.value) + barW.value / 2) / W) * 100))

function compact(v: number) {
  if (v >= 10000) return `${Math.round(v / 1000)}K`
  return v.toLocaleString()
}
</script>

<template>
  <div class="chart" @mouseleave="hover = null">
    <svg :viewBox="`0 0 ${W} ${H}`" role="img" :aria-label="t('每日销售额柱状图')">
      <!-- grid + y axis -->
      <g v-for="it in ticks" :key="it">
        <line :x1="pad.left" :x2="W - pad.right" :y1="y(it)" :y2="y(it)" class="grid" />
        <text :x="pad.left - 8" :y="y(it) + 4" text-anchor="end" class="tick">{{ compact(it) }}</text>
      </g>
      <!-- columns -->
      <path v-for="(p, i) in points" :key="p.label" :d="barPath(i, p.value)" class="bar" :class="{ dim: hover !== null && hover !== i }" />
      <!-- x labels -->
      <template v-for="(p, i) in points" :key="'l' + p.label">
        <text v-if="(i % labelEvery === 0 && points.length - 1 - i >= labelEvery) || i === points.length - 1" :x="xAt(i) + barW / 2" :y="H - 8" text-anchor="middle" class="tick">{{ p.label }}</text>
      </template>
      <!-- hit areas: full band height, wider than the mark -->
      <rect v-for="(p, i) in points" :key="'h' + p.label" :x="pad.left + i * band" :y="pad.top" :width="band" :height="plotH"
        fill="transparent" @mouseenter="hover = i" />
    </svg>
    <div v-if="tip" class="tip" :style="{ left: `${tipLeft}%` }">
      <div class="tip-label">{{ tip.label }}</div>
      <div class="tip-value">{{ format(tip.value) }}</div>
      <div v-if="tip.sub" class="tip-sub">{{ tip.sub }}</div>
    </div>
  </div>
</template>

<style scoped>
.chart { position: relative; }
svg { width: 100%; height: auto; display: block; overflow: visible; }
.grid { stroke: var(--border); stroke-width: 1; vector-effect: non-scaling-stroke; }
.tick { font-size: 11px; fill: var(--ink-muted); }
.bar { fill: var(--brand-600); transition: opacity .12s; }
.bar.dim { opacity: .45; }
.tip {
  position: absolute; top: 0; transform: translateX(-50%); pointer-events: none;
  background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px;
  box-shadow: 0 4px 16px rgba(16, 24, 20, .1); font-size: 12px; white-space: nowrap; z-index: 2;
}
.tip-label { color: var(--ink-muted); }
.tip-value { font-weight: 600; font-size: 14px; color: var(--ink); font-variant-numeric: tabular-nums; }
.tip-sub { color: var(--ink-muted); }
</style>
