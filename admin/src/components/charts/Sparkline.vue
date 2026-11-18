<script setup lang="ts">
/** Tiny trend line for a KPI tile: one green line + faint area, no axes (the tile states the number). */
import { computed } from 'vue'

const props = defineProps<{ values: number[]; height?: number }>()
const W = 120
const H = computed(() => props.height ?? 32)

const path = computed(() => {
  const v = props.values
  if (v.length < 2) return { line: '', area: '' }
  const max = Math.max(...v, 0)
  const min = Math.min(...v, 0)
  const span = max - min || 1
  const pts = v.map((n, i) => [(i / (v.length - 1)) * W, 2 + (H.value - 4) * (1 - (n - min) / span)])
  const line = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ')
  return { line, area: `${line} L${W},${H.value} L0,${H.value} Z` }
})
</script>

<template>
  <svg :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" aria-hidden="true" class="spark">
    <path :d="path.area" class="area" />
    <path :d="path.line" class="line" />
  </svg>
</template>

<style scoped>
.spark { width: 100%; display: block; overflow: visible; }
.spark { height: v-bind('`${H}px`'); }
.area { fill: var(--brand-600); opacity: .08; }
.line { fill: none; stroke: var(--brand-600); stroke-width: 1.5; vector-effect: non-scaling-stroke; stroke-linejoin: round; }
</style>
