<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NCard, NSpin } from 'naive-ui'
import { ops, stateLabel } from '@/api/ops'
import ColumnChart from '@/components/charts/ColumnChart.vue'

interface Overview {
  stores: number
  states: Record<string, number>
  mrr: string; revenue_30d: string; revenue_total: string; open_invoices: string; pending_fees: string
  trials_ending_7d: number
  signups: { date: string; signups: number }[]
  plans: { key: string; name: string; stores: number }[]
}

const router = useRouter()
const d = ref<Overview | null>(null)
const points = computed(() => (d.value?.signups ?? []).map((s) => ({ label: s.date.slice(5), value: s.signups })))

onMounted(async () => {
  d.value = (await ops.get('/overview')).data
})
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Ops</div>
      <h1 class="page-title">{{ t('平台概览') }}</h1>
    </div>
    <div v-if="!d" class="center"><NSpin /></div>
    <template v-else>
      <div class="kpis">
        <div class="kpi"><span>{{ t('月经常性收入 MRR') }}</span><b>${{ d.mrr }}</b><small>{{ t('年付按月折算，不含赠送套餐') }}</small></div>
        <div class="kpi"><span>{{ t('近 30 天收入') }}</span><b>${{ d.revenue_30d }}</b><small>{{ t('累计 ${0}', [d.revenue_total]) }}</small></div>
        <div class="kpi"><span>{{ t('待结算佣金') }}</span><b>${{ d.pending_fees }}</b><small>{{ t('未付账单 ${0}', [d.open_invoices]) }}</small></div>
        <div class="kpi"><span>{{ t('店铺总数') }}</span><b>{{ d.stores }}</b><small>{{ t('7 天内试用到期 {0} 家', [d.trials_ending_7d]) }}</small></div>
      </div>

      <div class="grid">
        <NCard :title="t('店铺状态')" size="small">
          <button v-for="(n, k) in d.states" :key="k" type="button" class="state-row" @click="router.push({ name: 'ops-stores', query: { state: k } })">
            <span>{{ stateLabel[k]?.label ?? k }}</span><b>{{ n }}</b>
          </button>
        </NCard>
        <NCard :title="t('付费套餐分布')" size="small">
          <div v-for="p in d.plans" :key="p.key" class="state-row static"><span>{{ p.name }}</span><b>{{ p.stores }}</b></div>
        </NCard>
      </div>

      <NCard :title="t('近 30 天新开店铺')" size="small">
        <ColumnChart :points="points" :format="(v: number) => String(Math.round(v))" :height="200" integer />
      </NCard>
    </template>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; max-width: 1100px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px; display: grid; gap: 4px; }
.kpi span { font-size: 13px; color: var(--ink-muted); }
.kpi b { font-size: 26px; font-variant-numeric: tabular-nums; }
.kpi small { font-size: 12px; color: var(--ink-muted); }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.state-row { display: flex; justify-content: space-between; width: 100%; padding: 8px 4px; border: 0; border-top: 1px solid var(--border); background: none; font: inherit; font-size: 14px; color: inherit; cursor: pointer; text-align: left; }
.state-row:first-child { border-top: 0; }
.state-row:hover:not(.static) { color: var(--brand-700); }
.state-row.static { cursor: default; }
@media (max-width: 900px) { .kpis { grid-template-columns: 1fr 1fr; } .grid { grid-template-columns: 1fr; } }
</style>
