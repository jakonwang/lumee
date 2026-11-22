<script setup lang="ts">
/**
 * Products › Test-and-learn (测款): how new products perform in their first weeks — overall or in
 * one market — against the store average, with a verdict and next steps; plus what shoppers
 * search for and don't find.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  NButton, NCard, NCheckbox, NDrawer, NDrawerContent, NEmpty, NInputNumber, NModal, NRadioButton, NRadioGroup, NSelect, NSpin, NTag, useMessage,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

type Status = 'hot' | 'testing' | 'cold' | 'no_traffic'
interface Row {
  id: number; title: string; image: string | null; age_days: number; views: number; carts: number; cart_rate: number
  orders: number; units: number; conversion: number; refund_rate: number; revenue: string; status: Status; advice: string[]
}
const message = useMessage()

const tab = ref<'products' | 'search'>('products')
const market = ref<string>('all')
const scope = ref<'new' | 'all'>('new')
const status = ref<Status | 'all'>('all')
const loading = ref(true)
const rows = ref<Row[]>([])
const counts = ref<Record<string, number>>({})
const info = ref({ baseline: 0, window_days: 14, from: '', store_currency: 'USD', experiments: 0 })
const markets = ref<{ id: number; name: string }[]>([])
const collections = ref<{ id: number; title: string }[]>([])
const settings = reactive({ window_days: 14, min_views: 50, hot_multiplier: 2 })
const selected = ref<number[]>([])

async function load() {
  loading.value = true
  try {
    const d = (await http.get('/testing', { params: { market: market.value, scope: scope.value, status: status.value === 'all' ? undefined : status.value } })).data
    rows.value = d.data
    counts.value = d.counts
    info.value = { baseline: d.baseline, window_days: d.window_days, from: d.from, store_currency: d.store_currency, experiments: d.experiments }
    markets.value = d.markets
    collections.value = d.collections
    Object.assign(settings, d.settings)
    selected.value = []
  } finally {
    loading.value = false
  }
}
watch([market, scope, status], load)

const statusLabel: Record<Status, string> = { hot: t('潜力款'), testing: t('观察中'), cold: t('滞销'), no_traffic: t('流量不足') }
const statusType: Record<Status, 'success' | 'default' | 'error' | 'warning'> = { hot: 'success', testing: 'default', cold: 'error', no_traffic: 'warning' }
const tabs = computed(() => [{ key: 'all', label: t('全部'), n: Object.values(counts.value).reduce((a, b) => a + b, 0) },
  ...(['hot', 'testing', 'cold', 'no_traffic'] as Status[]).map((k) => ({ key: k, label: statusLabel[k], n: counts.value[k] ?? 0 }))])
const marketOptions = computed(() => [{ label: t('所有市场'), value: 'all' }, { label: t('默认站点'), value: '0' }, ...markets.value.map((m) => ({ label: m.name, value: String(m.id) }))])
const allChecked = computed(() => rows.value.length > 0 && rows.value.every((r) => selected.value.includes(r.id)))

// Settings
const showSettings = ref(false)
const savingSettings = ref(false)
async function saveSettings() {
  savingSettings.value = true
  try {
    await http.put('/testing/settings', settings)
    showSettings.value = false
    message.success(t('已保存'))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    savingSettings.value = false
  }
}

// Bulk
const working = ref(false)
const collectionId = ref<number | null>(null)
async function bulk(action: string, extra: Record<string, unknown> = {}) {
  working.value = true
  try {
    const r = (await http.post('/testing/bulk', { ids: selected.value, action, ...extra })).data
    message.success(t('已处理 {0} 个', [r.done]))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    working.value = false
  }
}

// Product drawer: per market
const detail = ref<{ row: Row; markets: { id: number; name: string; sold: boolean; views: number; carts: number; orders: number }[] } | null>(null)
async function open(r: Row) {
  try {
    const d = (await http.get(`/testing/products/${r.id}`)).data.data
    detail.value = { row: r, markets: d.markets }
  } catch (e) {
    message.error(errorMessage(e))
  }
}
const rate = (c: number, v: number) => (v ? `${((c / v) * 100).toFixed(1)}%` : '—')

// Search terms
interface Term { term: string; searches: number; zero_results: number; last_date: string; markets: string[] }
const days = ref(30)
const termMarket = ref('all')
const terms = ref<{ top: Term[]; no_results: Term[]; total: number; zero: number } | null>(null)
async function loadTerms() {
  terms.value = (await http.get('/testing/search-terms', { params: { days: days.value, market: termMarket.value } })).data
}
watch([days, termMarket], loadTerms)
watch(tab, (v) => { if (v === 'search' && !terms.value) loadTerms() })

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Sourcing</div>
        <h1 class="page-title">{{ t('测款') }}</h1>
        <p class="muted">{{ t('新品上架后的 {0} 天里，按浏览、加购和订单判断哪些是潜力款、哪些卖不动，并给出下一步建议。可以按市场分别看。', [info.window_days]) }}</p>
      </div>
      <NButton secondary @click="showSettings = true">{{ t('测款规则') }}</NButton>
    </div>
    <NRadioGroup v-model:value="tab" size="small">
      <NRadioButton value="products">{{ t('商品表现') }}</NRadioButton>
      <NRadioButton value="search">{{ t('站内搜索词') }}</NRadioButton>
    </NRadioGroup>

    <template v-if="tab === 'products'">
      <NCard size="small">
        <div class="filters">
          <NSelect v-model:value="market" :options="marketOptions" size="small" style="width: 160px" />
          <NRadioGroup v-model:value="scope" size="small">
            <NRadioButton value="new">{{ t('新品') }}</NRadioButton>
            <NRadioButton value="all">{{ t('全部在售') }}</NRadioButton>
          </NRadioGroup>
          <span class="muted">{{ t('统计自 {0} · 店铺平均加购率 {1}%', [info.from, info.baseline]) }}</span>
          <span v-if="info.experiments" class="small exp">{{ t('{0} 个 A/B 测试进行中', [info.experiments]) }}</span>
        </div>
        <div class="tabs" role="tablist">
          <button v-for="tb in tabs" :key="tb.key" role="tab" class="tab" :class="{ on: status === tb.key }" :aria-selected="status === tb.key" @click="status = tb.key as Status | 'all'">
            {{ tb.label }}<span v-if="tb.n" class="count">{{ tb.n }}</span>
          </button>
        </div>

        <div v-if="selected.length" class="toolbar">
          <span class="strong">{{ t('已选 {0} 个', [selected.length]) }}</span>
          <NSelect v-model:value="collectionId" size="small" clearable :placeholder="t('选择分类')" :options="collections.map((c) => ({ label: c.title, value: c.id }))" style="width: 160px" />
          <NButton size="small" secondary :disabled="!collectionId || working" @click="bulk('collection', { collection_id: collectionId })">{{ t('加入分类') }}</NButton>
          <NButton size="small" secondary :disabled="working" @click="bulk('sell_everywhere')">{{ t('在所有市场销售') }}</NButton>
          <NButton size="small" quaternary :disabled="working" @click="bulk('draft')">{{ t('转为草稿') }}</NButton>
          <NButton size="small" quaternary type="error" :disabled="working" @click="bulk('archive')">{{ t('下架归档') }}</NButton>
        </div>

        <div v-if="loading" class="center"><NSpin /></div>
        <NEmpty v-else-if="!rows.length" :description="scope === 'new' ? t('最近没有新上架的商品') : t('没有在售商品')" style="padding: 32px 0" />
        <div v-else class="table-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th><NCheckbox :checked="allChecked" :aria-label="t('全选')" @update:checked="(v: boolean) => selected = v ? rows.map((r) => r.id) : []" /></th>
                <th>{{ t('商品') }}</th><th>{{ t('判断') }}</th><th class="r">{{ t('浏览') }}</th><th class="r">{{ t('加购率') }}</th>
                <th class="r">{{ t('订单') }}</th><th class="r">{{ t('转化率') }}</th><th class="r">{{ t('销售额（{0}）', [info.store_currency]) }}</th><th>{{ t('建议') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rows" :key="r.id">
                <td><NCheckbox :checked="selected.includes(r.id)" :aria-label="t('选择')" @update:checked="(v: boolean) => selected = v ? [...selected, r.id] : selected.filter((x) => x !== r.id)" /></td>
                <td>
                  <div class="prod">
                    <img v-if="r.image" :src="r.image" alt="" class="thumb">
                    <div v-else class="thumb" />
                    <div>
                      <button type="button" class="title" @click="open(r)">{{ r.title }}</button>
                      <div class="muted small">{{ t('上架 {0} 天', [r.age_days]) }}</div>
                    </div>
                  </div>
                </td>
                <td><NTag size="small" :type="statusType[r.status]" :bordered="false">{{ statusLabel[r.status] }}</NTag></td>
                <td class="r num">{{ r.views }}</td>
                <td class="r num" :class="{ up: r.cart_rate >= info.baseline * 2 && r.views > 0, down: r.views > 0 && r.cart_rate < info.baseline / 2 }">{{ r.cart_rate }}%</td>
                <td class="r num">{{ r.orders }}</td>
                <td class="r num">{{ r.conversion }}%</td>
                <td class="r num">{{ r.revenue }}</td>
                <td class="advice">{{ r.advice[0] }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </NCard>
    </template>

    <template v-else>
      <NCard size="small">
        <div class="filters">
          <NSelect v-model:value="termMarket" :options="marketOptions" size="small" style="width: 160px" />
          <NRadioGroup v-model:value="days" size="small">
            <NRadioButton :value="7">{{ t('7 天') }}</NRadioButton><NRadioButton :value="30">{{ t('30 天') }}</NRadioButton><NRadioButton :value="90">{{ t('90 天') }}</NRadioButton>
          </NRadioGroup>
          <span v-if="terms" class="muted">{{ t('共 {0} 次搜索，{1} 次没有结果', [terms.total, terms.zero]) }}</span>
        </div>
      </NCard>
      <div v-if="terms" class="two-cards">
        <NCard :title="t('搜了但没找到')" size="small">
          <p class="muted">{{ t('买家想要、店里还没有的商品，是最直接的选品线索。') }}</p>
          <NEmpty v-if="!terms.no_results.length" :description="t('暂无')" />
          <table v-else class="tbl">
            <thead><tr><th>{{ t('搜索词') }}</th><th class="r">{{ t('无结果次数') }}</th><th>{{ t('市场') }}</th></tr></thead>
            <tbody>
              <tr v-for="x in terms.no_results" :key="x.term"><td class="strong">{{ x.term }}</td><td class="r num">{{ x.zero_results }}</td><td class="muted small">{{ x.markets.join('、') }}</td></tr>
            </tbody>
          </table>
          <RouterLink :to="{ name: 'collect' }" class="small link">{{ t('去采集同类商品') }}</RouterLink>
        </NCard>
        <NCard :title="t('热门搜索词')" size="small">
          <NEmpty v-if="!terms.top.length" :description="t('暂无')" />
          <table v-else class="tbl">
            <thead><tr><th>{{ t('搜索词') }}</th><th class="r">{{ t('次数') }}</th><th class="r">{{ t('无结果') }}</th></tr></thead>
            <tbody>
              <tr v-for="x in terms.top" :key="x.term"><td>{{ x.term }}</td><td class="r num">{{ x.searches }}</td><td class="r num">{{ x.zero_results || '' }}</td></tr>
            </tbody>
          </table>
        </NCard>
      </div>
    </template>

    <NDrawer :show="!!detail" width="min(560px, 100vw)" @update:show="(v: boolean) => { if (!v) detail = null }">
      <NDrawerContent v-if="detail" :title="detail.row.title" closable>
        <NTag :type="statusType[detail.row.status]" :bordered="false">{{ statusLabel[detail.row.status] }}</NTag>
        <ul class="advice-list"><li v-for="(a, i) in detail.row.advice" :key="i">{{ a }}</li></ul>
        <div class="section-title">{{ t('各市场表现（近 {0} 天）', [info.window_days]) }}</div>
        <table class="tbl">
          <thead><tr><th>{{ t('市场') }}</th><th class="r">{{ t('浏览') }}</th><th class="r">{{ t('加购率') }}</th><th class="r">{{ t('订单') }}</th></tr></thead>
          <tbody>
            <tr v-for="m in detail.markets" :key="m.id">
              <td>{{ m.name }} <NTag v-if="!m.sold" size="tiny" :bordered="false" type="warning">{{ t('未销售') }}</NTag></td>
              <td class="r num">{{ m.views }}</td><td class="r num">{{ rate(m.carts, m.views) }}</td><td class="r num">{{ m.orders }}</td>
            </tr>
          </tbody>
        </table>
        <p class="muted small" style="margin-top: 12px">{{ t('在商品页的「A/B 测试」里可以测试另一张主图或另一个标题。') }}</p>
        <template #footer>
          <RouterLink :to="{ name: 'product-edit', params: { id: detail.row.id } }"><NButton type="primary">{{ t('打开商品') }}</NButton></RouterLink>
        </template>
      </NDrawerContent>
    </NDrawer>

    <NModal v-model:show="showSettings" preset="card" :title="t('测款规则')" style="max-width: 480px">
      <div class="settings">
        <label>{{ t('测款周期（天）') }}<NInputNumber v-model:value="settings.window_days" :min="3" :max="90" /></label>
        <label>{{ t('至少多少次浏览才下结论') }}<NInputNumber v-model:value="settings.min_views" :min="5" :max="10000" /></label>
        <label>{{ t('加购率达到店铺平均的几倍算潜力款') }}<NInputNumber v-model:value="settings.hot_multiplier" :min="1.1" :max="10" :step="0.1" :precision="1" /></label>
        <p class="muted small">{{ t('满 3 单也算潜力款。测满一个周期、浏览够了、0 订单且加购率不到平均一半的，算滞销。上架 3 天以上几乎没人看的，算流量不足。') }}</p>
      </div>
      <template #footer>
        <div class="foot"><NButton @click="showSettings = false">{{ t('取消') }}</NButton><NButton type="primary" :loading="savingSettings" @click="saveSettings">{{ t('保存') }}</NButton></div>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; max-width: 1200px; grid-template-columns: minmax(0, 1fr); }   /* wide tables scroll inside their card */
.head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
.head > div { flex: 1 1 320px; min-width: 0; }
.page-title { margin: 0 0 4px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0; }
.small { font-size: 12px; }
.strong { font-weight: 600; }
.filters { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.exp { margin-left: auto; color: var(--brand-700); }
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin: 12px 0 8px; flex-wrap: wrap; }
.tab { background: none; border: 0; padding: 9px 12px; font-size: 14px; color: var(--ink-muted); border-bottom: 2px solid transparent; margin-bottom: -1px; cursor: pointer; }
.tab.on { color: var(--brand-700); border-bottom-color: var(--brand-600); font-weight: 500; }
.count { margin-left: 6px; font-size: 12px; background: var(--surface-muted); border-radius: 999px; padding: 1px 7px; }
.toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 8px 10px; background: var(--surface-muted); border-radius: 8px; margin-bottom: 8px; }
.center { display: flex; justify-content: center; padding: 40px; }
.table-wrap { overflow-x: auto; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { text-align: left; font-weight: 500; color: var(--ink-muted); padding: 8px 6px; white-space: nowrap; border-bottom: 1px solid var(--border); }
.tbl td { padding: 8px 6px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.r { text-align: right; }
.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.up { color: var(--brand-700); font-weight: 600; }
.down { color: #b42318; }
.prod { display: flex; gap: 10px; align-items: center; min-width: 200px; }
.thumb { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border); background: var(--surface-muted); flex-shrink: 0; }
.title { background: none; border: 0; padding: 0; text-align: left; font: inherit; font-weight: 500; font-size: 14px; color: var(--ink); cursor: pointer; }
.title:hover { color: var(--brand-700); text-decoration: underline; }
.advice { color: var(--ink-muted); min-width: 180px; }
.two-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.link { color: var(--brand-700); display: inline-block; margin-top: 10px; }
.advice-list { margin: 12px 0; padding-left: 18px; font-size: 14px; line-height: 1.7; }
.section-title { font-weight: 600; margin: 16px 0 8px; }
.settings { display: grid; gap: 12px; }
.settings label { display: grid; gap: 4px; font-size: 13px; color: var(--ink-muted); }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
@media (max-width: 760px) { .two-cards { grid-template-columns: 1fr; } }
</style>
