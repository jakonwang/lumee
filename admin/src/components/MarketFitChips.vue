<script setup lang="ts">
/**
 * Which markets a product suits (App\Markets\MarketFit): one chip per market with its 0–100 score.
 * Good fits are green, poor fits muted, "don't sell there" (wrong plug, banned goods) red.
 * Hover / focus a chip for the reasons and local tags.
 */
import { computed } from 'vue'
import { NTooltip } from 'naive-ui'
import { t } from '@/i18n'

export interface Fit { market_id: number; score: number | null; hard?: boolean; reasons?: string[]; tags?: string[] }

const props = defineProps<{ fits: Fit[]; markets: { id: number; name: string }[] }>()

const chips = computed(() => props.markets
  .map((m) => ({ m, f: props.fits.find((f) => f.market_id === m.id) }))
  .filter((x) => x.f && x.f.score !== null)
  .sort((a, b) => (b.f!.score ?? 0) - (a.f!.score ?? 0)))
const level = (f: Fit) => (f.hard ? 'bad' : (f.score ?? 0) >= 70 ? 'good' : (f.score ?? 0) < 40 ? 'low' : '')
</script>

<template>
  <div v-if="chips.length" class="fits" :aria-label="t('市场适合度')">
    <NTooltip v-for="c in chips" :key="c.m.id" :style="{ maxWidth: '320px' }">
      <template #trigger>
        <span class="fit" :class="level(c.f!)" tabindex="0">{{ c.m.name }} <b>{{ c.f!.score }}</b></span>
      </template>
      <div class="tip">
        <strong>{{ c.f!.hard ? t('不建议在这个市场销售') : t('适合度 {0} / 100', [c.f!.score]) }}</strong>
        <ul v-if="c.f!.reasons?.length"><li v-for="(r, i) in c.f!.reasons" :key="i">{{ r }}</li></ul>
        <div v-if="c.f!.tags?.length" class="tags">{{ t('当地标签：') }}{{ c.f!.tags.join(' · ') }}</div>
      </div>
    </NTooltip>
  </div>
</template>

<style scoped>
.fits { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
.fit { font-size: 12px; line-height: 20px; padding: 0 7px; border-radius: 10px; border: 1px solid var(--border); color: var(--ink-muted); background: var(--surface); cursor: default; white-space: nowrap; }
.fit b { font-weight: 600; color: var(--ink); font-variant-numeric: tabular-nums; }
.fit.good { border-color: var(--brand-500); color: var(--brand-700); }
.fit.good b { color: var(--brand-700); }
.fit.low { opacity: 0.7; }
.fit.bad { border-color: var(--danger); color: var(--danger); }
.fit.bad b { color: var(--danger); }
.fit:focus-visible { outline: 2px solid var(--brand-500); outline-offset: 1px; }
.tip { font-size: 12px; line-height: 1.6; }
.tip ul { margin: 4px 0 0; padding-left: 16px; }
.tags { margin-top: 4px; opacity: 0.85; }
</style>
