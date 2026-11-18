<script setup lang="ts">
/**
 * Home: what happened in the chosen period (KPIs with trend + change, sales trend, conversion funnel,
 * sources, regions, best sellers) and what needs doing now (to-dos, latest orders).
 * Members only see blocks for areas they can access; period numbers come from /analytics.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { NCard, NButton, NSkeleton, NAlert, NRadioGroup, NRadioButton, NEmpty, NTag } from 'naive-ui'
import { useRoute, useRouter } from 'vue-router'
import { http } from '@/api/http'
import { financialLabel, fulfillmentLabel, formatDate } from '@/api/orders'
import { useAuth } from '@/stores/auth'
import LineChart from '@/components/charts/LineChart.vue'
import FunnelChart from '@/components/charts/FunnelChart.vue'
import Sparkline from '@/components/charts/Sparkline.vue'
import { t } from '@/i18n'

interface Todo { key: string; count: number; route: string }
interface RecentOrder {
  id: number; number: number; customer: string | null; total: string; currency: string
  financial_status: keyof typeof financialLabel; fulfillment_status: keyof typeof fulfillmentLabel; cancelled: boolean; created_at: string
}
interface Stats {
  currency: string
  products?: number
  orders?: number
  customers?: number
  revenue?: string
  to_fulfill?: number
  awaiting_payment?: number
  todos: Todo[]
  recent_orders?: RecentOrder[]
}
interface Stat { value: string | number; change: number | null }
interface Row { key: string | null; sessions: number; purchases: number; revenue: string; conversion: number }
interface Analytics {
  currency: string
  granularity: 'day' | 'hour'
  previous: [string, string]
  totals: Record<'revenue' | 'orders' | 'aov' | 'visitors' | 'sessions' | 'conversion' | 'customers', Stat>
  series: { key: string; revenue: string; orders: number; sessions: number; prev_revenue: string; prev_orders: number; prev_sessions: number }[]
  funnel: { key: string; sessions: number; prev: number }[]
  sources: Row[]
  countries: { code: string | null; name: string; sessions: number; orders: number; revenue: string; share: number }[]
  products: { id: number | null; title: string; qty: number; revenue: string; views: number }[]
  live: number
}

const auth = useAuth()
const router = useRouter()
const route = useRoute()
const canAnalytics = computed(() => auth.can('analytics'))

const stats = ref<Stats | null>(null)
const data = ref<Analytics | null>(null)
const days = ref(7)

async function loadAnalytics() {
  if (!canAnalytics.value) return
  data.value = (await http.get('/analytics', { params: { days: days.value } })).data
}
watch(days, loadAnalytics)
onMounted(async () => {
  loadAnalytics()
  stats.value = (await http.get('/dashboard')).data
})
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  timer = setInterval(async () => {
    if (document.hidden || !data.value) return
    data.value.live = (await http.get('/analytics', { params: { days: days.value } })).data.live
  }, 60000)
})
onBeforeUnmount(() => clearInterval(timer))

const fmtMoney = (v: number | string) => Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const money = (v: number | string, cur?: string) => `${cur ?? data.value?.currency ?? stats.value?.currency ?? ''} ${fmtMoney(v)}`
const num = (v: number) => v.toLocaleString()
const pct = (v: number) => `${v.toFixed(v >= 10 ? 1 : 2)}%`

// ---- KPI tiles (value, change vs previous period, small trend) ----
const tiles = computed(() => {
  const d = data.value
  if (!d) return []
  const it = d.totals
  const s = d.series
  return [
    { key: 'revenue', label: t('销售额'), value: money(it.revenue.value), change: it.revenue.change, spark: s.map((x) => Number(x.revenue)) },
    { key: 'orders', label: t('订单数'), value: num(Number(it.orders.value)), change: it.orders.change, spark: s.map((x) => x.orders) },
    { key: 'visitors', label: t('访客数'), value: num(Number(it.visitors.value)), change: it.visitors.change, spark: s.map((x) => x.sessions) },
    { key: 'conversion', label: t('转化率'), value: pct(Number(it.conversion.value)), change: it.conversion.change, spark: null },
    { key: 'aov', label: t('客单价'), value: money(it.aov.value), change: it.aov.change, spark: null },
  ]
})
const compareLabel = computed(() => (days.value === 1 ? t('较昨天') : t('较前 {0} 天', [days.value])))

// ---- Sales trend ----
const metric = ref<'revenue' | 'sessions'>('revenue')
const trend = computed(() => (data.value?.series ?? []).map((d) => ({
  label: data.value!.granularity === 'hour' ? d.key.slice(0, 2) : d.key.slice(5).replace('-', '/'),
  tip: d.key,
  value: Number(d[metric.value]),
  prev: Number(d[`prev_${metric.value}` as const]),
})))

// ---- Funnel & breakdowns ----
const funnelLabels: Record<string, string> = {
  sessions: t('访问'), product: t('浏览商品'), cart: t('加入购物车'), checkout: t('进入结账'), purchase: t('完成付款'),
}
const funnel = computed(() => (data.value?.funnel ?? []).map((s) => ({ label: funnelLabels[s.key] ?? s.key, value: s.sessions, prev: s.prev })))
const hasTraffic = computed(() => Number(data.value?.totals.sessions.value ?? 0) > 0)
const sourceLabels: Record<string, string> = {
  direct: t('直接访问'), search: t('自然搜索'), social: t('社交媒体'), email: t('邮件'), paid: t('付费广告'), referral: t('外部网站'),
}
const sources = computed(() => (data.value?.sources ?? []).slice(0, 5))
const totalSessions = computed(() => Math.max(1, Number(data.value?.totals.sessions.value ?? 0)))
const countries = computed(() => (data.value?.countries ?? []).slice(0, 5))
const topProducts = computed(() => (data.value?.products ?? []).filter((p) => p.qty > 0).slice(0, 5))

// ---- To-dos ----
const todoText: Record<string, string> = {
  to_fulfill: t('个订单待发货'),
  awaiting_payment: t('个订单待付款'),
  disputes: t('笔拒付待处理'),
  abandoned: t('个弃单可挽回（近 7 天）'),
  low_stock: t('个商品库存不足'),
  pending_reviews: t('条评论待审核'),
  overdue_invoices: t('笔账期订单已逾期'),
  quote_requests: t('张询价单待报价'),
  b2b_applications: t('个批发申请待审核'),
}
const todos = computed(() => (stats.value?.todos ?? []).filter((x) => x.count > 0))

// ---- Fallback tiles for members without analytics access ----
const countDefs = [
  { key: 'orders', label: t('订单'), perm: 'orders' },
  { key: 'products', label: t('商品'), perm: 'products' },
  { key: 'customers', label: t('客户'), perm: 'customers' },
] as const
const counts = computed(() => countDefs.filter((it) => auth.can(it.perm)))

// ---- Setup guide: only until the first order comes in ----
const showGuide = computed(() => stats.value && (stats.value.orders === 0 || stats.value.products === 0))
const steps = computed(() => [
  { title: t('添加第一件商品'), desc: t('上传图片、设置价格和库存'), to: 'products', perm: 'products' },
  { title: t('装修店铺'), desc: t('选择主题，设置 Logo 与品牌色'), to: 'online-store', perm: 'online_store' },
  { title: t('设置收款'), desc: t('接入 Stripe 与 PayPal'), to: 'settings-general', perm: 'settings' },
  { title: t('绑定域名'), desc: t('使用你自己的品牌域名'), to: 'settings-domains', perm: 'settings' },
].filter((s) => auth.can(s.perm)))
</script>

<template>
  <div class="home">
    <div class="head">
      <div>
        <div class="eyebrow">Overview</div>
        <h1 class="page-title">{{ t('你好，{0}', [auth.user?.name]) }}</h1>
        <p class="page-sub">{{ t('这是 {0} 的经营概况', [auth.currentStore?.name]) }}</p>
      </div>
      <div v-if="canAnalytics" class="filters">
        <span v-if="data" class="live" :title="t('最近 5 分钟有活动的访问')"><i class="pulse" />{{ t('{0} 人在线', [data.live]) }}</span>
        <NRadioGroup v-model:value="days" size="small">
          <NRadioButton :value="1">{{ t('今天') }}</NRadioButton>
          <NRadioButton :value="7">{{ t('近 7 天') }}</NRadioButton>
          <NRadioButton :value="30">{{ t('近 30 天') }}</NRadioButton>
        </NRadioGroup>
        <NButton size="small" quaternary type="primary" @click="router.push({ name: 'analytics' })">{{ t('完整报表 ›') }}</NButton>
      </div>
    </div>

    <NAlert v-if="route.query.denied" type="warning" :bordered="false" closable>
      {{ t('你没有访问那个页面的权限。如需开通，请联系店铺所有者。') }}
    </NAlert>

    <!-- KPIs -->
    <div v-if="canAnalytics" class="tiles">
      <template v-if="!data"><NCard v-for="n in 5" :key="n" size="small"><NSkeleton text :repeat="3" /></NCard></template>
      <NCard v-for="it in tiles" :key="it.key" size="small" class="tile">
        <div class="tile-label">{{ it.label }}</div>
        <div class="tile-value">{{ it.value }}</div>
        <div class="tile-foot">
          <span v-if="it.change !== null" class="change" :class="it.change >= 0 ? 'up' : 'down'">
            <span aria-hidden="true">{{ it.change >= 0 ? '▲' : '▼' }}</span> {{ Math.abs(it.change) }}%
          </span>
          <span v-else class="muted">—</span>
          <span class="muted">{{ compareLabel }}</span>
        </div>
        <Sparkline v-if="it.spark" :values="it.spark" class="spark" />
      </NCard>
    </div>
    <div v-else-if="counts.length" class="tiles">
      <NCard v-for="it in counts" :key="it.key" size="small" class="tile">
        <div class="tile-label">{{ it.label }}</div>
        <NSkeleton v-if="!stats" text style="width: 60%" />
        <div v-else class="tile-value">{{ stats[it.key] ?? '—' }}</div>
      </NCard>
    </div>

    <!-- Trend + funnel -->
    <div v-if="canAnalytics" class="row wide-left">
      <NCard size="small">
        <template #header>
          <NRadioGroup v-model:value="metric" size="small">
            <NRadioButton value="revenue">{{ t('销售额') }}</NRadioButton>
            <NRadioButton value="sessions">{{ t('访问') }}</NRadioButton>
          </NRadioGroup>
        </template>
        <NSkeleton v-if="!data" height="240px" />
        <LineChart v-else :points="trend" :format="metric === 'revenue' ? (v: number) => money(v) : num" :integer="metric !== 'revenue'" :height="240" compare />
      </NCard>
      <NCard :title="t('转化漏斗')" size="small">
        <NSkeleton v-if="!data" height="240px" />
        <NEmpty v-else-if="!hasTraffic" :description="t('暂无访问数据')" size="small" class="empty" />
        <FunnelChart v-else :steps="funnel" />
      </NCard>
    </div>

    <!-- Sources · regions · best sellers -->
    <div v-if="canAnalytics" class="row three">
      <NCard :title="t('流量来源')" size="small">
        <NEmpty v-if="!sources.length" :description="t('暂无访问数据')" size="small" class="empty" />
        <div v-for="s in sources" :key="s.key ?? ''" class="rank">
          <div class="rank-top"><span>{{ sourceLabels[s.key ?? ''] ?? s.key }}</span><span class="num">{{ num(s.sessions) }} <span class="muted">· {{ pct(s.conversion) }}</span></span></div>
          <div class="bar"><span :style="{ width: `${(s.sessions / totalSessions) * 100}%` }" /></div>
        </div>
      </NCard>
      <NCard :title="t('地区分布')" size="small">
        <NEmpty v-if="!countries.length" :description="t('暂无数据')" size="small" class="empty" />
        <div v-for="c in countries" :key="c.code ?? 'x'" class="rank">
          <div class="rank-top"><span><span class="code">{{ c.code ?? '—' }}</span>{{ c.name }}</span><span class="num">{{ money(c.revenue) }}</span></div>
          <div class="bar"><span :style="{ width: `${Math.min(100, c.share)}%` }" /></div>
        </div>
      </NCard>
      <NCard :title="t('热销商品')" size="small">
        <NEmpty v-if="!topProducts.length" :description="t('这段时间还没有已付款订单')" size="small" class="empty" />
        <div v-for="(p, i) in topProducts" :key="p.id ?? p.title" class="product">
          <span class="pos">{{ i + 1 }}</span>
          <RouterLink v-if="p.id" :to="{ name: 'product-edit', params: { id: p.id } }" class="ptitle">{{ p.title }}</RouterLink>
          <span v-else class="ptitle">{{ p.title }}</span>
          <span class="num"><span class="muted">{{ t('{0} 件', [p.qty]) }} · </span>{{ money(p.revenue) }}</span>
        </div>
      </NCard>
    </div>

    <!-- Latest orders + to-dos -->
    <div class="row wide-left" v-if="stats?.recent_orders || stats?.todos.length">
      <NCard v-if="stats?.recent_orders" :title="t('最近订单')" size="small" content-style="padding: 0">
        <template #header-extra><NButton size="tiny" quaternary @click="router.push({ name: 'orders' })">{{ t('全部订单 ›') }}</NButton></template>
        <NEmpty v-if="!stats.recent_orders.length" :description="t('还没有订单')" size="small" class="empty" />
        <table v-else class="tbl">
          <tbody>
            <tr v-for="o in stats.recent_orders" :key="o.id" @click="router.push({ name: 'order', params: { id: o.id } })">
              <td><div class="onum">#{{ o.number }}</div><div class="muted">{{ formatDate(o.created_at) }}</div></td>
              <td class="cust">{{ o.customer ?? '—' }}</td>
              <td>
                <NTag v-if="o.cancelled" size="small" :bordered="false">{{ t('已取消') }}</NTag>
                <template v-else>
                  <NTag size="small" :bordered="false" :type="financialLabel[o.financial_status]?.type">{{ financialLabel[o.financial_status]?.label ?? o.financial_status }}</NTag>
                  <NTag size="small" :bordered="false" :type="fulfillmentLabel[o.fulfillment_status]?.type" style="margin-left: 4px">{{ fulfillmentLabel[o.fulfillment_status]?.label ?? o.fulfillment_status }}</NTag>
                </template>
              </td>
              <td class="num">{{ money(o.total, o.currency) }}</td>
            </tr>
          </tbody>
        </table>
      </NCard>
      <NCard :title="t('待办事项')" size="small" content-style="padding: 4px 0">
        <div v-if="!stats" style="padding: 12px 16px"><NSkeleton text :repeat="3" /></div>
        <div v-else-if="!todos.length" class="done">✓ {{ t('都处理完了，没有待办事项') }}</div>
        <a v-for="x in todos" :key="x.key" class="todo" @click="router.push({ name: x.route })">
          <span class="todo-num">{{ num(x.count) }}</span>
          <span class="todo-text">{{ todoText[x.key] ?? x.key }}</span>
          <span class="chev" aria-hidden="true">›</span>
        </a>
      </NCard>
    </div>

    <!-- Setup guide -->
    <template v-if="showGuide && steps.length">
      <h2 class="section">{{ t('开店指引') }}</h2>
      <div class="guide">
        <NCard v-for="(s, i) in steps" :key="s.title" size="small" class="step">
          <div class="step-num">0{{ i + 1 }}</div>
          <div class="step-body">
            <div class="step-title">{{ s.title }}</div>
            <div class="step-desc">{{ s.desc }}</div>
          </div>
          <NButton secondary type="primary" size="small" @click="router.push({ name: s.to })">{{ t('开始') }}</NButton>
        </NCard>
      </div>
    </template>
  </div>
</template>

<style scoped>
.home { display: grid; gap: 16px; container-type: inline-size; }
.head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
.head .page-sub { margin-bottom: 0; }
.filters { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.live { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ink-muted); margin-right: 4px; }
.pulse { width: 8px; height: 8px; border-radius: 50%; background: var(--brand-500); box-shadow: 0 0 0 3px var(--brand-100); }

.tiles { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 16px; }
.tiles:has(> :nth-child(3):last-child) { grid-template-columns: repeat(3, minmax(0, 1fr)); }
/* Narrower: sales + orders on the first row, the other three below */
@container (max-width: 1100px) {
  .tiles { grid-template-columns: repeat(6, minmax(0, 1fr)); }
  .tiles > * { grid-column: span 2; }
  .tiles > :nth-child(-n+2) { grid-column: span 3; }
}
@container (max-width: 640px) {
  .tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .tiles > *, .tiles > :nth-child(-n+2) { grid-column: span 1; }
  .tiles > :first-child { grid-column: 1 / -1; }
}
.tile { position: relative; }
.tile-label { font-size: 13px; color: var(--ink-muted); }
.tile-value { font-family: var(--font-display); font-size: 26px; font-weight: 700; margin: 6px 0 4px; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tile-foot { font-size: 12px; display: flex; gap: 6px; flex-wrap: wrap; }
.change.up { color: var(--brand-700); }
.change.down { color: var(--danger); }
.spark { margin-top: 10px; }
.muted { color: var(--ink-muted); font-size: 12px; }

.row { display: grid; gap: 16px; align-items: start; }
.row.wide-left { grid-template-columns: 1.6fr 1fr; }
.row.three { grid-template-columns: repeat(3, 1fr); }
@container (max-width: 960px) { .row.wide-left, .row.three { grid-template-columns: 1fr; } }
.empty { padding: 24px 0; }

.rank { padding: 6px 0 8px; }
.rank-top { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; }
.bar { height: 4px; background: var(--surface-muted); border-radius: 2px; margin-top: 6px; overflow: hidden; }
.bar span { display: block; height: 100%; background: var(--brand-600); border-radius: 2px; }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.code { display: inline-block; width: 26px; font-size: 11px; color: var(--ink-muted); font-family: ui-monospace, monospace; }

.product { display: flex; align-items: center; gap: 10px; padding: 8px 0; font-size: 13px; border-bottom: 1px solid var(--border); }
.product:last-child { border-bottom: 0; }
.pos { width: 20px; height: 20px; border-radius: 50%; background: var(--brand-50); color: var(--brand-700); font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; flex: none; }
.ptitle { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--ink); text-decoration: none; }
a.ptitle:hover { color: var(--brand-600); }

.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl td { padding: 10px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.tbl tr:last-child td { border-bottom: 0; }
.tbl tr { cursor: pointer; }
.tbl tr:hover td { background: var(--surface-muted); }
.onum { font-weight: 600; }
.cust { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.todo { display: flex; align-items: center; gap: 12px; padding: 12px 16px; cursor: pointer; font-size: 14px; color: var(--ink); border-bottom: 1px solid var(--border); }
.todo:last-child { border-bottom: 0; }
.todo:hover { background: var(--brand-50); }
.todo-num { min-width: 32px; padding: 2px 8px; border-radius: 999px; background: var(--brand-100); color: var(--brand-800); font-weight: 600; font-size: 13px; text-align: center; font-variant-numeric: tabular-nums; }
.todo-text { flex: 1; }
.chev { color: var(--ink-muted); }
.done { padding: 20px 16px; color: var(--brand-700); font-size: 13px; }

.section { font-family: var(--font-display); font-size: 20px; margin: 16px 0 0; }
.guide { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
.step :deep(.n-card__content) { display: flex; align-items: center; gap: 16px; }
.step-num { font-family: var(--font-display); font-size: 24px; color: var(--brand-300); }
.step-body { flex: 1; }
.step-title { font-weight: 600; font-size: 15px; }
.step-desc { color: var(--ink-muted); font-size: 13px; margin-top: 2px; }
</style>
