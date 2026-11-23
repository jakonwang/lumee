<script setup lang="ts">
import { h, onMounted, ref } from 'vue'
import DiscountSwitch from '@/components/DiscountSwitch.vue'
import { useRouter } from 'vue-router'
import { NCard, NDataTable, NButton, NTag, NEmpty, NDropdown, type DataTableColumns } from 'naive-ui'
import { http } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

export interface PromotionRow {
  id: number
  title: string
  type: 'sale' | 'tiered' | 'bundle'
  value_type: 'percentage' | 'fixed'
  value: number | null
  applies_to: string | null
  tiers: { min: string; value: number }[]
  product_ids: number[]
  collection_ids: number[]
  combines_with_codes: boolean
  enabled: boolean
  starts_at: string | null
  ends_at: string | null
  status: 'active' | 'scheduled' | 'ended' | 'disabled'
  orders_count: number
  discount_total: string
}

const router = useRouter()
const rows = ref<PromotionRow[]>([])
const loading = ref(true)

const typeLabel: Record<PromotionRow['type'], string> = { sale: t('限时折扣'), tiered: t('满减'), bundle: t('捆绑优惠') }
const statusMeta: Record<PromotionRow['status'], { label: string; type: 'success' | 'info' | 'default' | 'warning' }> = {
  active: { label: t('进行中'), type: 'success' },
  scheduled: { label: t('未开始'), type: 'info' },
  ended: { label: t('已结束'), type: 'default' },
  disabled: { label: t('已停用'), type: 'warning' },
}

const amount = (v: number | string, vt: PromotionRow['value_type']) => (vt === 'percentage' ? `${Number(v)}%` : `${Number(v).toFixed(2)}`)
function summary(p: PromotionRow): string {
  if (p.type === 'tiered') return p.tiers.map((it) => t('满 {0} 减 {1}', [Number(it.min).toFixed(2), amount(it.value, p.value_type)])).join('，')
  if (p.type === 'bundle') return t('{0} 件商品一起买，每套减 {1}', [p.product_ids.length, amount(p.value ?? 0, p.value_type)])
  const scope = p.applies_to === 'products' ? t('{0} 件商品', [p.product_ids.length]) : p.applies_to === 'collections' ? t('{0} 个分类', [p.collection_ids.length]) : t('全部商品')
  return t('{0}减 {1}', [scope, amount(p.value ?? 0, p.value_type)])
}

const columns: DataTableColumns<PromotionRow> = [
  {
    title: t('活动'), key: 'title', minWidth: 260,
    render: (r) => h('div', [h('div', { style: 'font-weight:500' }, r.title), h('div', { class: 'sub' }, summary(r))]),
  },
  { title: t('类型'), key: 'type', width: 110, render: (r) => typeLabel[r.type] },
  { title: t('状态'), key: 'status', width: 100, render: (r) => h(NTag, { size: 'small', bordered: false, type: statusMeta[r.status].type }, () => statusMeta[r.status].label) },
  {
    title: t('时间'), key: 'starts_at', width: 210,
    render: (r) => h('div', { class: 'sub' }, [`${r.starts_at ? formatDate(r.starts_at) : t('立即开始')} → ${r.ends_at ? formatDate(r.ends_at) : t('长期有效')}`]),
  },
  {
    title: t('效果'), key: 'orders_count', width: 150,
    render: (r) => (r.type === 'sale' ? h('span', { class: 'sub' }, t('直接降价，不单独统计')) : h('div', { class: 'sub' }, t('{0} 单 · 共优惠 {1}', [r.orders_count, r.discount_total]))),
  },
]

const newOptions = [
  { key: 'sale', label: t('限时折扣：指定商品打折，前台显示原价和折后价') },
  { key: 'tiered', label: t('满减：订单满一定金额自动减钱或打折') },
  { key: 'bundle', label: t('捆绑优惠：几件商品一起买享受优惠') },
]

async function load() {
  loading.value = true
  try {
    rows.value = (await http.get('/promotions')).data.data
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <DiscountSwitch />
    <div class="head">
      <div>
        <div class="eyebrow">Marketing</div>
        <h1 class="page-title">{{ t('自动折扣') }}</h1>
        <p class="page-sub">{{ t('不需要折扣码，满足条件时自动生效：限时折扣、满减、捆绑优惠。') }}</p>
      </div>
      <NDropdown trigger="click" :options="newOptions" @select="(k: string) => router.push({ name: 'promotion-new', query: { type: k } })">
        <NButton type="primary">{{ t('创建活动') }}</NButton>
      </NDropdown>
    </div>

    <NCard content-style="padding: 0">
      <NDataTable
        v-if="loading || rows.length"
        :columns="columns"
        :data="rows"
        :loading="loading"
        :bordered="false"
        :scroll-x="860"
        :row-props="(r: PromotionRow) => ({ style: 'cursor:pointer', onClick: () => router.push({ name: 'promotion-edit', params: { id: r.id } }) })"
      />
      <NEmpty v-else :description="t('还没有自动折扣活动')" style="padding: 48px 0" />
    </NCard>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
:deep(.sub) { font-size: 12px; color: var(--ink-muted); line-height: 1.6; }
</style>
