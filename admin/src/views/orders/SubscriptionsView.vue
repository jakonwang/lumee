<script setup lang="ts">
/** Orders › Subscriptions: subscribe-and-save customers, renewals, and actions (skip, pause, renew now, cancel). */
import { h, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NAlert, NButton, NCard, NDataTable, NDrawer, NDrawerContent, NInput, NTabs, NTab, NTag, useDialog, useMessage, type DataTableColumns } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

interface Sub {
  id: number; email: string; status: 'active' | 'paused' | 'past_due' | 'cancelled'; interval_label: string; discount: number
  items_summary: string; value: string; currency: string; payment_gateway: string; next_billing_at: string | null; orders_count: number; created_at: string
  last_error?: string | null; failed_attempts?: number
  items?: { title: string; variant_title: string | null; quantity: number }[]
  orders?: { id: number; name: string; total: string; currency: string; financial_status: string; created_at: string }[]
}

const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const currency = useAuth().currentStore?.currency ?? ''
const rows = ref<Sub[]>([])
const counts = ref<Record<string, number>>({})
const monthly = ref('0.00')
const status = ref('active')
const q = ref('')
const loading = ref(false)
const pagination = ref({ page: 1, pageSize: 20, itemCount: 0 })

const statusTag: Record<Sub['status'], { label: string; type: 'success' | 'default' | 'warning' | 'error' }> = {
  active: { label: t('进行中'), type: 'success' }, paused: { label: t('已暂停'), type: 'default' },
  past_due: { label: t('扣款失败'), type: 'error' }, cancelled: { label: t('已取消'), type: 'default' },
}
const columns: DataTableColumns<Sub> = [
  { title: t('客户'), key: 'email', minWidth: 200, render: (r) => h('div', [h('div', r.email), h('div', { class: 'muted' }, r.items_summary)]) },
  { title: t('状态'), key: 'status', width: 100, render: (r) => h(NTag, { size: 'small', bordered: false, type: statusTag[r.status].type }, { default: () => statusTag[r.status].label }) },
  { title: t('频率'), key: 'interval_label', width: 150 },
  { title: t('每次金额'), key: 'value', width: 120, align: 'right', render: (r) => `${r.currency} ${r.value}` },
  { title: t('下次扣款'), key: 'next_billing_at', width: 160, render: (r) => (r.status === 'cancelled' || !r.next_billing_at ? '—' : formatDate(r.next_billing_at)) },
  { title: t('订单数'), key: 'orders_count', width: 80 },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/subscriptions', { params: { status: status.value === 'all' ? undefined : status.value, q: q.value || undefined, page: pagination.value.page } })
    rows.value = data.data
    counts.value = data.counts
    monthly.value = data.monthly_value
    pagination.value.itemCount = data.total
  } finally { loading.value = false }
}
let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => { clearTimeout(timer); timer = setTimeout(load, 300) })
watch(status, () => { pagination.value.page = 1; load() })
onMounted(load)

const detail = ref<Sub | null>(null)
const busy = ref(false)
async function openDetail(r: Sub) { detail.value = (await http.get(`/subscriptions/${r.id}`)).data.data }
async function act(action: string) {
  busy.value = true
  try {
    detail.value = (await http.post(`/subscriptions/${detail.value!.id}`, { action })).data.data
    message.success(t('已更新'))
    load()
  } catch (e) { message.error(errorMessage(e)) } finally { busy.value = false }
}
function confirmCancel() {
  dialog.warning({ title: t('取消这个订阅？'), content: t('不会再自动扣款和发货，会发邮件通知顾客。'), positiveText: t('取消订阅'), negativeText: t('返回'), onPositiveClick: () => act('cancel') })
}
</script>

<template>
  <div>
    <div class="eyebrow">Orders</div>
    <h1 class="page-title">{{ t('订阅') }}</h1>
    <p class="page-sub">{{ t('顾客订阅的商品会按频率自动生成订单并扣款。在商品编辑页可以开启订阅和设置优惠。') }}</p>

    <div class="stats">
      <div class="stat"><div class="label">{{ t('进行中的订阅') }}</div><div class="value">{{ counts.active ?? 0 }}</div></div>
      <div class="stat"><div class="label">{{ t('每月订阅收入（估算）') }}</div><div class="value">{{ currency }} {{ monthly }}</div></div>
      <div class="stat"><div class="label">{{ t('扣款失败') }}</div><div class="value">{{ counts.past_due ?? 0 }}</div></div>
    </div>

    <NCard content-style="padding: 0">
      <div class="toolbar">
        <NTabs v-model:value="status" type="line" size="small" style="flex: 1">
          <NTab name="active">{{ t('进行中') }}</NTab><NTab name="past_due">{{ t('扣款失败') }}</NTab>
          <NTab name="paused">{{ t('已暂停') }}</NTab><NTab name="cancelled">{{ t('已取消') }}</NTab><NTab name="all">{{ t('全部') }}</NTab>
        </NTabs>
        <NInput v-model:value="q" :placeholder="t('搜索邮箱')" clearable size="small" style="width: 200px" />
      </div>
      <NDataTable remote :columns="columns" :data="rows" :loading="loading" :bordered="false" :pagination="pagination" :scroll-x="820"
        :row-props="(r: Sub) => ({ style: 'cursor:pointer', onClick: () => openDetail(r) })" @update:page="(p: number) => { pagination.page = p; load() }" />
    </NCard>

    <NDrawer :show="!!detail" :width="460" @update:show="(v: boolean) => !v && (detail = null)">
      <NDrawerContent v-if="detail" :title="detail.email" closable>
        <NTag :type="statusTag[detail.status].type" :bordered="false">{{ statusTag[detail.status].label }}</NTag>
        <NAlert v-if="detail.status === 'past_due' && detail.last_error" type="error" :bordered="false" style="margin-top: 12px">
          {{ t('第 {0} 次扣款失败：{1}', [detail.failed_attempts, detail.last_error]) }}
        </NAlert>
        <div class="row"><span>{{ t('频率') }}</span><span>{{ detail.interval_label }}{{ detail.discount ? ` · ${detail.discount}% off` : '' }}</span></div>
        <div class="row"><span>{{ t('下次扣款') }}</span><span>{{ detail.status === 'cancelled' || !detail.next_billing_at ? '—' : formatDate(detail.next_billing_at) }}</span></div>
        <div class="row"><span>{{ t('付款方式') }}</span><span>{{ detail.payment_gateway === 'stripe' ? t('Stripe 保存的银行卡') : detail.payment_gateway === 'manual' ? t('线下付款') : detail.payment_gateway }}</span></div>
        <div class="sec">{{ t('商品') }}</div>
        <div v-for="(i, n) in detail.items" :key="n" class="row"><span>{{ i.title }}<span class="muted">{{ i.variant_title ? ` · ${i.variant_title}` : '' }}</span></span><span>× {{ i.quantity }}</span></div>
        <div v-if="detail.status !== 'cancelled'" class="acts">
          <NButton v-if="detail.status === 'active'" size="small" :loading="busy" @click="act('skip')">{{ t('跳过下一次') }}</NButton>
          <NButton v-if="detail.status === 'active'" size="small" :loading="busy" @click="act('pause')">{{ t('暂停') }}</NButton>
          <NButton v-else size="small" :loading="busy" @click="act('resume')">{{ t('恢复') }}</NButton>
          <NButton size="small" :loading="busy" @click="act('renew_now')">{{ t('立即续订') }}</NButton>
          <NButton size="small" type="error" secondary @click="confirmCancel">{{ t('取消订阅') }}</NButton>
        </div>
        <div class="sec">{{ t('订单') }}</div>
        <div v-for="o in detail.orders" :key="o.id" class="row link" @click="router.push({ name: 'order', params: { id: o.id } })">
          <span>{{ o.name }} <span class="muted">{{ formatDate(o.created_at) }}</span></span><span>{{ o.currency }} {{ o.total }}</span>
        </div>
      </NDrawerContent>
    </NDrawer>
  </div>
</template>

<style scoped>
.stats { display: grid; grid-template-columns: repeat(3, minmax(0, 220px)); gap: 12px; margin-bottom: 16px; }
.stat { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 12px 16px; }
.label { font-size: 12px; color: var(--ink-muted); }
.value { font-size: 20px; font-weight: 600; margin-top: 2px; font-variant-numeric: tabular-nums; }
.toolbar { display: flex; align-items: center; gap: 16px; padding: 8px 16px 0; }
:deep(.muted), .muted { color: var(--ink-muted); font-size: 12px; }
.row { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 14px; }
.row.link { cursor: pointer; }
.row.link:hover { color: var(--brand-700); }
.sec { font-size: 12px; font-weight: 600; color: var(--ink-muted); text-transform: uppercase; letter-spacing: .04em; margin: 18px 0 4px; }
.acts { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 16px; }
@media (max-width: 800px) { .stats { grid-template-columns: 1fr; } }
</style>
