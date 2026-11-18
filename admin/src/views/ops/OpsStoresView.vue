<script setup lang="ts">
import { h, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NCard, NDataTable, NInput, NTag, NTabs, NTab, type DataTableColumns } from 'naive-ui'
import { ops, stateLabel } from '@/api/ops'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Row {
  id: number; name: string; handle: string; owner: { name: string; email: string } | null; currency: string
  state: string; plan_name: string | null; interval: string | null; provider: string | null
  trial_ends_at: string | null; current_period_end: string | null; gmv_30d: string; created_at: string
}

const route = useRoute()
const router = useRouter()
const rows = ref<Row[]>([])
const loading = ref(false)
const q = ref('')
const state = ref(String(route.query.state ?? 'all'))
const pagination = ref({ page: 1, pageSize: 20, itemCount: 0 })

const columns: DataTableColumns<Row> = [
  { title: t('店铺'), key: 'name', minWidth: 200, render: (r) => h('div', [h('div', { style: 'font-weight:500' }, r.name), h('div', { style: 'font-size:12px;color:var(--ink-muted)' }, r.owner?.email ?? '—')]) },
  { title: t('状态'), key: 'state', width: 100, render: (r) => h(NTag, { size: 'small', bordered: false, type: stateLabel[r.state]?.type }, { default: () => stateLabel[r.state]?.label ?? r.state }) },
  {
    title: t('套餐'), key: 'plan_name', width: 150,
    render: (r) => (r.plan_name ? `${r.plan_name} · ${r.provider === 'manual' ? t('赠送') : r.interval === 'year' ? t('年付') : t('月付')}` : '—'),
  },
  {
    title: t('到期'), key: 'end', width: 170,
    render: (r) => formatDate(r.state === 'trialing' || !r.plan_name ? r.trial_ends_at : r.current_period_end),
  },
  { title: t('近 30 天成交'), key: 'gmv_30d', width: 130, align: 'right', render: (r) => `${r.currency} ${r.gmv_30d}` },
  { title: t('开店时间'), key: 'created_at', width: 170, render: (r) => formatDate(r.created_at) },
]

async function load() {
  loading.value = true
  try {
    const { data } = await ops.get('/stores', { params: { q: q.value || undefined, state: state.value === 'all' ? undefined : state.value, page: pagination.value.page } })
    rows.value = data.data
    pagination.value.itemCount = data.total
  } finally {
    loading.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { pagination.value.page = 1; load() }, 300)
})
watch(state, () => { pagination.value.page = 1; load() })
onMounted(load)
</script>

<template>
  <div>
    <div class="eyebrow">Ops</div>
    <h1 class="page-title">{{ t('店铺') }}</h1>
    <NCard content-style="padding: 0">
      <div class="toolbar">
        <NTabs v-model:value="state" type="line" size="small" style="flex: 1">
          <NTab name="all">{{ t('全部') }}</NTab>
          <NTab v-for="(v, k) in stateLabel" :key="k" :name="k">{{ v.label }}</NTab>
        </NTabs>
        <NInput v-model:value="q" :placeholder="t('店铺名、网址或店主邮箱')" clearable style="max-width: 260px" />
      </div>
      <NDataTable remote :columns="columns" :data="rows" :loading="loading" :bordered="false" :scroll-x="920" :pagination="pagination"
        :row-props="(r: Row) => ({ style: 'cursor:pointer', onClick: () => router.push({ name: 'ops-store', params: { id: r.id } }) })"
        @update:page="(p: number) => { pagination.page = p; load() }" />
    </NCard>
  </div>
</template>

<style scoped>
.toolbar { display: flex; align-items: center; gap: 16px; padding: 4px 16px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
</style>
