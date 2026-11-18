<script setup lang="ts">
import { h, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NCard, NDataTable, NButton, NTag, NSpace, NEmpty, useMessage, type DataTableColumns } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

interface PageRow { id: number; title: string; handle: string; published: boolean; updated_at: string }
interface Template { handle: string; title: string; label: string }

const router = useRouter()
const message = useMessage()
const auth = useAuth()
const rows = ref<PageRow[]>([])
const templates = ref<Template[]>([])
const loading = ref(true)
const creating = ref<string | null>(null)

const columns: DataTableColumns<PageRow> = [
  {
    title: t('页面'), key: 'title',
    render: (r) => h('div', [h('div', { style: 'font-weight:500' }, r.title), h('div', { style: 'font-size:12px;color:var(--ink-muted)' }, `/pages/${r.handle}`)]),
  },
  {
    title: t('状态'), key: 'published', width: 100,
    render: (r) => h(NTag, { size: 'small', bordered: false, type: r.published ? 'success' : 'default' }, { default: () => (r.published ? t('已发布') : t('未发布')) }),
  },
  { title: t('更新时间'), key: 'updated_at', width: 170, render: (r) => formatDate(r.updated_at) },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/pages')
    rows.value = data.data
    templates.value = data.templates
  } finally {
    loading.value = false
  }
}

async function fromTemplate(it: Template) {
  creating.value = it.handle
  try {
    const { data } = await http.post('/pages', { template: it.handle })
    message.success(t('已创建「{0}」，请检查内容后发布', [it.title]))
    router.push({ name: 'page-edit', params: { id: data.data.id } })
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    creating.value = null
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="head">
      <div>
        <div class="eyebrow">Online store</div>
        <h1 class="page-title">{{ t('页面') }}</h1>
        <p class="page-sub">{{ t('关于我们、常见问题、配送和退换货政策等内容页。可以在「导航菜单」里把它们加到页头或页脚。') }}</p>
      </div>
      <NButton type="primary" @click="router.push({ name: 'page-new' })">{{ t('添加页面') }}</NButton>
    </div>

    <NCard v-if="templates.length" size="small" :title="t('快速创建常用页面')" style="margin-bottom: 16px">
      <p class="muted">{{ t('已为跨境店铺准备好英文模板，创建后为「未发布」状态，修改成你自己的内容后再发布。模板仅供参考，不构成法律意见。') }}</p>
      <NSpace>
        <NButton v-for="it in templates" :key="it.handle" size="small" :loading="creating === it.handle" @click="fromTemplate(it)">+ {{ it.label }}</NButton>
      </NSpace>
    </NCard>

    <NCard content-style="padding: 0">
      <NDataTable
        v-if="loading || rows.length"
        :columns="columns"
        :data="rows"
        :loading="loading"
        :bordered="false"
        :row-props="(r: PageRow) => ({ style: 'cursor:pointer', onClick: () => router.push({ name: 'page-edit', params: { id: r.id } }) })"
      />
      <NEmpty v-else :description="t('还没有页面')" style="padding: 48px 0" />
    </NCard>
    <p v-if="rows.length" class="muted" style="margin-top: 10px">
      {{ t('店铺地址：') }}<a :href="auth.currentStore?.storefront_url" target="_blank" rel="noopener">{{ auth.currentStore?.storefront_url }}</a>
    </p>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.muted { color: var(--ink-muted); font-size: 13px; margin: 0 0 12px; }
.muted a { color: var(--brand-700); }
</style>
