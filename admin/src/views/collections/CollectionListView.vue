<script setup lang="ts">
import { h, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NButton, NCard, NDataTable, NTag, NEmpty, type DataTableColumns } from 'naive-ui'
import { listCollections, type CollectionSummary } from '@/api/collections'
import { t } from '@/i18n'

const router = useRouter()
const rows = ref<CollectionSummary[]>([])
const loading = ref(true)

const columns: DataTableColumns<CollectionSummary> = [
  {
    title: t('分类'), key: 'title', minWidth: 240,
    render: (r) => h('div', { class: 'ccell' }, [
      h('div', { class: 'cthumb' }, h('img', { src: r.image_url || '/placeholder.svg', class: r.image_url ? '' : 'ph', alt: '' })),
      h('div', [h('div', { style: 'font-weight:500' }, r.title), h('div', { style: 'font-size:12px;color:var(--ink-muted)' }, '/collections/' + r.handle)]),
    ]),
  },
  { title: t('商品数'), key: 'products_count', width: 100 },
  {
    title: t('状态'), key: 'published', width: 100,
    render: (r) => h(NTag, { size: 'small', bordered: false, type: r.published ? 'success' : 'default' }, { default: () => (r.published ? t('已发布') : t('隐藏')) }),
  },
]

onMounted(async () => {
  try {
    rows.value = await listCollections(true)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="head">
      <div>
        <div class="eyebrow">Catalog</div>
        <h1 class="page-title">{{ t('商品分类') }}</h1>
        <p class="page-sub">{{ t('把商品分组，例如「项链」「耳饰」「新品」，买家可以按分类浏览') }}</p>
      </div>
      <NButton type="primary" @click="router.push({ name: 'collection-new' })">{{ t('新建分类') }}</NButton>
    </div>

    <NCard content-style="padding: 0">
      <NEmpty v-if="!loading && !rows.length" :description="t('还没有分类')" style="padding: 56px 0">
        <template #extra>
          <NButton size="small" @click="router.push({ name: 'collection-new' })">{{ t('新建第一个分类') }}</NButton>
        </template>
      </NEmpty>
      <NDataTable
        v-else
        :columns="columns"
        :data="rows"
        :loading="loading"
        :bordered="false"
        :scroll-x="560"
        :row-props="(r: CollectionSummary) => ({ style: 'cursor:pointer', onClick: () => router.push({ name: 'collection-edit', params: { id: r.id } }) })"
      />
    </NCard>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
:deep(.ccell) { display: flex; align-items: center; gap: 12px; }
:deep(.cthumb) { width: 44px; height: 44px; border-radius: 8px; overflow: hidden; background: var(--surface-muted); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; flex: none; }
:deep(.cthumb img) { width: 100%; height: 100%; object-fit: cover; }
:deep(.cthumb img.ph) { width: 60%; height: auto; opacity: .5; }
</style>
