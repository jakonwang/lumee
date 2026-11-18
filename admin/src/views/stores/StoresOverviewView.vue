<script setup lang="ts">
import { h, onMounted, ref, watch } from 'vue'
import { NCard, NGrid, NGi, NButton, NDataTable, NTag, NSelect, NSpace, NSkeleton, NAlert, type DataTableColumns } from 'naive-ui'
import CreateStoreModal from '@/components/CreateStoreModal.vue'
import { STORE_CURRENCIES } from '@/constants/currencies'
import { http } from '@/api/http'
import { useAuth, type StoreBilling } from '@/stores/auth'
import { t } from '@/i18n'

interface Row {
  id: number; name: string; handle: string; currency: string; url: string; role: string; billing: StoreBilling
  revenue: string; orders: number; aov: string; today: string
}
interface RecentOrder {
  id: number; store_id: number; store: string; name: string; email: string; total: string; currency: string
  financial_status: string; fulfillment_status: string; created_at: string
}
interface Payload {
  stores: Row[]
  totals: { currency: string; revenue: string; orders: number; missing_rates: string[] }
  recent_orders: RecentOrder[]
  can_create: boolean
}

const auth = useAuth()
const data = ref<Payload | null>(null)
const days = ref(30)
const currency = ref(auth.currentStore?.currency ?? 'USD')
const createOpen = ref(false)

async function load() {
  const res = await http.get('/organization/overview', { params: { days: days.value, currency: currency.value } })
  data.value = res.data
}
watch([days, currency], load)
onMounted(load)

function open(storeId: number, path = '/') {
  if (storeId !== auth.currentStore?.id) auth.switchStore(storeId)
  window.location.href = path
}

const billingLabel: Record<string, { label: string; type: 'success' | 'info' | 'warning' | 'error' | 'default' }> = {
  trialing: { label: t('试用中'), type: 'info' },
  active: { label: t('营业中'), type: 'success' },
  past_due: { label: t('扣款失败'), type: 'warning' },
  expired: { label: t('已暂停'), type: 'warning' },
  suspended: { label: t('已被平台暂停'), type: 'error' },
}

const columns: DataTableColumns<Row> = [
  {
    title: t('店铺'), key: 'name', minWidth: 220,
    render: (r) => h('div', { class: 'scell' }, [
      h('span', { class: 'mark' }, r.name.slice(0, 1).toUpperCase()),
      h('div', [
        h('div', { style: 'font-weight:500' }, [r.name, r.id === auth.currentStore?.id ? h('span', { class: 'here' }, t('当前')) : null]),
        h('a', { href: r.url, target: '_blank', class: 'url', onClick: (e: Event) => e.stopPropagation() }, r.url.replace(/^https?:\/\//, '')),
      ]),
    ]),
  },
  {
    title: t('状态'), key: 'billing', width: 120,
    render: (r) => {
      const b = billingLabel[r.billing.state] ?? { label: r.billing.state, type: 'default' as const }
      return h(NTag, { type: b.type, size: 'small', bordered: false }, { default: () => b.label })
    },
  },
  { title: t('今日销售额'), key: 'today', width: 130, align: 'right', render: (r) => `${r.currency} ${r.today}` },
  { title: t('销售额'), key: 'revenue', width: 140, align: 'right', render: (r) => `${r.currency} ${r.revenue}` },
  { title: t('订单'), key: 'orders', width: 80, align: 'right' },
  { title: t('客单价'), key: 'aov', width: 120, align: 'right', render: (r) => `${r.currency} ${r.aov}` },
  {
    title: '', key: 'go', width: 90, align: 'right',
    render: (r) => h(NButton, { size: 'small', quaternary: r.id === auth.currentStore?.id, onClick: () => open(r.id) },
      { default: () => t('进入') }),
  },
]

const fmtDate = (s: string) => new Date(s).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <div>
    <div class="head">
      <div>
        <div class="eyebrow">Organization</div>
        <h1 class="page-title">{{ t('所有店铺') }}</h1>
        <p class="page-sub">{{ t('在一个页面查看你所有店铺的经营情况') }}</p>
      </div>
      <NSpace>
        <NSelect v-model:value="days" size="small" style="width: 120px"
          :options="[7, 30, 90].map((d) => ({ label: t('最近 {0} 天', [d]), value: d }))" />
        <NSelect v-model:value="currency" size="small" style="width: 96px" filterable
          :options="STORE_CURRENCIES.map((c) => ({ label: c, value: c }))" />
        <NButton type="primary" size="small" :disabled="data ? !data.can_create : false" @click="createOpen = true">{{ t('新建店铺') }}</NButton>
      </NSpace>
    </div>

    <NGrid cols="1 700:3" :x-gap="16" :y-gap="16" responsive="self" style="margin-bottom: 16px">
      <NGi>
        <NCard class="stat">
          <div class="stat-label">{{ t('总销售额（{0}）', [currency]) }}</div>
          <NSkeleton v-if="!data" text style="width: 60%" />
          <div v-else class="stat-value">{{ data.totals.revenue }}</div>
        </NCard>
      </NGi>
      <NGi>
        <NCard class="stat">
          <div class="stat-label">{{ t('总订单') }}</div>
          <NSkeleton v-if="!data" text style="width: 40%" />
          <div v-else class="stat-value">{{ data.totals.orders }}</div>
        </NCard>
      </NGi>
      <NGi>
        <NCard class="stat">
          <div class="stat-label">{{ t('店铺数量') }}</div>
          <NSkeleton v-if="!data" text style="width: 30%" />
          <div v-else class="stat-value">{{ data.stores.length }}</div>
        </NCard>
      </NGi>
    </NGrid>

    <NAlert v-if="data?.totals.missing_rates.length" type="warning" :bordered="false" style="margin-bottom: 16px">
      {{ t('暂时没有 {0} 的汇率，这些店铺的销售额没有计入总额。', [data.totals.missing_rates.join(', ')]) }}
    </NAlert>
    <p v-if="!data?.can_create" class="note">{{ t('每个账号最多拥有 10 家店铺。') }}</p>

    <NCard :title="t('店铺')" content-style="padding: 0" style="margin-bottom: 16px">
      <NDataTable :columns="columns" :data="data?.stores ?? []" :loading="!data" :bordered="false" :scroll-x="820" />
    </NCard>

    <NCard :title="t('最近订单')" content-style="padding: 0">
      <div v-if="data && !data.recent_orders.length" class="empty">{{ t('还没有订单') }}</div>
      <div v-for="o in data?.recent_orders ?? []" :key="o.id" class="order" @click="open(o.store_id, `/orders/${o.id}`)">
        <div>
          <div class="o-name">{{ o.name }} <span class="o-store">{{ o.store }}</span></div>
          <div class="o-sub">{{ o.email }} · {{ fmtDate(o.created_at) }}</div>
        </div>
        <div class="o-total">{{ o.currency }} {{ o.total }}</div>
      </div>
    </NCard>

    <CreateStoreModal v-model:show="createOpen" />
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.stat-label { font-size: 13px; color: var(--ink-muted); }
.stat-value { font-size: 26px; font-weight: 600; margin-top: 6px; color: var(--ink); font-variant-numeric: tabular-nums; }
.note { color: var(--ink-muted); font-size: 13px; margin: -4px 0 16px; }
:deep(.scell) { display: flex; align-items: center; gap: 12px; }
:deep(.mark) {
  width: 32px; height: 32px; border-radius: 8px; flex: none; display: flex; align-items: center; justify-content: center;
  background: #E8F5EF; color: #0E8A5F; font-weight: 600; font-size: 14px;
}
:deep(.here) { margin-left: 8px; font-size: 11px; color: var(--ink-muted); font-weight: 400; }
:deep(.url) { font-size: 12px; color: var(--ink-muted); text-decoration: none; }
:deep(.url:hover) { color: #0E8A5F; }
.order { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; border-top: 1px solid var(--border); cursor: pointer; }
.order:first-child { border-top: 0; }
.order:hover { background: #F7F9F8; }
.o-name { font-weight: 500; }
.o-store { margin-left: 8px; font-size: 12px; color: var(--ink-muted); font-weight: 400; }
.o-sub { font-size: 12px; color: var(--ink-muted); margin-top: 2px; }
.o-total { font-variant-numeric: tabular-nums; }
.empty { padding: 24px; text-align: center; color: var(--ink-muted); }
</style>
