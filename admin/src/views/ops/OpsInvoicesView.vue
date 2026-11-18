<script setup lang="ts">
import { h, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NCard, NDataTable, NTag, NTabs, NTab, NButton, useMessage, useDialog, type DataTableColumns } from 'naive-ui'
import { ops } from '@/api/ops'
import { errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Row {
  id: number; number: string; kind: 'subscription' | 'fees'; description: string; amount: string; status: 'paid' | 'open' | 'void'
  created_at: string; paid_at: string | null; store: { id: number; name: string; handle: string } | null
}

const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const rows = ref<Row[]>([])
const loading = ref(false)
const status = ref('all')
const pagination = ref({ page: 1, pageSize: 30, itemCount: 0 })

function markPaid(r: Row) {
  dialog.info({
    title: t('确认已收到 {0} 的款项？', [r.number]),
    content: t('${0} · {1}。用于线下收款（没有 Stripe 订阅的店铺）。', [r.amount, r.store?.name]),
    positiveText: t('标记已支付'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        await ops.post(`/invoices/${r.id}/mark-paid`)
        message.success(t('已标记为已支付'))
        load()
      } catch (e) {
        message.error(errorMessage(e))
      }
    },
  })
}

const columns: DataTableColumns<Row> = [
  { title: t('编号'), key: 'number', width: 150, render: (r) => h('span', { style: 'font-family:ui-monospace,monospace;font-size:12px' }, r.number) },
  {
    title: t('店铺'), key: 'store', minWidth: 160,
    render: (r) => h('a', { style: 'color:var(--brand-700);cursor:pointer', onClick: () => r.store && router.push({ name: 'ops-store', params: { id: r.store.id } }) }, r.store?.name ?? '—'),
  },
  { title: t('内容'), key: 'description', minWidth: 200 },
  { title: t('金额'), key: 'amount', width: 110, align: 'right', render: (r) => `$${r.amount}` },
  {
    title: t('状态'), key: 'status', width: 100,
    render: (r) => h(NTag, { size: 'small', bordered: false, type: r.status === 'paid' ? 'success' : r.status === 'open' ? 'warning' : 'default' },
      { default: () => ({ paid: t('已支付'), open: t('待支付'), void: t('作废') })[r.status] }),
  },
  { title: t('日期'), key: 'created_at', width: 170, render: (r) => formatDate(r.created_at) },
  { title: '', key: 'act', width: 110, render: (r) => (r.status === 'open' ? h(NButton, { size: 'tiny', onClick: () => markPaid(r) }, { default: () => t('标记已支付') }) : null) },
]

async function load() {
  loading.value = true
  try {
    const { data } = await ops.get('/invoices', { params: { status: status.value === 'all' ? undefined : status.value, page: pagination.value.page } })
    rows.value = data.data
    pagination.value.itemCount = data.total
  } finally {
    loading.value = false
  }
}

watch(status, () => { pagination.value.page = 1; load() })
onMounted(load)
</script>

<template>
  <div>
    <div class="eyebrow">Ops</div>
    <h1 class="page-title">{{ t('平台账单') }}</h1>
    <p class="page-sub">{{ t('商家支付给 Lumée 的套餐费和交易佣金') }}</p>
    <NCard content-style="padding: 0">
      <div class="toolbar">
        <NTabs v-model:value="status" type="line" size="small">
          <NTab name="all">{{ t('全部') }}</NTab>
          <NTab name="open">{{ t('待支付') }}</NTab>
          <NTab name="paid">{{ t('已支付') }}</NTab>
        </NTabs>
      </div>
      <NDataTable remote :columns="columns" :data="rows" :loading="loading" :bordered="false" :scroll-x="940" :pagination="pagination"
        @update:page="(p: number) => { pagination.page = p; load() }" />
    </NCard>
  </div>
</template>

<style scoped>
.toolbar { padding: 4px 16px; border-bottom: 1px solid var(--border); }
</style>
