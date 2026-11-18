<script setup lang="ts">
/**
 * Conversion funnel as horizontal bars (one hue, length = visits reaching the step), with the
 * step-to-step rate and the drop-off between steps. Labels are always visible; no color-only meaning.
 */
import { computed } from 'vue'
import { t } from '@/i18n'

const props = defineProps<{ steps: { label: string; value: number; prev: number }[] }>()

const top = computed(() => Math.max(props.steps[0]?.value ?? 0, 1))
const rows = computed(() => props.steps.map((s, i) => {
  const before = i ? props.steps[i - 1].value : null
  return {
    ...s,
    width: (s.value / top.value) * 100,
    ofTotal: props.steps[0]?.value ? (s.value / props.steps[0].value) * 100 : 0,
    stepRate: before ? (s.value / before) * 100 : null,
    dropped: before !== null ? Math.max(0, before - s.value) : 0,
    prevRate: props.steps[0]?.prev ? (s.prev / props.steps[0].prev) * 100 : null,
  }
}))
const pct = (v: number) => `${v >= 10 || v === 0 ? v.toFixed(0) : v.toFixed(1)}%`
</script>

<template>
  <div class="funnel">
    <template v-for="(r, i) in rows" :key="r.label">
      <div v-if="i" class="between">
        <span class="arrow" aria-hidden="true">↓</span>
        <span>{{ r.stepRate === null ? '—' : t('{0} 继续', [pct(r.stepRate)]) }}</span>
        <span v-if="r.dropped" class="drop">{{ t('流失 {0}', [r.dropped.toLocaleString()]) }}</span>
      </div>
      <div class="step">
        <div class="step-head">
          <span class="name">{{ r.label }}</span>
          <span class="val">{{ r.value.toLocaleString() }}<span v-if="i" class="of">{{ pct(r.ofTotal) }}</span></span>
        </div>
        <div class="track"><span class="bar" :style="{ width: `${Math.max(r.width, r.value ? 0.8 : 0)}%`, opacity: 1 - i * 0.13 }" /></div>
        <div v-if="i && r.prevRate !== null" class="prev">{{ t('上期 {0}', [pct(r.prevRate)]) }}</div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.funnel { display: grid; }
.step-head { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; margin-bottom: 6px; }
.name { color: var(--ink); }
.val { font-weight: 600; font-variant-numeric: tabular-nums; }
.of { margin-left: 8px; font-weight: 400; color: var(--ink-muted); }
.track { height: 12px; background: var(--surface-muted); border-radius: 4px; overflow: hidden; }
.bar { display: block; height: 100%; background: var(--brand-600); border-radius: 0 4px 4px 0; transition: width .3s; }
.prev { font-size: 11px; color: var(--ink-muted); margin-top: 4px; text-align: right; }
.between { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--ink-muted); padding: 8px 0 8px 2px; }
.arrow { color: var(--brand-600); }
.drop { margin-left: auto; }
</style>
