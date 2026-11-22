<script setup lang="ts">
/** 装修效果: how each site (main + markets) performs, design A/B tests, and the weekly findings + suggestions. */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NAlert, NButton, NCard, NEmpty, NRadioButton, NRadioGroup, NSpin, NTag, useDialog, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import type { DesignPerformance, SiteMetrics } from '@/api/theme'
import { t } from '@/i18n'

const message = useMessage()
const dialog = useDialog()
const router = useRouter()
const data = ref<DesignPerformance | null>(null)
const loading = ref(false)
const days = ref(28)

async function load() {
  loading.value = true
  try { data.value = (await http.get('/design/performance', { params: { days: days.value } })).data }
  catch (e) { message.error(errorMessage(e)) } finally { loading.value = false }
}
watch(days, load)
onMounted(load)

const money = (v: number) => `${data.value?.currency ?? ''} ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v}%`)

/** Rows shown for each site; `up` = a rise is good. */
const rows: { key: keyof SiteMetrics; label: string; up: boolean; fmt: (v: number) => string }[] = [
  { key: 'sessions', label: t('访问'), up: true, fmt: (v) => v.toLocaleString() },
  { key: 'bounce', label: t('跳出率'), up: false, fmt: (v) => `${v}%` },
  { key: 'product_view', label: t('看了商品'), up: true, fmt: (v) => `${v}%` },
  { key: 'cart', label: t('加购率'), up: true, fmt: (v) => `${v}%` },
  { key: 'conversion', label: t('转化率'), up: true, fmt: (v) => `${v}%` },
  { key: 'revenue_per_session', label: t('每次访问收入'), up: true, fmt: (v) => money(v) },
]
function delta(now: number, before: number, up: boolean) {
  if (!before || now === before) return null
  const d = Math.round(((now - before) / before) * 1000) / 10
  return { text: `${d > 0 ? '+' : ''}${d}%`, good: (d > 0) === up }
}

const sources: Record<string, string> = { publish: t('发布'), schedule: t('活动排期'), experiment: t('A/B 测试') }
const running = computed(() => data.value?.experiments.filter((e) => e.status === 'running') ?? [])
const past = computed(() => data.value?.experiments.filter((e) => e.status !== 'running') ?? [])

const finishing = ref<number | null>(null)
function finish(id: number, winner: 'a' | 'b' | null) {
  const text = winner === 'b' ? t('B 版会发布给所有访客（原设计保存在版本记录里，可以随时恢复）。') : winner === 'a' ? t('保留现在的设计，B 版不会发布。') : t('停止测试，不选结果。')
  dialog.warning({
    title: winner === 'b' ? t('采用 B 版？') : winner === 'a' ? t('保留 A 版？') : t('停止测试？'), content: text,
    positiveText: t('确定'), negativeText: t('取消'),
    onPositiveClick: async () => {
      finishing.value = id
      try { await http.post(`/design/experiments/${id}/finish`, { winner }); message.success(t('测试已结束')); load() }
      catch (e) { message.error(errorMessage(e)) } finally { finishing.value = null }
    },
  })
}

const refreshing = ref(false)
async function refresh() {
  refreshing.value = true
  try {
    const { data: r } = await http.post('/design/insights/refresh')
    message.success(r.sites ? t('已分析 {0} 个站点', [r.sites]) : t('最近 7 天访问不足 50 次的站点不会分析'))
    load()
  } catch (e) { message.error(errorMessage(e)) } finally { refreshing.value = false }
}
const siteName = (m: { name: string } | string | null) => (m === null ? t('主站') : typeof m === 'string' ? m : m.name)
const editor = (marketId: number | null) => router.push({ name: 'theme-editor', params: { id: data.value!.theme_id }, query: marketId ? { market: String(marketId) } : {} })
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <div class="eyebrow">Online Store</div>
        <h1 class="page-title">{{ t('装修效果') }}</h1>
        <p class="page-sub">{{ t('每个国家站点的访客表现、设计 A/B 测试结果，以及每周一自动生成的改版建议。') }}</p>
      </div>
      <div class="actions">
        <NRadioGroup v-model:value="days" size="small">
          <NRadioButton v-for="d in [7, 14, 28, 90]" :key="d" :value="d">{{ t('{0} 天', [d]) }}</NRadioButton>
        </NRadioGroup>
      </div>
    </div>

    <NSpin :show="loading && !data">
      <template v-if="data">
        <!-- A/B tests -->
        <NCard :title="t('设计 A/B 测试')" class="block">
          <template #header-extra><span class="muted">{{ t('在装修编辑器里点「A/B 测试这个草稿」开始') }}</span></template>
          <NEmpty v-if="!data.experiments.length" :description="t('还没有测试。改好草稿后，可以先让一半访客看新设计，用数据决定是否发布。')" />
          <div v-for="e in [...running, ...past]" :key="e.id" class="test">
            <div class="test-head">
              <strong>{{ e.name }}</strong>
              <NTag size="small" :bordered="false">{{ siteName(e.market) }}</NTag>
              <NTag v-if="e.status === 'running'" size="small" type="success" :bordered="false">{{ t('进行中') }}</NTag>
              <NTag v-else size="small" :bordered="false">{{ e.winner === 'b' ? t('已采用 B') : e.winner === 'a' ? t('保留 A') : t('已停止') }}</NTag>
              <span class="muted">{{ formatDate(e.started_at) }}<template v-if="e.ended_at"> → {{ formatDate(e.ended_at) }}</template></span>
            </div>
            <table class="arms">
              <thead><tr><th /><th>{{ t('访问') }}</th><th>{{ t('加购率') }}</th><th>{{ t('转化率') }}</th><th>{{ t('订单') }}</th><th>{{ t('每次访问收入') }}</th></tr></thead>
              <tbody>
                <tr v-for="k in (['a', 'b'] as const)" :key="k" :class="{ lead: e.stats.leader === k }">
                  <td>{{ k === 'a' ? t('A 原设计') : t('B 新设计') }}</td>
                  <td>{{ e.stats[k].sessions.toLocaleString() }}</td>
                  <td>{{ pct(e.stats[k].cart_rate) }}</td>
                  <td>{{ pct(e.stats[k].conversion) }}</td>
                  <td>{{ e.stats[k].orders }}</td>
                  <td>{{ money(e.stats[k].revenue_per_session) }}</td>
                </tr>
              </tbody>
            </table>
            <p v-if="e.status === 'running'" class="verdict">
              <template v-if="e.stats.ready">{{ t('{0} 版转化率更高（{1}），结果可信，可以做决定了。', [e.stats.leader === 'b' ? 'B' : 'A', e.stats.uplift === null ? '' : `${e.stats.uplift > 0 ? '+' : ''}${e.stats.uplift}%`]) }}</template>
              <template v-else-if="Math.min(e.stats.a.sessions, e.stats.b.sessions) < e.stats.min">{{ t('每个版本至少需要 {0} 次访问才能判断，继续等待。', [e.stats.min]) }}</template>
              <template v-else>{{ t('两个版本差别还不明显，可以多跑几天。') }}</template>
            </p>
            <p v-else-if="e.note" class="muted">{{ e.note }}</p>
            <div v-if="e.status === 'running'" class="test-actions">
              <NButton size="small" type="primary" :loading="finishing === e.id" @click="finish(e.id, 'b')">{{ t('采用 B 版') }}</NButton>
              <NButton size="small" secondary :disabled="finishing === e.id" @click="finish(e.id, 'a')">{{ t('保留 A 版') }}</NButton>
              <NButton size="small" quaternary :disabled="finishing === e.id" @click="finish(e.id, null)">{{ t('停止') }}</NButton>
            </div>
          </div>
        </NCard>

        <!-- Weekly look -->
        <NCard :title="t('每周建议')" class="block">
          <template #header-extra>
            <NButton size="small" secondary :loading="refreshing" @click="refresh">{{ t('现在分析') }}</NButton>
          </template>
          <NAlert v-if="!data.ai.ready" type="info" :bordered="false" style="margin-bottom: 12px">{{ t('没有设置 Claude API 密钥时，只给出基于数据的发现，不生成 AI 改版建议。') }}</NAlert>
          <NEmpty v-if="!data.insights.length" :description="t('每周一早上自动分析上周访问 50 次以上的站点。')" />
          <div class="insights">
            <div v-for="i in data.insights" :key="i.id" class="insight">
              <div class="test-head">
                <strong>{{ siteName(i.market) }}</strong>
                <span class="muted">{{ t('{0} 这周', [i.week]) }}</span>
                <NButton size="tiny" quaternary @click="editor(i.market_id)">{{ t('去装修') }}</NButton>
              </div>
              <ul class="findings">
                <li v-for="f in i.findings" :key="f.code" :class="f.level">{{ f.message }}</li>
              </ul>
              <div v-for="(s, n) in i.suggestions" :key="n" class="suggestion">
                <strong>{{ s.title }}</strong>
                <p>{{ s.detail }}</p>
              </div>
            </div>
          </div>
        </NCard>

        <!-- Sites -->
        <NCard :title="t('各站点表现')" class="block" content-style="padding: 0">
          <template #header-extra><span class="muted">{{ t('和前 {0} 天对比', [data.days]) }}</span></template>
          <div class="table-wrap">
            <table class="sites">
              <thead>
                <tr><th>{{ t('站点') }}</th><th v-for="r in rows" :key="r.key">{{ r.label }}</th><th>{{ t('手机占比') }}</th><th>{{ t('首页跳出') }}</th><th>{{ t('设计改动') }}</th></tr>
              </thead>
              <tbody>
                <tr v-for="s in data.sites" :key="s.market?.id ?? 0">
                  <td><a class="link" @click="editor(s.market?.id ?? null)">{{ siteName(s.market) }}</a></td>
                  <td v-for="r in rows" :key="r.key">
                    {{ r.fmt(s.now[r.key] as number) }}
                    <span v-if="delta(s.now[r.key] as number, s.before[r.key] as number, r.up)" class="delta" :class="{ good: delta(s.now[r.key] as number, s.before[r.key] as number, r.up)!.good }">
                      {{ delta(s.now[r.key] as number, s.before[r.key] as number, r.up)!.text }}
                    </span>
                  </td>
                  <td>{{ pct(s.now.mobile_share) }}</td>
                  <td>{{ pct(s.now.home_bounce) }}</td>
                  <td>
                    <span v-if="!s.changes.length" class="muted">—</span>
                    <div v-for="c in s.changes.slice(0, 3)" :key="c.id" class="change">{{ formatDate(c.created_at) }} · {{ sources[c.source] ?? c.source }}</div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </NCard>
      </template>
    </NSpin>
  </div>
</template>

<style scoped>
.block { margin-bottom: 16px; }
.muted { color: var(--n-text-color-3, #888); font-size: 12px; }
.test { padding: 12px 0; border-top: 1px solid var(--line, #eee); }
.test:first-of-type { border-top: 0; padding-top: 0; }
.test-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
.arms, .sites { width: 100%; border-collapse: collapse; font-size: 13px; }
.arms th, .arms td, .sites th, .sites td { padding: 8px 10px; text-align: right; border-bottom: 1px solid var(--line, #f0f0f0); white-space: nowrap; }
.arms th:first-child, .arms td:first-child, .sites th:first-child, .sites td:first-child { text-align: left; }
.arms th, .sites th { font-weight: 500; color: #666; font-size: 12px; }
.arms tr.lead td { font-weight: 600; color: #15803d; }
.verdict { margin: 8px 0; font-size: 13px; }
.test-actions { display: flex; gap: 8px; }
.insights { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
.insight { border: 1px solid var(--line, #eee); border-radius: 8px; padding: 12px; }
.findings { margin: 0 0 8px; padding-left: 18px; font-size: 13px; }
.findings li { margin: 4px 0; }
.findings li.warning, .findings li.error { color: #b45309; }
.suggestion { background: #f0fdf4; border-radius: 6px; padding: 8px 10px; margin-top: 8px; font-size: 13px; }
.suggestion p { margin: 4px 0 0; color: #444; }
.table-wrap { overflow-x: auto; }
.delta { font-size: 11px; color: #b91c1c; margin-left: 4px; }
.delta.good { color: #15803d; }
.change { font-size: 12px; color: #666; }
.link { color: #15803d; cursor: pointer; }
</style>
