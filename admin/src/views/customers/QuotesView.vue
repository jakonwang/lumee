<script setup lang="ts">
/** Customers › Quotes: business buyers' quote requests; set prices, shipping and expiry, then send. */
import { computed, h, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NAlert, NButton, NCard, NDataTable, NDatePicker, NDrawer, NDrawerContent, NInput, NInputNumber, NTabs, NTab, NTag, useMessage, type DataTableColumns } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

interface Row { id: number; name: string; status: string; email: string; company: string | null; items_count: number; subtotal: string; created_at: string; sent_at: string | null }
interface Item { variant_id: number; title: string; variant_title: string | null; sku: string | null; quantity: number; list_price: string; price: string | null }
interface Detail extends Row { buyer_note: string | null; merchant_note: string | null; shipping_price: string | null; expires_on: string | null; order_id: number | null; items: Item[]; url: string; shipping_address: Record<string, string> | null }

const router = useRouter()
const message = useMessage()
const currency = useAuth().currentStore?.currency ?? ''
const status = ref('requested')
const rows = ref<Row[]>([])
const counts = ref<Record<string, number>>({})
const loading = ref(false)
const statusTag: Record<string, { label: string; type: 'warning' | 'info' | 'success' | 'default' | 'error' }> = {
  requested: { label: t('待报价'), type: 'warning' }, sent: { label: t('已报价'), type: 'info' }, accepted: { label: t('已接受'), type: 'success' },
  declined: { label: t('已拒绝'), type: 'default' }, expired: { label: t('已过期'), type: 'default' },
}
const columns: DataTableColumns<Row> = [
  { title: t('询价单'), key: 'name', width: 90, render: (r) => h('strong', r.name) },
  { title: t('客户'), key: 'email', minWidth: 200, render: (r) => h('div', [h('div', r.company ?? r.email), r.company ? h('div', { class: 'muted' }, r.email) : null]) },
  { title: t('状态'), key: 'status', width: 90, render: (r) => h(NTag, { size: 'small', bordered: false, type: statusTag[r.status]?.type }, { default: () => statusTag[r.status]?.label ?? r.status }) },
  { title: t('商品数'), key: 'items_count', width: 80 },
  { title: t('金额'), key: 'subtotal', width: 120, align: 'right', render: (r) => `${currency} ${r.subtotal}` },
  { title: t('日期'), key: 'created_at', width: 160, render: (r) => formatDate(r.created_at) },
]
async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/quotes', { params: { status: status.value === 'all' ? undefined : status.value } })
    rows.value = data.data
    counts.value = data.counts
  } finally { loading.value = false }
}
watch(status, load)
onMounted(load)

const d = ref<Detail | null>(null)
const items = ref<{ variant_id: number; title: string; variant_title: string | null; list: number; quantity: number; price: number }[]>([])
const shipping = ref<number | null>(null)
const expires = ref<number | null>(null)
const note = ref('')
const busy = ref(false)
async function open(r: Row) {
  d.value = (await http.get(`/quotes/${r.id}`)).data.data
  items.value = d.value!.items.map((i) => ({ variant_id: i.variant_id, title: i.title, variant_title: i.variant_title, list: Number(i.list_price), quantity: i.quantity, price: Number(i.price ?? i.list_price) }))
  shipping.value = d.value!.shipping_price !== null ? Number(d.value!.shipping_price) : null
  expires.value = d.value!.expires_on ? new Date(`${d.value!.expires_on}T00:00:00`).getTime() : Date.now() + 14 * 86400000
  note.value = d.value!.merchant_note ?? ''
}
const total = computed(() => items.value.reduce((s, i) => s + i.price * i.quantity, 0) + (shipping.value ?? 0))
const editable = computed(() => d.value && ['requested', 'sent'].includes(d.value.status))
const ymd = (ms: number) => { const x = new Date(ms); return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}` }
async function send() {
  busy.value = true
  try {
    d.value = (await http.post(`/quotes/${d.value!.id}/send`, {
      items: items.value.map((i) => ({ variant_id: i.variant_id, quantity: i.quantity, price: i.price })),
      shipping_price: shipping.value, expires_on: expires.value ? ymd(expires.value) : null, note: note.value || null,
    })).data.data
    message.success(t('报价已发送给客户'))
    load()
  } catch (e) { message.error(errorMessage(e)) } finally { busy.value = false }
}
async function decline() {
  d.value = (await http.post(`/quotes/${d.value!.id}/decline`)).data.data
  load()
}
</script>

<template>
  <div>
    <div class="eyebrow">B2B wholesale</div>
    <h1 class="page-title">{{ t('询价单') }}</h1>
    <p class="page-sub">{{ t('批发客户在购物车里提交询价，你填好价格、运费和有效期后发给客户；客户接受后自动生成订单（有账期的公司按账期付款）。') }}</p>

    <NCard content-style="padding: 0">
      <div class="toolbar">
        <NTabs v-model:value="status" type="line" size="small">
          <NTab name="requested">{{ t('待报价') }}{{ counts.requested ? ` (${counts.requested})` : '' }}</NTab>
          <NTab name="sent">{{ t('已报价') }}</NTab><NTab name="accepted">{{ t('已接受') }}</NTab><NTab name="declined">{{ t('已拒绝') }}</NTab><NTab name="all">{{ t('全部') }}</NTab>
        </NTabs>
      </div>
      <NDataTable :columns="columns" :data="rows" :loading="loading" :bordered="false" :scroll-x="720"
        :row-props="(r: Row) => ({ style: 'cursor:pointer', onClick: () => open(r) })" />
    </NCard>

    <NDrawer :show="!!d" :width="640" @update:show="(v: boolean) => !v && (d = null)">
      <NDrawerContent v-if="d" :title="`${d.name} · ${d.company ?? d.email}`" closable>
        <NTag :type="statusTag[d.status]?.type" :bordered="false">{{ statusTag[d.status]?.label }}</NTag>
        <NAlert v-if="d.buyer_note" type="info" :bordered="false" style="margin-top: 12px">{{ t('客户留言：{0}', [d.buyer_note]) }}</NAlert>
        <table class="tbl">
          <thead><tr><th>{{ t('商品') }}</th><th class="num">{{ t('原价') }}</th><th class="num">{{ t('数量') }}</th><th class="num">{{ t('报价单价') }}</th><th class="num">{{ t('金额') }}</th></tr></thead>
          <tbody>
            <tr v-for="i in items" :key="i.variant_id">
              <td>{{ i.title }}<div class="muted">{{ i.variant_title }}</div></td>
              <td class="num muted">{{ i.list.toFixed(2) }}</td>
              <td class="num"><NInputNumber v-model:value="i.quantity" :min="0" size="small" :show-button="false" :disabled="!editable" style="width: 80px" /></td>
              <td class="num"><NInputNumber v-model:value="i.price" :min="0" :precision="2" size="small" :show-button="false" :disabled="!editable" style="width: 100px" /></td>
              <td class="num">{{ (i.price * i.quantity).toFixed(2) }}</td>
            </tr>
          </tbody>
        </table>
        <div class="fields">
          <div class="field"><span>{{ t('运费（留空 = 稍后计算）') }}</span><NInputNumber v-model:value="shipping" :min="0" :precision="2" clearable size="small" :disabled="!editable" style="width: 140px" /></div>
          <div class="field"><span>{{ t('有效期至') }}</span><NDatePicker v-model:value="expires" type="date" size="small" :disabled="!editable" :is-date-disabled="(ts: number) => ts < Date.now() - 86400000" /></div>
          <div class="field total"><span>{{ t('合计（不含税）') }}</span><strong>{{ currency }} {{ total.toFixed(2) }}</strong></div>
        </div>
        <NInput v-model:value="note" type="textarea" :autosize="{ minRows: 2 }" maxlength="2000" :disabled="!editable" :placeholder="t('给客户的说明（交期、付款方式等）')" />
        <div v-if="editable" class="actions">
          <NButton quaternary @click="decline">{{ t('拒绝询价') }}</NButton>
          <NButton type="primary" :loading="busy" @click="send">{{ d.status === 'sent' ? t('更新并重新发送') : t('发送报价') }}</NButton>
        </div>
        <div v-else-if="d.order_id" class="actions"><NButton type="primary" @click="router.push({ name: 'order', params: { id: d.order_id } })">{{ t('查看订单') }}</NButton></div>
        <p class="muted" style="margin-top: 12px">{{ t('客户查看链接：') }}<a :href="d.url" target="_blank" rel="noopener">{{ d.url }}</a></p>
      </NDrawerContent>
    </NDrawer>
  </div>
</template>

<style scoped>
.toolbar { padding: 8px 16px 0; border-bottom: 1px solid var(--border); }
:deep(.muted), .muted { color: var(--ink-muted); font-size: 12px; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 14px; }
.tbl th { text-align: left; font-size: 12px; font-weight: 500; color: var(--ink-muted); padding: 6px 4px; border-bottom: 1px solid var(--border); }
.tbl td { padding: 8px 4px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.num { text-align: right !important; white-space: nowrap; font-variant-numeric: tabular-nums; }
.fields { display: grid; gap: 10px; margin: 14px 0; }
.field { display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 14px; }
.field.total { border-top: 1px solid var(--border); padding-top: 10px; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
</style>
