<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { NCard, NSelect, NDatePicker, NSkeleton, NButton, NEmpty, NAlert, NRadioGroup, NRadioButton, NTooltip } from 'naive-ui'
import { http } from '@/api/http'
import LineChart from '@/components/charts/LineChart.vue'
import FunnelChart from '@/components/charts/FunnelChart.vue'
import ColumnChart from '@/components/charts/ColumnChart.vue'
import { t } from '@/i18n'

interface Stat { value: string | number; change: number | null }
interface Row { key: string | null; sessions: number; purchases: number; revenue: string; conversion: number }
interface Analytics {
  currency: string
  timezone: string
  range: [string, string]
  previous: [string, string]
  granularity: 'day' | 'hour'
  totals: Record<'revenue' | 'orders' | 'aov' | 'visitors' | 'sessions' | 'conversion' | 'customers', Stat>
  pending_orders: number
  discounted_orders: number
  series: { key: string; revenue: string; orders: number; sessions: number; prev_revenue: string; prev_orders: number; prev_sessions: number }[]
  hours: number[]
  funnel: { key: string; sessions: number; prev: number }[]
  sources: Row[]
  devices: Row[]
  browsers: Row[]
  landing_pages: Row[]
  referrers: Row[]
  campaigns: Row[]
  countries: { code: string | null; name: string; sessions: number; orders: number; revenue: string; share: number }[]
  products: { id: number | null; title: string; views: number; carts: number; qty: number; orders: number; revenue: string; cart_rate: number | null; conversion: number | null }[]
  customers: { new: { orders: number; revenue: string }; returning: { orders: number; revenue: string } }
  returning_visitors: number
  live: number
}

// ---- Date range (one row of filters above everything) ----
const preset = ref<string>('30')
const custom = ref<[number, number] | null>(null)
const presets = [
  { label: t('今天'), value: '1' },
  { label: t('昨天'), value: 'yesterday' },
  { label: t('近 7 天'), value: '7' },
  { label: t('近 30 天'), value: '30' },
  { label: t('近 90 天'), value: '90' },
  { label: t('自定义'), value: 'custom' },
]
const ymd = (ms: number) => {
  const d = new Date(ms)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function params() {
  if (preset.value === 'yesterday') {
    const y = ymd(Date.now() - 86400000)
    return { from: y, to: y }
  }
  if (preset.value === 'custom') return custom.value ? { from: ymd(custom.value[0]), to: ymd(custom.value[1]) } : null
  return { days: Number(preset.value) }
}

const data = ref<Analytics | null>(null)
const loading = ref(true)
async function load() {
  const p = params()
  if (!p) return
  loading.value = true
  try {
    data.value = (await http.get('/analytics', { params: p })).data
  } finally {
    loading.value = false
  }
}
watch([preset, custom], load)
onMounted(load)

// Live visitors refresh every minute
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  timer = setInterval(async () => {
    if (document.hidden || !data.value) return
    const p = params()
    if (p) data.value.live = (await http.get('/analytics', { params: p })).data.live
  }, 60000)
})
onBeforeUnmount(() => clearInterval(timer))

const fmtMoney = (v: number | string) => Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const money = (v: number | string) => `${data.value?.currency ?? ''} ${fmtMoney(v)}`
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v.toFixed(v >= 10 ? 1 : 2)}%`)
const num = (v: number) => v.toLocaleString()

// ---- KPI tiles ----
const tiles = computed(() => {
  const it = data.value?.totals
  if (!it) return []
  return [
    { label: t('销售额'), value: money(it.revenue.value), change: it.revenue.change, hint: t('已付款订单，扣除退款') },
    { label: t('订单数'), value: num(Number(it.orders.value)), change: it.orders.change, hint: data.value!.pending_orders ? t('其中 {0} 笔待付款', [data.value!.pending_orders]) : '' },
    { label: t('客单价'), value: money(it.aov.value), change: it.aov.change, hint: '' },
    { label: t('访客数'), value: num(Number(it.visitors.value)), change: it.visitors.change, hint: t('{0} 次访问', [num(Number(it.sessions.value))]) },
    { label: t('转化率'), value: pct(Number(it.conversion.value)), change: it.conversion.change, hint: t('完成付款的访问占比') },
    { label: t('新客户'), value: num(Number(it.customers.value)), change: it.customers.change, hint: '' },
  ]
})

// ---- Trend ----
const metric = ref<'revenue' | 'orders' | 'sessions'>('revenue')
const showTable = ref(false)
const shortDate = (s: string) => s.slice(5).replace('-', '/')
const trend = computed(() => (data.value?.series ?? []).map((d, i) => {
  const prevKey = data.value!.granularity === 'hour' ? d.key : shiftDate(data.value!.previous[0], i)
  return {
    label: data.value!.granularity === 'hour' ? d.key : shortDate(d.key),
    tip: d.key,
    prevTip: prevKey,
    value: Number(d[metric.value]),
    prev: Number(d[`prev_${metric.value}` as const]),
  }
}))
function shiftDate(start: string, days: number) {
  const d = new Date(`${start}T00:00:00`)
  d.setDate(d.getDate() + days)
  return ymd(d.getTime())
}
const trendFormat = computed(() => (metric.value === 'revenue' ? money : (v: number) => num(v)))

// ---- Funnel ----
const funnelLabels: Record<string, string> = {
  sessions: t('访问'), product: t('浏览商品'), cart: t('加入购物车'), checkout: t('进入结账'), purchase: t('完成付款'),
}
const funnel = computed(() => (data.value?.funnel ?? []).map((s) => ({ label: funnelLabels[s.key] ?? s.key, value: s.sessions, prev: s.prev })))
const hasTraffic = computed(() => Number(data.value?.totals.sessions.value ?? 0) > 0)

// ---- Labels ----
const sourceLabels: Record<string, string> = {
  direct: t('直接访问'), search: t('自然搜索'), social: t('社交媒体'), email: t('邮件'), paid: t('付费广告'), referral: t('外部网站'),
}
const deviceLabels: Record<string, string> = { mobile: t('手机'), tablet: t('平板'), desktop: t('电脑') }
const share = (rows: { sessions: number }[], v: number) => {
  const max = Math.max(...rows.map((r) => r.sessions), 1)
  return `${(v / max) * 100}%`
}
const revenueShare = (v: number) => `${Math.min(100, v)}%`

// ---- Customers & hours ----
const customerMix = computed(() => {
  const c = data.value?.customers
  if (!c) return null
  const total = c.new.orders + c.returning.orders
  return { ...c, total, newPct: total ? (c.new.orders / total) * 100 : 0 }
})
const hours = computed(() => (data.value?.hours ?? []).map((v, h) => ({ label: `${h}`, value: v, sub: t('{0}:00 – {0}:59', [String(h).padStart(2, '0')]) })))
const hasOrders = computed(() => (data.value?.hours ?? []).some((v) => v > 0))

// ---- Customer retention (monthly cohorts; independent of the date range) ----
interface Retention {
  months: number
  summary: { customers: number; repeat_customers: number; repeat_rate: number; orders_per_customer: number; median_days_to_second: number | null }
  cohorts: { month: string; customers: number; retention: (number | null)[] }[]
}
const cohortMonths = ref(6)
const retention = ref<Retention | null>(null)
async function loadRetention() {
  retention.value = (await http.get('/analytics/retention', { params: { months: cohortMonths.value } })).data
}
watch(cohortMonths, loadRetention)
onMounted(loadRetention)
// One hue, light → dark by value; text flips to white on the darker cells.
const cellStyle = (v: number | null) => (v === null || v === 0 ? {} : {
  background: `color-mix(in srgb, var(--brand-600) ${Math.round(12 + Math.min(v, 100) * 0.78)}%, var(--surface))`,
  color: v >= 45 ? '#fff' : 'var(--ink)',
})

// ---- CSV export of everything on the page ----
function exportCsv() {
  const d = data.value
  if (!d) return
  const rows: (string | number | null)[][] = []
  const section = (title: string, head: string[], body: (string | number | null)[][]) => {
    rows.push([title], head, ...body, [])
  }
  section(t('数据分析'), [t('时间范围'), t('对比'), t('货币')], [[`${d.range[0]} ~ ${d.range[1]}`, `${d.previous[0]} ~ ${d.previous[1]}`, d.currency]])
  section(t('核心指标'), [t('指标'), t('数值'), t('变化 %')], tiles.value.map((x) => [x.label, x.value, x.change]))
  section(t('趋势'), [t('日期'), t('访问'), t('订单数'), t('销售额'), t('上期销售额')], d.series.map((s) => [s.key, s.sessions, s.orders, s.revenue, s.prev_revenue]))
  section(t('转化漏斗'), [t('步骤'), t('访问'), t('上期')], d.funnel.map((f) => [funnelLabels[f.key] ?? f.key, f.sessions, f.prev]))
  const rowTable = (title: string, list: Row[], label: (k: string | null) => string | null = (k) => k) =>
    section(title, [t('名称'), t('访问'), t('完成付款'), t('转化率'), t('销售额')], list.map((r) => [label(r.key), r.sessions, r.purchases, r.conversion, r.revenue]))
  rowTable(t('流量来源'), d.sources, (k) => sourceLabels[k ?? ''] ?? k)
  rowTable(t('设备'), d.devices, (k) => deviceLabels[k ?? ''] ?? k)
  rowTable(t('浏览器'), d.browsers)
  rowTable(t('热门着陆页'), d.landing_pages)
  rowTable(t('推广活动（UTM）'), d.campaigns)
  rowTable(t('来源网站'), d.referrers)
  section(t('地区分布'), [t('代码'), t('国家/地区'), t('访问'), t('订单数'), t('销售额'), t('销售额占比')], d.countries.map((c) => [c.code, c.name, c.sessions, c.orders, c.revenue, c.share]))
  section(t('商品分析'), [t('商品'), t('浏览'), t('加购'), t('加购率'), t('售出件数'), t('销售额'), t('转化率')], d.products.map((p) => [p.title, p.views, p.carts, p.cart_rate, p.qty, p.revenue, p.conversion]))
  section(t('下单时段'), [t('时段'), t('订单数')], d.hours.map((v, h) => [`${String(h).padStart(2, '0')}:00`, v]))
  const cell = (v: string | number | null) => {
    let s = v === null || v === undefined ? '' : String(v)
    if (/^[=+\-@]/.test(s) && isNaN(Number(s))) s = `'${s}` // keep spreadsheets from running it as a formula
    return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
  }
  const csv = '﻿' + rows.map((r) => r.map(cell).join(',')).join('\r\n')
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  const a = document.createElement('a')
  a.href = url
  a.download = `analytics-${d.range[0]}_${d.range[1]}.csv`
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Analytics</div>
        <h1 class="page-title">{{ t('数据分析') }}</h1>
        <p class="page-sub" v-if="data">
          {{ data.range[0] === data.range[1] ? data.range[0] : `${data.range[0]} – ${data.range[1]}` }}
          · {{ t('对比 {0}', [data.previous[0] === data.previous[1] ? data.previous[0] : `${data.previous[0]} – ${data.previous[1]}`]) }}
          · {{ t('时区 {0}', [data.timezone]) }}
        </p>
      </div>
      <div class="filters">
        <span v-if="data" class="live" :title="t('最近 5 分钟有活动的访问')"><i class="pulse" />{{ t('{0} 人在线', [data.live]) }}</span>
        <NSelect v-model:value="preset" :options="presets" size="small" style="width: 120px" />
        <NDatePicker v-if="preset === 'custom'" v-model:value="custom" type="daterange" size="small" clearable
          :is-date-disabled="(ts: number) => ts > Date.now()" style="width: 250px" />
        <NButton size="small" :disabled="!data" @click="exportCsv">{{ t('导出报表') }}</NButton>
      </div>
    </div>

    <NAlert v-if="data && !hasTraffic" type="info" :bordered="false">
      {{ t('访客、转化漏斗和流量来源从店铺前台被访问时开始统计（不含搜索引擎爬虫和你自己的主题预览）。销售数据不受影响。') }}
    </NAlert>

    <!-- KPIs -->
    <div class="tiles">
      <NCard v-for="it in tiles" :key="it.label" size="small" class="tile">
        <div class="tile-label">{{ it.label }}</div>
        <div class="tile-value">{{ it.value }}</div>
        <div class="tile-foot">
          <span v-if="it.change !== null" class="change" :class="it.change >= 0 ? 'up' : 'down'">
            <span aria-hidden="true">{{ it.change >= 0 ? '▲' : '▼' }}</span> {{ Math.abs(it.change) }}%
            <span class="sr">{{ it.change >= 0 ? t('上升') : t('下降') }}</span>
          </span>
          <span v-else class="muted">{{ t('无对比数据') }}</span>
          <span v-if="it.hint" class="muted">· {{ it.hint }}</span>
        </div>
      </NCard>
      <template v-if="loading && !data">
        <NCard v-for="n in 6" :key="n" size="small"><NSkeleton text :repeat="2" /></NCard>
      </template>
    </div>

    <!-- Trend -->
    <NCard size="small">
      <template #header>
        <NRadioGroup v-model:value="metric" size="small">
          <NRadioButton value="revenue">{{ t('销售额') }}</NRadioButton>
          <NRadioButton value="orders">{{ t('订单数') }}</NRadioButton>
          <NRadioButton value="sessions">{{ t('访问') }}</NRadioButton>
        </NRadioGroup>
      </template>
      <template #header-extra>
        <NButton size="tiny" quaternary @click="showTable = !showTable">{{ showTable ? t('查看图表') : t('查看表格') }}</NButton>
      </template>
      <NSkeleton v-if="loading && !data" height="260px" />
      <LineChart v-else-if="!showTable" :points="trend" :format="trendFormat" :integer="metric !== 'revenue'" compare />
      <div v-else class="table-wrap">
        <table class="tbl">
          <thead><tr>
            <th>{{ data?.granularity === 'hour' ? t('时段') : t('日期') }}</th>
            <th class="num">{{ t('访问') }}</th><th class="num">{{ t('订单数') }}</th><th class="num">{{ t('销售额') }}</th>
            <th class="num">{{ t('上期销售额') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="d in [...(data?.series ?? [])].reverse()" :key="d.key">
              <td>{{ d.key }}</td><td class="num">{{ num(d.sessions) }}</td><td class="num">{{ d.orders }}</td>
              <td class="num">{{ money(d.revenue) }}</td><td class="num muted">{{ money(d.prev_revenue) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </NCard>

    <!-- Funnel + sources -->
    <div class="two">
      <NCard size="small">
        <template #header>
          {{ t('转化漏斗') }}
          <NTooltip>
            <template #trigger><span class="info">?</span></template>
            {{ t('每一步是到达这一步的访问次数。完成付款 = 本次访问下的订单已付款。') }}
          </NTooltip>
        </template>
        <NEmpty v-if="!hasTraffic" :description="t('暂无访问数据')" size="small" />
        <FunnelChart v-else :steps="funnel" />
      </NCard>
      <NCard :title="t('流量来源')" size="small" content-style="padding: 0">
        <NEmpty v-if="!data?.sources.length" :description="t('暂无访问数据')" size="small" style="padding: 24px" />
        <table v-else class="tbl">
          <thead><tr><th>{{ t('来源') }}</th><th class="num">{{ t('访问') }}</th><th class="num">{{ t('转化率') }}</th><th class="num">{{ t('销售额') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in data.sources" :key="r.key ?? ''">
              <td>
                <div>{{ sourceLabels[r.key ?? ''] ?? r.key }}</div>
                <div class="cell-bar"><span :style="{ width: share(data.sources, r.sessions) }" /></div>
              </td>
              <td class="num">{{ num(r.sessions) }}</td><td class="num">{{ pct(r.conversion) }}</td><td class="num">{{ money(r.revenue) }}</td>
            </tr>
          </tbody>
        </table>
      </NCard>
    </div>

    <!-- Regions + devices -->
    <div class="two wide-left">
      <NCard :title="t('地区分布')" size="small" content-style="padding: 0">
        <NEmpty v-if="!data?.countries.length" :description="t('暂无数据')" size="small" style="padding: 24px" />
        <div v-else class="table-wrap">
          <table class="tbl">
            <thead><tr><th>{{ t('国家/地区') }}</th><th class="num">{{ t('访问') }}</th><th class="num">{{ t('订单数') }}</th><th class="num">{{ t('销售额') }}</th><th class="share-col">{{ t('销售额占比') }}</th></tr></thead>
            <tbody>
              <tr v-for="c in data.countries" :key="c.code ?? 'unknown'">
                <td><span class="code">{{ c.code ?? '—' }}</span>{{ c.name }}</td>
                <td class="num">{{ num(c.sessions) }}</td><td class="num">{{ c.orders }}</td><td class="num">{{ money(c.revenue) }}</td>
                <td class="share-col"><div class="share"><div class="cell-bar"><span :style="{ width: revenueShare(c.share) }" /></div><span class="muted">{{ c.share }}%</span></div></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="foot-note">{{ t('销售额按收货国家统计；访问按访客所在地区统计（有 CDN 地区信息时使用它，否则根据浏览器语言估算）。') }}</p>
      </NCard>
      <NCard :title="t('设备与浏览器')" size="small">
        <NEmpty v-if="!data?.devices.length" :description="t('暂无访问数据')" size="small" />
        <template v-else>
          <div v-for="d in data.devices" :key="d.key ?? ''" class="rank">
            <div class="rank-top"><span>{{ deviceLabels[d.key ?? ''] ?? d.key }}</span><span class="num">{{ num(d.sessions) }} · {{ pct(d.conversion) }}</span></div>
            <div class="cell-bar"><span :style="{ width: share(data.devices, d.sessions) }" /></div>
          </div>
          <div class="sub-title">{{ t('浏览器') }}</div>
          <div v-for="b in data.browsers" :key="b.key ?? ''" class="mini-row"><span>{{ b.key }}</span><span class="num muted">{{ num(b.sessions) }}</span></div>
        </template>
      </NCard>
    </div>

    <!-- Products -->
    <NCard :title="t('商品分析')" size="small" content-style="padding: 0">
      <NEmpty v-if="!data?.products.length" :description="t('暂无数据')" size="small" style="padding: 24px" />
      <div v-else class="table-wrap">
        <table class="tbl">
          <thead><tr>
            <th>{{ t('商品') }}</th><th class="num">{{ t('浏览') }}</th><th class="num">{{ t('加购') }}</th><th class="num">{{ t('加购率') }}</th>
            <th class="num">{{ t('售出件数') }}</th><th class="num">{{ t('销售额') }}</th><th class="num">{{ t('转化率') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="p in data.products" :key="p.id ?? p.title">
              <td class="title-cell">
                <RouterLink v-if="p.id" :to="{ name: 'product-edit', params: { id: p.id } }">{{ p.title }}</RouterLink>
                <span v-else>{{ p.title }}</span>
              </td>
              <td class="num">{{ num(p.views) }}</td><td class="num">{{ num(p.carts) }}</td><td class="num">{{ pct(p.cart_rate) }}</td>
              <td class="num">{{ num(p.qty) }}</td><td class="num">{{ money(p.revenue) }}</td><td class="num">{{ pct(p.conversion) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </NCard>

    <!-- Landing pages + campaigns/referrers -->
    <div class="two">
      <NCard :title="t('热门着陆页')" size="small" content-style="padding: 0">
        <NEmpty v-if="!data?.landing_pages.length" :description="t('暂无访问数据')" size="small" style="padding: 24px" />
        <table v-else class="tbl">
          <thead><tr><th>{{ t('页面') }}</th><th class="num">{{ t('访问') }}</th><th class="num">{{ t('转化率') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in data.landing_pages" :key="r.key ?? ''"><td class="path">{{ r.key }}</td><td class="num">{{ num(r.sessions) }}</td><td class="num">{{ pct(r.conversion) }}</td></tr>
          </tbody>
        </table>
      </NCard>
      <NCard size="small" content-style="padding: 0">
        <template #header>
          {{ t('推广活动与来源网站') }}
          <NTooltip>
            <template #trigger><span class="info">?</span></template>
            {{ t('推广活动来自链接里的 utm_campaign 参数；邮件营销的链接会自动加上。') }}
          </NTooltip>
        </template>
        <NEmpty v-if="!data?.campaigns.length && !data?.referrers.length" :description="t('暂无数据')" size="small" style="padding: 24px" />
        <table v-else class="tbl">
          <tbody>
            <tr v-if="data.campaigns.length" class="group"><td colspan="3">{{ t('推广活动（UTM）') }}</td></tr>
            <tr v-for="r in data.campaigns" :key="'c' + r.key"><td class="path">{{ r.key }}</td><td class="num">{{ num(r.sessions) }}</td><td class="num">{{ money(r.revenue) }}</td></tr>
            <tr v-if="data.referrers.length" class="group"><td colspan="3">{{ t('来源网站') }}</td></tr>
            <tr v-for="r in data.referrers" :key="'r' + r.key"><td class="path">{{ r.key }}</td><td class="num">{{ num(r.sessions) }}</td><td class="num">{{ money(r.revenue) }}</td></tr>
          </tbody>
        </table>
      </NCard>
    </div>

    <!-- Customers + order hours -->
    <div class="two wide-right">
      <NCard :title="t('新老客户')" size="small">
        <NEmpty v-if="!customerMix?.total" :description="t('这段时间还没有已付款订单')" size="small" />
        <template v-else>
          <div class="mix-bar" role="img" :aria-label="t('新客户订单占 {0}%', [customerMix.newPct.toFixed(0)])">
            <span class="new" :style="{ width: `${customerMix.newPct}%` }" />
          </div>
          <div class="mix-row">
            <span><i class="sw new" />{{ t('首次购买') }}</span>
            <span class="num">{{ t('{0} 笔', [customerMix.new.orders]) }} · {{ money(customerMix.new.revenue) }}</span>
          </div>
          <div class="mix-row">
            <span><i class="sw ret" />{{ t('回头客') }}</span>
            <span class="num">{{ t('{0} 笔', [customerMix.returning.orders]) }} · {{ money(customerMix.returning.revenue) }}</span>
          </div>
          <div class="muted mix-note">{{ t('复购率 {0}', [pct(customerMix.total ? (customerMix.returning.orders / customerMix.total) * 100 : 0)]) }}
            · {{ t('{0} 位回访访客', [num(data!.returning_visitors)]) }}</div>
        </template>
      </NCard>
      <NCard :title="t('下单时段')" size="small">
        <NEmpty v-if="!hasOrders" :description="t('暂无数据')" size="small" />
        <ColumnChart v-else :points="hours" :format="(v: number) => t('{0} 笔订单', [v])" :height="180" integer />
      </NCard>
    </div>

    <!-- Retention cohorts -->
    <NCard size="small" content-style="padding: 0">
      <template #header>
        {{ t('客户留存') }}
        <NTooltip>
          <template #trigger><span class="info">?</span></template>
          {{ t('按首次付款的月份把客户分组，看之后每个月有多少比例再次购买。按订单邮箱识别客户，包含游客下单。不受上方时间范围影响。') }}
        </NTooltip>
      </template>
      <template #header-extra>
        <NRadioGroup v-model:value="cohortMonths" size="small">
          <NRadioButton :value="6">{{ t('近 6 个月') }}</NRadioButton>
          <NRadioButton :value="12">{{ t('近 12 个月') }}</NRadioButton>
        </NRadioGroup>
      </template>
      <NSkeleton v-if="!retention" height="200px" />
      <template v-else>
        <div class="ret-stats">
          <div><div class="muted">{{ t('购买客户') }}</div><div class="ret-val">{{ num(retention.summary.customers) }}</div></div>
          <div><div class="muted">{{ t('复购客户占比') }}</div><div class="ret-val">{{ pct(retention.summary.repeat_rate) }}</div></div>
          <div><div class="muted">{{ t('人均订单') }}</div><div class="ret-val">{{ retention.summary.orders_per_customer }}</div></div>
          <div><div class="muted">{{ t('首单到第二单（中位数）') }}</div><div class="ret-val">{{ retention.summary.median_days_to_second === null ? '—' : t('{0} 天', [retention.summary.median_days_to_second]) }}</div></div>
        </div>
        <NEmpty v-if="!retention.cohorts.some((c) => c.customers)" :description="t('这段时间还没有已付款订单')" size="small" style="padding: 24px" />
        <div v-else class="table-wrap">
          <table class="tbl cohort">
            <thead><tr>
              <th>{{ t('首单月份') }}</th><th class="num">{{ t('客户') }}</th>
              <th v-for="m in retention.months" :key="m" class="num">{{ m === 1 ? t('当月') : t('第 {0} 月', [m - 1]) }}</th>
            </tr></thead>
            <tbody>
              <tr v-for="c in retention.cohorts" :key="c.month">
                <td>{{ c.month }}</td><td class="num">{{ num(c.customers) }}</td>
                <td v-for="m in retention.months" :key="m" class="num heat" :style="cellStyle(c.retention[m - 1] ?? null)">
                  {{ c.retention[m - 1] === undefined ? '' : pct(c.retention[m - 1]) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </NCard>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; container-type: inline-size; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.head .page-sub { margin-bottom: 0; }
.filters { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.live { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ink-muted); margin-right: 4px; }
.pulse { width: 8px; height: 8px; border-radius: 50%; background: var(--brand-500); box-shadow: 0 0 0 3px var(--brand-100); }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; }
.tile-label { font-size: 13px; color: var(--ink-muted); }
.tile-value { font-family: var(--font-display); font-size: 24px; font-weight: 700; margin: 6px 0; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tile-foot { font-size: 12px; display: flex; gap: 4px; flex-wrap: wrap; }
.change.up { color: var(--brand-700); }
.change.down { color: var(--danger); }
.muted { color: var(--ink-muted); font-size: 12px; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }
.two.wide-left { grid-template-columns: 1.6fr 1fr; }
.two.wide-right { grid-template-columns: 1fr 1.6fr; }
@container (max-width: 900px) { .two, .two.wide-left, .two.wide-right { grid-template-columns: 1fr; } }
.info {
  display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; margin-left: 6px;
  border-radius: 50%; border: 1px solid var(--border); font-size: 11px; color: var(--ink-muted); cursor: help; vertical-align: 1px;
}
.table-wrap { overflow-x: auto; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { text-align: left; font-weight: 500; color: var(--ink-muted); font-size: 12px; padding: 10px 16px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.tbl td { padding: 10px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.tbl tbody tr:last-child td { border-bottom: 0; }
.tbl tr.group td { background: var(--surface-muted); font-size: 12px; color: var(--ink-muted); padding: 6px 16px; }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.tbl th.num { text-align: right; }
.cell-bar { height: 4px; background: var(--surface-muted); border-radius: 2px; margin-top: 6px; overflow: hidden; }
.cell-bar span { display: block; height: 100%; background: var(--brand-600); border-radius: 2px; }
.share-col { width: 140px; }
.share { display: flex; align-items: center; gap: 8px; }
.share .cell-bar { flex: 1; margin: 0; }
.code { display: inline-block; width: 28px; font-size: 11px; color: var(--ink-muted); font-family: ui-monospace, monospace; }
.path { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.title-cell { max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.title-cell a { color: var(--ink); text-decoration: none; }
.title-cell a:hover { color: var(--brand-600); }
.foot-note { margin: 0; padding: 10px 16px; font-size: 12px; color: var(--ink-muted); border-top: 1px solid var(--border); }
.rank { padding: 6px 0 10px; }
.rank-top { display: flex; justify-content: space-between; font-size: 13px; }
.sub-title { font-size: 12px; color: var(--ink-muted); margin: 12px 0 4px; padding-top: 12px; border-top: 1px solid var(--border); }
.mini-row { display: flex; justify-content: space-between; font-size: 13px; padding: 4px 0; }
.mix-bar { height: 12px; border-radius: 4px; background: var(--brand-300); overflow: hidden; display: flex; margin-bottom: 14px; }
.mix-bar .new { display: block; height: 100%; background: var(--brand-700); border-right: 2px solid var(--surface); }
.mix-row { display: flex; justify-content: space-between; font-size: 13px; padding: 4px 0; }
.mix-row span:first-child { display: inline-flex; align-items: center; gap: 8px; }
.sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; }
.sw.new { background: var(--brand-700); }
.sw.ret { background: var(--brand-300); }
.mix-note { margin-top: 10px; }
.ret-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--border); }
.ret-val { font-size: 18px; font-weight: 600; margin-top: 2px; font-variant-numeric: tabular-nums; }
.cohort td.heat { padding: 8px 10px; border: 2px solid var(--surface); border-radius: 4px; font-size: 12px; min-width: 64px; }
.cohort th { padding: 10px; }
</style>
