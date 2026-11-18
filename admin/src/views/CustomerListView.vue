<script setup lang="ts">
import { computed, h, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NButton, NCard, NDataTable, NInput, NModal, NSelect, NTag, useMessage, type DataTableColumns, type DataTableRowKey } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import { formatDate } from '@/api/orders'
import { download } from '@/utils/download'
import { t } from '@/i18n'

interface Customer {
  id: number
  name: string
  email: string
  phone: string | null
  orders_count: number
  total_spent: string
  account: 'none' | 'unverified' | 'active'
  accepts_marketing: boolean
  tags: string[]
  created_at: string
}

const auth = useAuth()
const router = useRouter()
const message = useMessage()
const rows = ref<Customer[]>([])
const loading = ref(false)
const q = ref('')
const pagination = ref({ page: 1, pageSize: 20, itemCount: 0 })

// ---- Segment filters (one row above the table) ----
const tag = ref<string | null>(null)
const marketing = ref<string | null>(null)
const orders = ref<string | null>(null)
const sort = ref('newest')
const allTags = ref<{ tag: string; count: number }[]>([])
const tagOptions = computed(() => allTags.value.map((x) => ({ label: `${x.tag} (${x.count})`, value: x.tag })))
const marketingOptions = [
  { label: t('已订阅营销邮件'), value: 'subscribed' },
  { label: t('未订阅'), value: 'not_subscribed' },
]
const ordersOptions = [
  { label: t('未下单'), value: 'none' },
  { label: t('下过 1 单'), value: 'one' },
  { label: t('回头客（2 单以上）'), value: 'repeat' },
]
const sortOptions = [
  { label: t('最新'), value: 'newest' },
  { label: t('消费最多'), value: 'spent' },
  { label: t('订单最多'), value: 'orders' },
  { label: t('最早'), value: 'oldest' },
]
const params = () => ({
  q: q.value || undefined, tag: tag.value || undefined, marketing: marketing.value || undefined,
  orders: orders.value || undefined, sort: sort.value,
})

const accountTag: Record<Customer['account'], { label: string; type: 'success' | 'warning' | 'default' }> = {
  active: { label: t('已注册'), type: 'success' },
  unverified: { label: t('待验证邮箱'), type: 'warning' },
  none: { label: t('游客'), type: 'default' },
}

const columns: DataTableColumns<Customer> = [
  { type: 'selection' },
  {
    title: t('客户'), key: 'name', minWidth: 220,
    render: (r) => h('div', [h('div', r.name || '—'), h('div', { style: 'font-size:12px;color:var(--ink-muted)' }, r.email)]),
  },
  {
    title: t('标签'), key: 'tags', minWidth: 160,
    render: (r) => h('div', { style: 'display:flex;gap:4px;flex-wrap:wrap' }, r.tags.slice(0, 3).map((x) => h(NTag, { size: 'small', bordered: false }, { default: () => x }))),
  },
  {
    title: t('账户'), key: 'account', width: 110,
    render: (r) => h(NTag, { size: 'small', bordered: false, type: accountTag[r.account].type }, { default: () => accountTag[r.account].label }),
  },
  { title: t('订阅营销邮件'), key: 'accepts_marketing', width: 110, render: (r) => (r.accepts_marketing ? t('是') : '—') },
  { title: t('订单数'), key: 'orders_count', width: 80 },
  { title: t('消费金额'), key: 'total_spent', width: 130, align: 'right', render: (r) => `${auth.currentStore?.currency ?? ''} ${r.total_spent}` },
  { title: t('创建时间'), key: 'created_at', width: 160, render: (r) => formatDate(r.created_at) },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/customers', { params: { ...params(), page: pagination.value.page } })
    rows.value = data.data
    pagination.value.itemCount = data.total
  } finally {
    loading.value = false
  }
}
async function loadTags() {
  allTags.value = (await http.get('/customers/tags')).data.data
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
watch([tag, marketing, orders, sort], () => { pagination.value.page = 1; checked.value = []; load() })
onMounted(() => { load(); loadTags() })

// ---- Bulk tags ----
const checked = ref<DataTableRowKey[]>([])
const bulk = ref<'add' | 'remove' | null>(null)
const bulkTag = ref('')
async function applyBulk() {
  if (!bulk.value || !bulkTag.value.trim()) return
  try {
    const { data } = await http.post('/customers/bulk-tags', { ids: checked.value, tag: bulkTag.value.trim(), action: bulk.value })
    message.success(t('已更新 {0} 位客户', [data.updated]))
    bulk.value = null
    bulkTag.value = ''
    load()
    loadTags()
  } catch (e) { message.error(errorMessage(e)) }
}

const exporting = ref(false)
async function exportCsv() {
  exporting.value = true
  try { await download('/customers/export', params(), 'customers.csv') } catch (e) { message.error(errorMessage(e)) } finally { exporting.value = false }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <div class="eyebrow">Customers</div>
        <h1 class="page-title">{{ t('客户') }}</h1>
        <p class="page-sub">{{ t('在店铺下过单或注册了账户的买家') }}</p>
      </div>
      <NButton secondary :loading="exporting" @click="exportCsv">{{ t('导出') }}</NButton>
    </div>
    <NCard content-style="padding: 0">
      <div class="toolbar">
        <NInput v-model:value="q" :placeholder="t('搜索姓名、邮箱或电话')" clearable size="small" style="width: 240px" />
        <NSelect v-model:value="tag" :options="tagOptions" :placeholder="t('标签')" clearable filterable size="small" style="width: 160px" />
        <NSelect v-model:value="orders" :options="ordersOptions" :placeholder="t('购买次数')" clearable size="small" style="width: 170px" />
        <NSelect v-model:value="marketing" :options="marketingOptions" :placeholder="t('营销邮件')" clearable size="small" style="width: 150px" />
        <NSelect v-model:value="sort" :options="sortOptions" size="small" style="width: 120px; margin-left: auto" />
      </div>
      <div v-if="checked.length" class="bulk">
        <span>{{ t('已选 {0} 位', [checked.length]) }}</span>
        <NButton size="small" @click="bulk = 'add'">{{ t('添加标签') }}</NButton>
        <NButton size="small" @click="bulk = 'remove'">{{ t('移除标签') }}</NButton>
      </div>
      <NDataTable
        v-model:checked-row-keys="checked"
        remote
        :columns="columns"
        :data="rows"
        :loading="loading"
        :bordered="false"
        :scroll-x="980"
        :pagination="pagination"
        :row-key="(r: Customer) => r.id"
        :row-props="(r: Customer) => ({ style: 'cursor:pointer', onClick: (e: MouseEvent) => { if (!(e.target as HTMLElement).closest('.n-data-table-td--selection')) router.push({ name: 'customer', params: { id: r.id } }) } })"
        @update:page="onPage"
      />
    </NCard>

    <NModal :show="bulk !== null" preset="card" :title="bulk === 'add' ? t('添加标签') : t('移除标签')" style="width: 400px; max-width: 94vw" @update:show="(v: boolean) => !v && (bulk = null)">
      <NSelect v-model:value="bulkTag" :options="allTags.map((x) => ({ label: x.tag, value: x.tag }))" filterable :tag="bulk === 'add'" :placeholder="t('输入或选择标签')" />
      <div class="modal-actions">
        <NButton @click="bulk = null">{{ t('取消') }}</NButton>
        <NButton type="primary" :disabled="!bulkTag" @click="applyBulk">{{ t('确定') }}</NButton>
      </div>
    </NModal>
  </div>
</template>

<style scoped>
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
.toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--border); }
.bulk { display: flex; align-items: center; gap: 8px; padding: 8px 16px; background: var(--brand-50); border-bottom: 1px solid var(--border); font-size: 13px; }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
</style>
