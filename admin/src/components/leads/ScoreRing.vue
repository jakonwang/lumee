<script setup lang="ts">
/** Lead score as a ring with its grade letter — shades of the brand green only. */
import { computed } from 'vue'
import { grade } from '@/api/leads'

const props = withDefaults(defineProps<{ score: number; size?: number; ai?: boolean }>(), { size: 40, ai: false })
const r = computed(() => props.size / 2 - 3)
const c = computed(() => 2 * Math.PI * r.value)
const g = computed(() => grade(props.score))
const color = computed(() => ({ A: '#0A7350', B: '#14A06E', C: '#7CC9A6', D: '#C5CEC9' })[g.value])
</script>

<template>
  <div class="ring" :style="{ width: `${size}px`, height: `${size}px` }" :title="`${score} / 100`">
    <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`">
      <circle :cx="size / 2" :cy="size / 2" :r="r" fill="none" stroke="#EDF1EF" stroke-width="3.5" />
      <circle :cx="size / 2" :cy="size / 2" :r="r" fill="none" :stroke="color" stroke-width="3.5" stroke-linecap="round"
        :stroke-dasharray="`${(c * score) / 100} ${c}`" :transform="`rotate(-90 ${size / 2} ${size / 2})`" />
    </svg>
    <div class="inner">
      <span class="num" :style="{ fontSize: `${Math.round(size * 0.3)}px` }">{{ score }}</span>
      <span v-if="size >= 52" class="g" :style="{ color }">{{ g }}{{ ai ? ' · AI' : '' }}</span>
    </div>
  </div>
</template>

<style scoped>
.ring { position: relative; flex: none; }
.ring svg { display: block; }
.inner { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1; }
.num { font-family: var(--font-display); font-weight: 700; color: var(--ink); font-variant-numeric: tabular-nums; }
.g { font-size: 10px; font-weight: 600; margin-top: 3px; letter-spacing: .04em; }
</style>
