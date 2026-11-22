<script setup lang="ts">
/**
 * Products › Research radar (选品雷达): product ideas per market — from the store's own searches
 * and best-testing product types, or typed in — with outside evidence (Google Trends / Shopping
 * via SerpApi), a 0–100 score, Claude's market analysis, and links to search suppliers.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  NAlert, NButton, NCard, NCheckbox, NDrawer, NDrawerContent, NEmpty, NInput, NModal, NPagination, NProgress, NSelect, NSpin, NTag, NTooltip,
  useDialog, useMessage,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

type Status = 'new' | 'watching' | 'sourcing' | 'testing' | 'won' | 'dropped'
interface Opp {
  id: number; market_id: number; market: string; keyword: string; source: string; status: Status; note: string | null; score: number | null
  scores: Record<string, number> | null
  signals: { site?: { searches?: number; zero_results?: number; hot_products?: number }; trend?: { average: number; growth: number | null; series: number[] }
    shopping?: { results: number; sellers: number; min: number | null; median: number | null; max: number | null; currency: string | null; examples: { title: string; price: string | null; source: string | null }[] }
    external?: { source: string; volume: number | null; growth: number | null; price: number | null; sellers: number | null; imported_at: string } } | null
  ai: { fit_score: number; summary: string; price_band: { min: number; max: number }; local_keywords: string[]; title_ideas: string[]; risks: string[]; season: string; currency: string } | null
  links: { name: string; url: string }[]; enriched_at: string | null; analyzed_at: string | null
}
const message = useMessage()
const dialog = useDialog()

const market = ref('all')
const status = ref<'open' | Status>('open')
const q = ref('')
const page = ref(1)
const loading = ref(true)
const rows = ref<Opp[]>([])
const meta = ref({ total: 0, page: 1, last_page: 1 })
const counts = ref<Record<string, number>>({})
const markets = ref<{ id: number; name: string; currency: string }[]>([])
const serpConnected = ref(false)
const aiReady = ref(false)
const selected = ref<number[]>([])

async function load() {
  loading.value = true
  try {
    const d = (await http.get('/research', { params: { market: market.value, status: status.value, q: q.value || undefined, page: page.value } })).data
    rows.value = d.data
    meta.value = d.meta
    counts.value = d.counts
    markets.value = d.markets
    serpConnected.value = d.serpapi_connected
    aiReady.value = d.ai_ready
    selected.value = selected.value.filter((id) => rows.value.some((r) => r.id === id))
    if (detail.value) detail.value = rows.value.find((r) => r.id === detail.value!.id) ?? detail.value
  } finally {
    loading.value = false
  }
}
watch([market, status, page], load)
let timer: number | undefined
watch(q, () => { clearTimeout(timer); timer = window.setTimeout(() => { page.value = 1; load() }, 300) })
onMounted(load)

const statusLabel: Record<Status, string> = { new: t('新发现'), watching: t('观察'), sourcing: t('找货中'), testing: t('测款中'), won: t('已成功'), dropped: t('放弃') }
const sourceLabel: Record<string, string> = { search_gap: t('搜了没找到'), top_search: t('热门搜索'), hot_type: t('潜力品类'), manual: t('手动添加'), import: t('导入') }
const statusOptions = (Object.keys(statusLabel) as Status[]).map((k) => ({ label: statusLabel[k], value: k }))
const marketOptions = computed(() => [{ label: t('所有市场'), value: 'all' }, { label: t('默认站点'), value: '0' }, ...markets.value.map((m) => ({ label: m.name, value: String(m.id) }))])
const color = (s: number | null) => (s === null ? 'var(--border)' : s >= 70 ? 'var(--brand-600)' : s >= 50 ? '#d89614' : '#d03050')
const allChecked = computed(() => rows.value.length > 0 && rows.value.every((r) => selected.value.includes(r.id)))
const money = (minor: number | null, cur: string | null) => (minor === null ? '—' : `${cur ?? ''} ${(minor / 100).toFixed(2)}`)

// Discover / add
const discovering = ref(false)
async function discover() {
  discovering.value = true
  try {
    const r = (await http.post('/research/discover')).data
    message.success(r.created ? t('发现了 {0} 个新机会', [r.created]) : t('没有新的机会；站内搜索数据越多，发现得越多'))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    discovering.value = false
  }
}
const showAdd = ref(false)
const addText = ref('')
const addMarket = ref('0')
async function add() {
  try {
    const r = (await http.post('/research/opportunities', { keywords: addText.value, market_id: Number(addMarket.value) })).data
    message.success(t('添加了 {0} 个', [r.created]))
    showAdd.value = false
    addText.value = ''
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  }
}

// Import from other tools (TikTok Creative Center, Keepa, Helium 10…)
const showImport = ref(false)
const importText = ref('')
const importSource = ref('TikTok Creative Center')
const importMarket = ref('0')
const importSources = ['TikTok Creative Center', 'Keepa', 'Helium 10', 'Jungle Scout', 'Google Keyword Planner', t('其他')].map((s) => ({ label: s, value: s }))
async function readFile(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (f) importText.value = await f.text()
}
async function runImport() {
  try {
    const r = (await http.post('/research/import', { csv: importText.value, source: importSource.value, market_id: Number(importMarket.value) })).data
    message.success(t('读到 {0} 个关键词，新增 {1} 个', [r.total, r.created]))
    showImport.value = false
    importText.value = ''
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  }
}

// Evidence / AI
const working = ref(false)
async function run(kind: 'enrich' | 'analyze', ids = selected.value) {
  working.value = true
  try {
    const r = (await http.post(`/research/${kind}`, { ids })).data
    if (r.errors.length) message.warning(t('{0} 个完成，{1} 个失败：{2}', [r.done, r.errors.length, r.errors[0].message]))
    else message.success(t('已处理 {0} 个', [r.done]))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    working.value = false
  }
}
async function setStatus(o: Opp, s: Status) {
  try {
    await http.put(`/research/opportunities/${o.id}`, { status: s })
    o.status = s
  } catch (e) {
    message.error(errorMessage(e))
  }
}
async function saveNote(o: Opp) {
  try {
    await http.put(`/research/opportunities/${o.id}`, { note: o.note })
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  }
}
function remove(o: Opp) {
  dialog.warning({
    title: t('删除「{0}」？', [o.keyword]), positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => { await http.delete(`/research/opportunities/${o.id}`); detail.value = null; await load() },
  })
}

// Settings
const showSettings = ref(false)
const serpKey = ref('')
async function saveKey(clear = false) {
  try {
    const r = (await http.put('/research/settings', { serpapi_key: clear ? null : serpKey.value })).data
    serpConnected.value = r.serpapi_connected
    serpKey.value = ''
    showSettings.value = false
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  }
}

// Detail
const detail = ref<Opp | null>(null)
const scoreParts = computed(() => {
  const s = detail.value?.scores ?? {}
  return [['demand', t('需求')], ['trend', t('趋势')], ['competition', t('竞争')], ['price', t('价格匹配')], ['fit', t('AI 适合度')]]
    .map(([k, label]) => ({ k, label, v: s[k] ?? null }))
})
// Sparkline of the trend series
function spark(series: number[]): string {
  if (series.length < 2) return ''
  const max = Math.max(...series, 1)
  return series.map((v, i) => `${(i / (series.length - 1)) * 200},${40 - (v / max) * 36}`).join(' ')
}
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Sourcing</div>
        <h1 class="page-title">{{ t('选品雷达') }}</h1>
        <p class="muted">{{ t('从站内搜索和测款数据里找机会，再用搜索趋势、购物平台竞争和价格验证，AI 按市场给出判断和建议售价。分数越高，越值得优先做。') }}</p>
      </div>
      <div class="head-actions">
        <NButton secondary @click="showSettings = true">{{ t('数据源设置') }}</NButton>
        <NButton secondary @click="showAdd = true">{{ t('添加关键词') }}</NButton>
        <NButton secondary @click="showImport = true">{{ t('导入数据') }}</NButton>
        <NButton type="primary" :loading="discovering" @click="discover">{{ t('从站内数据发现') }}</NButton>
      </div>
    </div>

    <NAlert v-if="!serpConnected" type="info" :bordered="false">
      {{ t('填写 SerpApi Key 后，可以给每个机会补充 Google 搜索趋势和 Google Shopping 的竞争、价格数据（每个机会消耗 2 次搜索）。没有 Key 时，只用站内数据和 AI 分析。') }}
      <NButton text type="primary" @click="showSettings = true">{{ t('去设置') }}</NButton>
    </NAlert>

    <NCard size="small">
      <div class="filters">
        <NSelect v-model:value="market" :options="marketOptions" size="small" style="width: 150px" />
        <NSelect v-model:value="status" size="small" style="width: 140px"
          :options="[{ label: t('进行中的'), value: 'open' }, ...statusOptions.map((o) => ({ ...o, label: `${o.label}${counts[o.value] ? ` (${counts[o.value]})` : ''}` }))]" />
        <NInput v-model:value="q" size="small" clearable :placeholder="t('搜索关键词')" style="width: 200px" />
      </div>
      <div v-if="selected.length" class="toolbar">
        <span class="strong">{{ t('已选 {0} 个', [selected.length]) }}</span>
        <NTooltip :disabled="serpConnected">
          <template #trigger><NButton size="small" secondary :loading="working" :disabled="!serpConnected || selected.length > 20" @click="run('enrich')">{{ t('获取趋势和竞争数据') }}</NButton></template>
          {{ t('需要先填写 SerpApi Key') }}
        </NTooltip>
        <NTooltip :disabled="aiReady && selected.length <= 5">
          <template #trigger><NButton size="small" type="primary" secondary :loading="working" :disabled="!aiReady || selected.length > 5" @click="run('analyze')">{{ t('AI 市场分析') }}</NButton></template>
          {{ aiReady ? t('一次最多分析 5 个') : t('在「获客雷达 › 设置」填写 Claude API Key 后可用') }}
        </NTooltip>
      </div>

      <div v-if="loading" class="center"><NSpin /></div>
      <NEmpty v-else-if="!rows.length" :description="t('还没有选品机会。点「从站内数据发现」，或手动添加关键词。')" style="padding: 40px 0" />
      <div v-else class="table-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th><NCheckbox :checked="allChecked" :aria-label="t('全选')" @update:checked="(v: boolean) => selected = v ? rows.map((r) => r.id) : []" /></th>
              <th>{{ t('关键词') }}</th><th>{{ t('市场') }}</th><th>{{ t('依据') }}</th><th class="r">{{ t('趋势') }}</th>
              <th class="r">{{ t('卖家数') }}</th><th class="r">{{ t('中位价') }}</th><th>{{ t('分数') }}</th><th>{{ t('状态') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in rows" :key="o.id">
              <td><NCheckbox :checked="selected.includes(o.id)" :aria-label="t('选择')" @update:checked="(v: boolean) => selected = v ? [...selected, o.id] : selected.filter((x) => x !== o.id)" /></td>
              <td>
                <button type="button" class="kw" @click="detail = o">{{ o.keyword }}</button>
                <div v-if="o.ai" class="muted small one-line">{{ o.ai.summary }}</div>
              </td>
              <td class="nowrap">{{ o.market }}</td>
              <td class="nowrap">
                <NTag size="small" :bordered="false">{{ sourceLabel[o.source] ?? o.source }}</NTag>
                <span v-if="o.signals?.site?.zero_results" class="muted small"> {{ t('{0} 次无结果', [o.signals.site.zero_results]) }}</span>
              </td>
              <td class="r num">
                <template v-if="o.signals?.trend?.growth != null"><span :class="o.signals.trend.growth >= 0 ? 'up' : 'down'">{{ o.signals.trend.growth > 0 ? '+' : '' }}{{ o.signals.trend.growth }}%</span></template>
                <span v-else class="muted">—</span>
              </td>
              <td class="r num">{{ o.signals?.shopping ? o.signals.shopping.sellers : '—' }}</td>
              <td class="r num">{{ o.signals?.shopping ? money(o.signals.shopping.median, o.signals.shopping.currency) : '—' }}</td>
              <td>
                <div class="score-cell">
                  <NProgress type="line" :percentage="o.score ?? 0" :color="color(o.score)" :show-indicator="false" style="width: 60px" />
                  <span class="score" :style="{ color: color(o.score) }">{{ o.score ?? '—' }}</span>
                </div>
              </td>
              <td><NSelect :value="o.status" :options="statusOptions" size="tiny" style="width: 96px" @update:value="(v: Status) => setStatus(o, v)" /></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="meta.last_page > 1" class="pager"><NPagination v-model:page="page" :page-count="meta.last_page" /></div>
    </NCard>

    <NDrawer :show="!!detail" width="min(640px, 100vw)" @update:show="(v: boolean) => { if (!v) detail = null }">
      <NDrawerContent v-if="detail" :title="detail.keyword" closable>
        <div class="d-head">
          <NTag :bordered="false">{{ detail.market }}</NTag>
          <NTag :bordered="false">{{ sourceLabel[detail.source] ?? detail.source }}</NTag>
          <span class="d-score" :style="{ color: color(detail.score) }">{{ detail.score ?? '—' }}</span>
        </div>
        <div class="parts">
          <div v-for="p in scoreParts" :key="p.k" class="part">
            <span class="muted small">{{ p.label }}</span>
            <NProgress type="line" :percentage="p.v ?? 0" :color="p.v === null ? 'var(--border)' : color(p.v)" :show-indicator="false" />
            <span class="small num">{{ p.v ?? t('无数据') }}</span>
          </div>
        </div>

        <div class="section-title">{{ t('依据') }}</div>
        <ul class="facts">
          <li v-if="detail.signals?.site?.searches">{{ t('站内被搜索 {0} 次，其中 {1} 次没有结果', [detail.signals.site.searches, detail.signals.site.zero_results ?? 0]) }}</li>
          <li v-if="detail.signals?.site?.hot_products">{{ t('这个品类有 {0} 个商品是测款潜力款', [detail.signals.site.hot_products]) }}</li>
          <li v-if="detail.signals?.trend">
            {{ t('Google 搜索热度平均 {0}，近 4 周变化 {1}', [detail.signals.trend.average, detail.signals.trend.growth == null ? '—' : detail.signals.trend.growth + '%']) }}
            <svg v-if="detail.signals.trend.series.length > 1" viewBox="0 0 200 40" class="spark" aria-hidden="true"><polyline :points="spark(detail.signals.trend.series)" fill="none" stroke="var(--brand-600)" stroke-width="2" /></svg>
          </li>
          <li v-if="detail.signals?.shopping">
            {{ t('Google Shopping：{0} 个卖家，价格 {1} – {2}，中位 {3}', [detail.signals.shopping.sellers, money(detail.signals.shopping.min, detail.signals.shopping.currency), money(detail.signals.shopping.max, detail.signals.shopping.currency), money(detail.signals.shopping.median, detail.signals.shopping.currency)]) }}
          </li>
          <li v-if="detail.signals?.external">
            {{ t('{0}（{1} 导入）：', [detail.signals.external.source, detail.signals.external.imported_at]) }}{{ [
              detail.signals.external.volume != null ? t('热度 {0}', [detail.signals.external.volume.toLocaleString()]) : null,
              detail.signals.external.growth != null ? t('增长 {0}%', [detail.signals.external.growth]) : null,
              detail.signals.external.sellers != null ? t('{0} 个卖家', [detail.signals.external.sellers]) : null,
              detail.signals.external.price != null ? t('价格 {0}', [(detail.signals.external.price / 100).toFixed(2)]) : null,
            ].filter(Boolean).join(' · ') }}
          </li>
          <li v-if="!detail.enriched_at" class="muted">{{ t('还没有获取外部数据') }}</li>
        </ul>
        <div v-if="detail.signals?.shopping?.examples?.length" class="examples">
          <div v-for="(x, i) in detail.signals.shopping.examples" :key="i" class="example"><span class="one-line">{{ x.title }}</span><span class="muted small nowrap">{{ x.price }} · {{ x.source }}</span></div>
        </div>
        <div class="inline-actions">
          <NButton size="small" secondary :loading="working" :disabled="!serpConnected" @click="run('enrich', [detail.id])">{{ detail.enriched_at ? t('刷新外部数据') : t('获取外部数据') }}</NButton>
          <NButton size="small" type="primary" secondary :loading="working" :disabled="!aiReady" @click="run('analyze', [detail.id])">{{ detail.ai ? t('重新分析') : t('AI 市场分析') }}</NButton>
        </div>

        <template v-if="detail.ai">
          <div class="section-title">{{ t('AI 分析') }} <span class="muted small">{{ detail.analyzed_at ? formatDate(detail.analyzed_at) : '' }}</span></div>
          <p class="summary">{{ detail.ai.summary }}</p>
          <dl class="ai">
            <dt>{{ t('建议售价') }}</dt><dd>{{ detail.ai.currency }} {{ detail.ai.price_band.min }} – {{ detail.ai.price_band.max }}</dd>
            <dt v-if="detail.ai.season">{{ t('旺季') }}</dt><dd v-if="detail.ai.season">{{ detail.ai.season }}</dd>
            <dt>{{ t('当地搜索词') }}</dt><dd><NTag v-for="k in detail.ai.local_keywords" :key="k" size="small" :bordered="false" class="tag">{{ k }}</NTag></dd>
            <dt>{{ t('标题参考') }}</dt><dd><div v-for="x in detail.ai.title_ideas" :key="x">{{ x }}</div></dd>
            <dt v-if="detail.ai.risks.length">{{ t('风险') }}</dt><dd v-if="detail.ai.risks.length"><ul class="risks"><li v-for="r in detail.ai.risks" :key="r">{{ r }}</li></ul></dd>
          </dl>
        </template>

        <div class="section-title">{{ t('找货') }}</div>
        <div class="links">
          <a v-for="l in detail.links" :key="l.name" :href="l.url" target="_blank" rel="noopener noreferrer">{{ l.name }}</a>
        </div>
        <p class="muted small">{{ t('在供应商页面找到合适的商品后，用浏览器插件采集，或把网址粘贴到') }} <RouterLink :to="{ name: 'collect' }">{{ t('商品采集') }}</RouterLink>。</p>

        <div class="section-title">{{ t('备注') }}</div>
        <NInput v-model:value="(detail.note as string)" type="textarea" maxlength="500" :autosize="{ minRows: 2 }" :placeholder="t('例如：供应商报价、样品情况')" />
        <template #footer>
          <div class="foot">
            <NButton quaternary type="error" @click="remove(detail)">{{ t('删除') }}</NButton>
            <NSelect :value="detail.status" :options="statusOptions" size="small" style="width: 120px" @update:value="(v: Status) => setStatus(detail!, v)" />
            <NButton type="primary" @click="saveNote(detail)">{{ t('保存备注') }}</NButton>
          </div>
        </template>
      </NDrawerContent>
    </NDrawer>

    <NModal v-model:show="showAdd" preset="card" :title="t('添加关键词')" style="max-width: 480px">
      <NSelect v-model:value="addMarket" :options="marketOptions.filter((o) => o.value !== 'all')" style="margin-bottom: 10px" />
      <NInput v-model:value="addText" type="textarea" :autosize="{ minRows: 5 }" :placeholder="t('每行一个，例如：silver anklet')" />
      <template #footer>
        <div class="foot"><NButton @click="showAdd = false">{{ t('取消') }}</NButton><NButton type="primary" :disabled="!addText.trim()" @click="add">{{ t('添加') }}</NButton></div>
      </template>
    </NModal>

    <NModal v-model:show="showImport" preset="card" :title="t('导入其他工具的数据')" style="max-width: 600px">
      <p class="muted small" style="margin: 0 0 10px">{{ t('从 TikTok Creative Center、Keepa、Helium 10、Google 关键词规划师等工具导出 CSV，上传或粘贴到这里。会自动识别关键词、热度（搜索量 / 播放量 / 销量）、增长率、价格和卖家数这几列，并计入评分。') }}</p>
      <div class="two-sel">
        <NSelect v-model:value="importSource" :options="importSources" filterable tag />
        <NSelect v-model:value="importMarket" :options="marketOptions.filter((o) => o.value !== 'all')" />
      </div>
      <input type="file" accept=".csv,.tsv,.txt" style="margin: 10px 0" @change="readFile">
      <NInput v-model:value="importText" type="textarea" :autosize="{ minRows: 6, maxRows: 14 }" placeholder="keyword,volume,growth,price&#10;silver anklet,120000,35%,12.99" />
      <template #footer>
        <div class="foot"><NButton @click="showImport = false">{{ t('取消') }}</NButton><NButton type="primary" :disabled="!importText.trim()" @click="runImport">{{ t('导入') }}</NButton></div>
      </template>
    </NModal>

    <NModal v-model:show="showSettings" preset="card" :title="t('数据源设置')" style="max-width: 520px">
      <p class="muted">{{ t('SerpApi（serpapi.com）通过官方接口提供 Google 搜索趋势和 Google Shopping 数据，按搜索次数收费。Key 加密保存，只用于这个店铺。') }}</p>
      <NInput v-model:value="serpKey" type="password" show-password-on="click" :placeholder="serpConnected ? t('已连接（留空保持不变）') : t('SerpApi API Key')" style="margin-top: 10px" />
      <template #footer>
        <div class="foot">
          <NButton v-if="serpConnected" quaternary type="error" @click="saveKey(true)">{{ t('断开') }}</NButton>
          <NButton @click="showSettings = false">{{ t('取消') }}</NButton>
          <NButton type="primary" :disabled="!serpKey.trim()" @click="saveKey()">{{ t('保存') }}</NButton>
        </div>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.two-sel { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.page { display: grid; gap: 16px; max-width: 1200px; grid-template-columns: minmax(0, 1fr); }
.head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
.head > div:first-child { flex: 1 1 320px; min-width: 0; }
.head-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.page-title { margin: 0 0 4px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0; }
.small { font-size: 12px; }
.strong { font-weight: 600; }
.filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 8px; }
.toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 8px 10px; background: var(--surface-muted); border-radius: 8px; margin-bottom: 8px; }
.center { display: flex; justify-content: center; padding: 40px; }
.table-wrap { overflow-x: auto; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { text-align: left; font-weight: 500; color: var(--ink-muted); padding: 8px 6px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.tbl td { padding: 8px 6px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.r { text-align: right; }
.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.nowrap { white-space: nowrap; }
.up { color: var(--brand-700); font-weight: 600; }
.down { color: #b42318; }
.kw { background: none; border: 0; padding: 0; font: inherit; font-weight: 600; font-size: 14px; color: var(--ink); cursor: pointer; text-align: left; }
.kw:hover { color: var(--brand-700); text-decoration: underline; }
.one-line { display: block; max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.score-cell { display: flex; align-items: center; gap: 8px; }
.score { font-weight: 700; font-variant-numeric: tabular-nums; width: 24px; }
.pager { display: flex; justify-content: flex-end; padding-top: 12px; }
.d-head { display: flex; gap: 8px; align-items: center; }
.d-score { margin-left: auto; font-size: 28px; font-weight: 700; }
.parts { display: grid; gap: 6px; margin-top: 12px; }
.part { display: grid; grid-template-columns: 80px 1fr 50px; gap: 10px; align-items: center; }
.section-title { font-weight: 600; margin: 18px 0 8px; }
.facts { margin: 0; padding-left: 18px; font-size: 13px; line-height: 1.8; }
.spark { display: block; width: 200px; height: 40px; margin-top: 4px; }
.examples { display: grid; gap: 4px; margin: 8px 0; font-size: 13px; }
.example { display: flex; justify-content: space-between; gap: 10px; }
.inline-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
.summary { font-size: 14px; line-height: 1.7; margin: 0 0 10px; }
.ai { display: grid; grid-template-columns: 90px 1fr; gap: 6px 12px; font-size: 13px; margin: 0; }
.ai dt { color: var(--ink-muted); }
.ai dd { margin: 0; }
.tag { margin: 0 4px 4px 0; }
.risks { margin: 0; padding-left: 16px; }
.links { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 6px; }
.links a { color: var(--brand-700); font-weight: 500; }
.foot { display: flex; justify-content: flex-end; align-items: center; gap: 8px; width: 100%; }
.foot > :first-child { margin-right: auto; }
</style>
