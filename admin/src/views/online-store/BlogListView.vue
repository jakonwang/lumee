<script setup lang="ts">
import { h, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NCard, NDataTable, NButton, NTag, NEmpty, NInput, type DataTableColumns } from 'naive-ui'
import { http } from '@/api/http'
import { formatDate } from '@/api/orders'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

interface PostRow {
  id: number
  title: string
  handle: string
  author: string | null
  tags: string[]
  cover_image: string | null
  published: boolean
  published_at: string | null
  scheduled: boolean
  updated_at: string
}

const router = useRouter()
const auth = useAuth()
const rows = ref<PostRow[]>([])
const loading = ref(true)
const q = ref('')
const pagination = reactive({ page: 1, pageSize: 20, itemCount: 0 })

const columns: DataTableColumns<PostRow> = [
  {
    title: t('文章'), key: 'title',
    render: (r) => h('div', { class: 'post' }, [
      h('div', { class: 'thumb' }, r.cover_image ? [h('img', { src: r.cover_image, alt: '' })] : []),
      h('div', [
        h('div', { style: 'font-weight:500' }, r.title),
        h('div', { class: 'sub' }, [`/blog/${r.handle}`, r.tags.length ? ` · ${r.tags.join(', ')}` : '']),
      ]),
    ]),
  },
  {
    title: t('状态'), key: 'published', width: 120,
    render: (r) => h(NTag, { size: 'small', bordered: false, type: r.scheduled ? 'info' : r.published ? 'success' : 'default' },
      { default: () => (r.scheduled ? t('定时发布') : r.published ? t('已发布') : t('草稿')) }),
  },
  { title: t('发布时间'), key: 'published_at', width: 170, render: (r) => (r.published_at ? formatDate(r.published_at) : '—') },
]

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await http.get('/blog-posts', { params: { page, q: q.value || undefined } })
    rows.value = data.data
    pagination.page = data.meta.current_page
    pagination.itemCount = data.meta.total
  } finally {
    loading.value = false
  }
}

let timer: number | undefined
watch(q, () => { clearTimeout(timer); timer = window.setTimeout(() => load(), 300) })
onMounted(() => load())
</script>

<template>
  <div>
    <div class="head">
      <div>
        <div class="eyebrow">Online store</div>
        <h1 class="page-title">{{ t('博客') }}</h1>
        <p class="page-sub">{{ t('写穿搭指南、新品故事、品牌动态，帮助店铺获得搜索流量。可以在「导航菜单」里添加「博客」链接。') }}</p>
      </div>
      <NButton type="primary" @click="router.push({ name: 'post-new' })">{{ t('写文章') }}</NButton>
    </div>

    <NCard content-style="padding: 0">
      <div class="toolbar"><NInput v-model:value="q" :placeholder="t('搜索文章标题')" clearable size="small" style="width: 240px" /></div>
      <NDataTable
        v-if="loading || rows.length"
        remote
        :columns="columns"
        :data="rows"
        :loading="loading"
        :bordered="false"
        :pagination="pagination"
        :row-props="(r: PostRow) => ({ style: 'cursor:pointer', onClick: () => router.push({ name: 'post-edit', params: { id: r.id } }) })"
        @update:page="load"
      />
      <NEmpty v-else :description="t('还没有文章')" style="padding: 48px 0" />
    </NCard>
    <p class="muted" style="margin-top: 10px">
      {{ t('博客地址：') }}<a :href="`${auth.currentStore?.storefront_url}/blog`" target="_blank" rel="noopener">{{ auth.currentStore?.storefront_url }}/blog</a>
    </p>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.toolbar { padding: 12px 16px 4px; }
.muted { color: var(--ink-muted); font-size: 13px; margin: 0 0 12px; }
.muted a { color: var(--brand-700); }
:deep(.post) { display: flex; gap: 12px; align-items: center; }
:deep(.thumb) { width: 56px; height: 42px; border-radius: 6px; background: var(--surface-muted); overflow: hidden; flex: none; }
:deep(.thumb img) { width: 100%; height: 100%; object-fit: cover; display: block; }
:deep(.sub) { font-size: 12px; color: var(--ink-muted); }
</style>
