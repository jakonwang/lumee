<script setup lang="ts">
import { h, onMounted, ref, watch } from 'vue'
import EmptyState from '@/components/EmptyState.vue'
import { useRouter } from 'vue-router'
import { NButton, NDataTable, NTag, NCard, NInput, NSpace, useMessage, type DataTableColumns } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import ProductImportModal from '@/components/ProductImportModal.vue'
import CopyProductsModal from '@/components/CopyProductsModal.vue'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

interface Product {
  id: number
  title: string
  handle: string
  status: 'active' | 'draft' | 'archived'
  product_type: string | null
  featured_image: string | null
  price: string
  price_max: string
  inventory: number
  variants_count: number
}

const auth = useAuth()
const router = useRouter()
const rows = ref<Product[]>([])
const loading = ref(false)
const q = ref('')
const pagination = ref({ page: 1, pageSize: 20, itemCount: 0 })

const statusMap = {
  active: { label: t('在售'), type: 'success' },
  draft: { label: t('草稿'), type: 'default' },
  archived: { label: t('已归档'), type: 'warning' },
} as const

const money = (v: string) => `${auth.currentStore?.currency ?? ''} ${v}`

const columns: DataTableColumns<Product> = [
  { type: 'selection' },
  {
    title: t('商品'), key: 'title', minWidth: 260,
    render: (r) => h('div', { class: 'pcell' }, [
      h('div', { class: 'pthumb' }, r.featured_image
        ? h('img', { src: r.featured_image, alt: '' })
        : h('img', { src: '/placeholder.svg', alt: '', class: 'ph' })),
      h('div', [
        h('div', { style: 'font-weight:500' }, r.title),
        h('div', { style: 'font-size:12px;color:var(--ink-muted)' }, r.product_type || '/' + r.handle),
      ]),
    ]),
  },
  {
    title: t('状态'), key: 'status', width: 100,
    render: (r) => h(NTag, { type: statusMap[r.status].type, size: 'small', bordered: false }, { default: () => statusMap[r.status].label }),
  },
  {
    title: t('库存'), key: 'inventory', width: 150,
    render: (r) => h('span', { style: r.inventory <= 0 ? 'color:var(--danger)' : '' },
      r.variants_count > 1 ? t('{0} 件 · {1} 个 SKU', [r.inventory, r.variants_count]) : t('{0} 件', [r.inventory])),
  },
  {
    title: t('价格'), key: 'price', width: 180, align: 'right',
    render: (r) => r.price === r.price_max ? money(r.price) : `${money(r.price)} – ${r.price_max}`,
  },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/products', { params: { q: q.value || undefined, page: pagination.value.page } })
    rows.value = data.data
    pagination.value.itemCount = data.meta.total
  } finally {
    loading.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { pagination.value.page = 1; load() }, 300)
})

// Bulk import / export
const message = useMessage()
const importOpen = ref(false)
const exporting = ref(false)
async function exportCsv() {
  exporting.value = true
  try {
    const res = await http.get('/products/export', { responseType: 'blob' })
    const name = /filename="?([^";]+)"?/.exec(res.headers['content-disposition'] ?? '')?.[1] ?? 'products.csv'
    const url = URL.createObjectURL(res.data)
    Object.assign(document.createElement('a'), { href: url, download: name }).click()
    URL.revokeObjectURL(url)
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    exporting.value = false
  }
}

// Selection → copy to another store
const checked = ref<number[]>([])
const copyOpen = ref(false)

onMounted(load)
</script>

<template>
  <div>
    <div class="head">
      <div>
        <div class="eyebrow">Catalog</div>
        <h1 class="page-title">{{ t('商品') }}</h1>
        <p class="page-sub">{{ t('管理店铺中的所有商品') }}</p>
      </div>
      <NSpace>
        <NButton @click="importOpen = true">{{ t('导入') }}</NButton>
        <NButton :loading="exporting" @click="exportCsv">{{ t('导出') }}</NButton>
        <NButton type="primary" @click="router.push({ name: 'product-new' })">{{ t('添加商品') }}</NButton>
      </NSpace>
    </div>

    <NCard content-style="padding: 0">
      <div class="toolbar">
        <div v-if="checked.length" class="bulk">
          <span>{{ t('已选 {0} 件', [checked.length]) }}</span>
          <NButton size="small" @click="copyOpen = true">{{ t('复制到其他店铺') }}</NButton>
          <NButton size="small" quaternary @click="checked = []">{{ t('取消选择') }}</NButton>
        </div>
        <NInput v-else v-model:value="q" :placeholder="t('搜索商品名称')" clearable size="small" style="max-width: 260px" />
      </div>
      <NDataTable
        v-model:checked-row-keys="checked"
        remote
        :row-key="(r: Product) => r.id"
        :columns="columns"
        :data="rows"
        :loading="loading"
        :bordered="false"
        :pagination="pagination"
        :scroll-x="700"
        :row-props="(r: Product) => ({ style: 'cursor:pointer', onClick: (e: MouseEvent) => { if (!(e.target as HTMLElement).closest('.n-data-table-td--selection')) router.push({ name: 'product-edit', params: { id: r.id } }) } })"
        @update:page="(p: number) => { pagination.page = p; load() }"
      >
        <template #empty>
          <EmptyState v-if="q" :title="t('没有符合条件的商品')" :text="t('换个关键词，或清空搜索看看全部商品。')" />
          <EmptyState v-else :title="t('添加第一个商品')" :text="t('手动添加、从 CSV 导入，或用「选品与采购 › 采集」从其他平台一键导入。')">
            <NButton type="primary" @click="router.push({ name: 'product-new' })">{{ t('添加商品') }}</NButton>
            <NButton @click="importOpen = true">{{ t('导入') }}</NButton>
          </EmptyState>
        </template>
      </NDataTable>
    </NCard>

    <ProductImportModal v-model:show="importOpen" @done="load" />
    <CopyProductsModal v-model:show="copyOpen" :product-ids="checked" @done="checked = []" />
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; }
.toolbar { padding: 12px 16px; min-height: 52px; display: flex; align-items: center; }
.bulk { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.bulk span { margin-right: 4px; color: var(--ink); font-weight: 500; }
:deep(.pcell) { display: flex; align-items: center; gap: 12px; }
:deep(.pthumb) {
  width: 44px; height: 44px; border-radius: 8px; overflow: hidden; flex: none;
  background: var(--surface-muted); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
}
:deep(.pthumb img) { width: 100%; height: 100%; object-fit: cover; }
:deep(.pthumb img.ph) { width: 60%; height: auto; opacity: .5; }
</style>
