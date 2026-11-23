<script setup lang="ts">
import { h, onMounted, ref, watch } from 'vue'
import EmptyState from '@/components/EmptyState.vue'
import { useRouter } from 'vue-router'
import { NCard, NDataTable, NTag, NTabs, NTab, NInput, NButton, useMessage, type DataTableColumns } from 'naive-ui'
import { download } from '@/utils/download'
import { errorMessage } from '@/api/http'
import { http } from '@/api/http'
import { type Order, financialLabel, fulfillmentLabel, formatDate } from '@/api/orders'
import { t } from '@/i18n'

const router = useRouter()
const rows = ref<Order[]>([])
const loading = ref(false)
const tab = ref('all')
const q = ref('')
const pagination = ref({ page: 1, pageSize: 20, itemCount: 0 })

const filters: Record<string, Record<string, string>> = {
  all: {},
  unpaid: { status: 'unpaid' },
  unfulfilled: { status: 'to_fulfill' },
  fulfilled: { status: 'fulfilled' },
  refunded: { status: 'refunded' },
  cancelled: { status: 'cancelled' },
}

const columns: DataTableColumns<Order> = [
  { title: t('订单'), key: 'name', width: 90, render: (r) => h('span', { style: 'font-weight:600' }, r.name) },
  { title: t('日期'), key: 'created_at', width: 170, render: (r) => formatDate(r.created_at) },
  {
    title: t('客户'), key: 'customer_name', minWidth: 200,
    render: (r) => h('div', [h('div', r.customer_name), h('div', { style: 'font-size:12px;color:var(--ink-muted)' }, r.email)]),
  },
  {
    title: t('付款'), key: 'financial_status', width: 100,
    render: (r) => h(NTag, { size: 'small', bordered: false, type: financialLabel[r.financial_status].type }, { default: () => financialLabel[r.financial_status].label }),
  },
  {
    title: t('发货'), key: 'fulfillment_status', width: 100,
    render: (r) => r.cancelled_at
      ? h(NTag, { size: 'small', bordered: false }, { default: () => t('已取消') })
      : h(NTag, { size: 'small', bordered: false, type: fulfillmentLabel[r.fulfillment_status].type }, { default: () => fulfillmentLabel[r.fulfillment_status].label }),
  },
  {
    title: t('风险'), key: 'risk_level', width: 80,
    render: (r) => r.risk_level === 'high' || r.risk_level === 'medium'
      ? h(NTag, { size: 'small', bordered: false, type: r.risk_level === 'high' ? 'error' : 'warning' }, { default: () => (r.risk_level === 'high' ? t('高') : t('中')) })
      : h('span', { style: 'color:var(--ink-muted)' }, r.risk_level ? t('低') : '—'),
  },
  { title: t('商品数'), key: 'items_count', width: 80 },
  {
    title: t('金额'), key: 'total', width: 130, align: 'right',
    render: (r) => r.refunded_total !== '0.00'
      ? h('div', [h('s', { style: 'color:var(--ink-muted)' }, `${r.currency} ${r.total}`), h('div', `${r.currency} ${r.net_total}`)])
      : `${r.currency} ${r.total}`,
  },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/orders', {
      params: { ...filters[tab.value], q: q.value || undefined, page: pagination.value.page },
    })
    rows.value = data.data
    pagination.value.itemCount = data.meta.total
  } finally {
    loading.value = false
  }
}

function onPage(p: number) {
  pagination.value.page = p
  load()
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { pagination.value.page = 1; load() }, 300)
})
watch(tab, () => { pagination.value.page = 1; load() })
onMounted(load)

const message = useMessage()
const exporting = ref(false)
async function exportCsv() {
  exporting.value = true
  try {
    await download('/orders/export', { ...filters[tab.value], q: q.value || undefined }, 'orders.csv')
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <div class="eyebrow">Sales</div>
        <h1 class="page-title">{{ t('订单') }}</h1>
        <p class="page-sub">{{ t('查看与处理店铺的所有订单') }}</p>
      </div>
      <div class="head-actions">
        <NButton secondary :loading="exporting" @click="exportCsv">{{ t('导出') }}</NButton>
        <NButton type="primary" @click="router.push({ name: 'order-new' })">{{ t('创建订单') }}</NButton>
      </div>
    </div>

    <NCard content-style="padding: 0">
      <div class="toolbar">
        <NTabs v-model:value="tab" type="line" size="small" style="flex: 1">
          <NTab name="all">{{ t('全部') }}</NTab>
          <NTab name="unpaid">{{ t('待付款') }}</NTab>
          <NTab name="unfulfilled">{{ t('待发货') }}</NTab>
          <NTab name="fulfilled">{{ t('已发货') }}</NTab>
          <NTab name="refunded">{{ t('退款') }}</NTab>
          <NTab name="cancelled">{{ t('已取消') }}</NTab>
        </NTabs>
        <NInput v-model:value="q" :placeholder="t('搜索订单号或邮箱')" clearable size="small" style="width: 220px" />
      </div>
      <NDataTable
        remote
        :columns="columns"
        :data="rows"
        :loading="loading"
        :bordered="false"
        :pagination="pagination"
        :scroll-x="860"
        :row-props="(r: Order) => ({ style: 'cursor:pointer', onClick: () => router.push({ name: 'order', params: { id: r.id } }) })"
        @update:page="onPage"
      >
        <template #empty>
          <EmptyState v-if="q || tab !== 'all'" :title="t('没有符合条件的订单')" :text="t('换个关键词，或切换到「全部」看看。')" />
          <EmptyState v-else :title="t('还没有订单')" :text="t('买家下单后会出现在这里。也可以为电话或线下的客户手动创建订单。')">
            <NButton @click="router.push({ name: 'order-new' })">{{ t('手动创建订单') }}</NButton>
          </EmptyState>
        </template>
      </NDataTable>
    </NCard>
  </div>
</template>

<style scoped>
.head-actions { display: flex; gap: 8px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
.toolbar { display: flex; align-items: center; gap: 16px; padding: 8px 16px 0; }
</style>
