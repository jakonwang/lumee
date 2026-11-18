<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  NCard, NDataTable, NTag, NTabs, NTab, NInput, NSelect, NButton, NModal, NSwitch, NRadioGroup, NRadio, NSpace, NUpload, NAlert,
  useMessage, useDialog, type DataTableColumns, type DataTableRowKey, type UploadFileInfo,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Review {
  id: number
  product: { id: number; title: string; handle: string } | null
  author: string
  email: string | null
  rating: number
  title: string | null
  body: string
  images: string[]
  status: 'pending' | 'published' | 'hidden'
  verified: boolean
  source: string
  reply: string | null
  created_at: string
}

const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const rows = ref<Review[]>([])
const counts = ref<Record<string, number>>({})
const tab = ref<'all' | Review['status']>('pending')
const q = ref('')
const rating = ref<number | null>(null)
const checked = ref<DataTableRowKey[]>([])
const pagination = reactive({ page: 1, pageSize: 20, itemCount: 0 })
const settings = reactive({ enabled: true, auto_publish: 'verified', request_delay_days: 7 })
const settingsOpen = ref(false)
const savingSettings = ref(false)

const statusMeta = computed(() => ({
  pending: { label: t('待审核'), type: 'warning' as const },
  published: { label: t('已发布'), type: 'success' as const },
  hidden: { label: t('已隐藏'), type: 'default' as const },
}))
const ratingOptions = [5, 4, 3, 2, 1].map((n) => ({ value: n, label: '★'.repeat(n) }))
const stars = (n: number) => '★'.repeat(n) + '☆'.repeat(5 - n)

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await http.get('/reviews', {
      params: { page, status: tab.value === 'all' ? undefined : tab.value, q: q.value || undefined, rating: rating.value ?? undefined },
    })
    rows.value = data.data
    counts.value = data.counts
    Object.assign(settings, data.settings)
    pagination.page = data.meta.current_page
    pagination.itemCount = data.meta.total
    checked.value = []
  } finally {
    loading.value = false
  }
}

let timer: number | undefined
watch([tab, rating], () => load())
watch(q, () => { clearTimeout(timer); timer = window.setTimeout(() => load(), 300) })

async function setStatus(r: Review, status: Review['status']) {
  try {
    await http.patch(`/reviews/${r.id}`, { status })
    message.success(status === 'published' ? t('已发布') : t('已隐藏'))
    load(pagination.page)
  } catch (e) {
    message.error(errorMessage(e))
  }
}

function remove(r: Review) {
  dialog.warning({
    title: t('删除这条评论？'), content: t('删除后无法恢复，评论中的图片也会一起删除。'),
    positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.delete(`/reviews/${r.id}`)
      message.success(t('已删除'))
      load(pagination.page)
    },
  })
}

async function bulk(action: 'publish' | 'hide' | 'delete') {
  const run = async () => {
    const { data } = await http.post('/reviews/bulk', { ids: checked.value, action })
    message.success(t('已处理 {0} 条评论', [data.affected]))
    load(pagination.page)
  }
  if (action === 'delete') {
    dialog.warning({ title: t('删除选中的 {0} 条评论？', [checked.value.length]), positiveText: t('删除'), negativeText: t('取消'), onPositiveClick: run })
  } else {
    await run()
  }
}

// Reply
const replying = ref<Review | null>(null)
const replyText = ref('')
const savingReply = ref(false)
function openReply(r: Review) {
  replying.value = r
  replyText.value = r.reply ?? ''
}
async function saveReply() {
  if (!replying.value) return
  savingReply.value = true
  try {
    await http.patch(`/reviews/${replying.value.id}`, { reply: replyText.value })
    message.success(t('回复已保存'))
    replying.value = null
    load(pagination.page)
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    savingReply.value = false
  }
}

async function saveSettings() {
  savingSettings.value = true
  try {
    const { data } = await http.put('/reviews/settings', settings)
    Object.assign(settings, data.settings)
    settingsOpen.value = false
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    savingSettings.value = false
  }
}

// CSV import
const importOpen = ref(false)
const importing = ref(false)
const importResult = ref<{ imported: number; error_count: number; errors: { line: number; message: string }[] } | null>(null)
async function onImport({ file }: { file: UploadFileInfo }) {
  if (!file.file) return false
  importing.value = true
  importResult.value = null
  try {
    const form = new FormData()
    form.append('file', file.file)
    importResult.value = (await http.post('/reviews/import', form)).data
    load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    importing.value = false
  }
  return false
}

const columns = computed<DataTableColumns<Review>>(() => [
  { type: 'selection' },
  {
    title: t('评论'), key: 'body', minWidth: 320,
    render: (r) => h('div', { class: 'rv' }, [
      h('div', { class: 'rv-top' }, [
        h('span', { class: 'stars' }, stars(r.rating)),
        h('strong', r.author),
        r.verified ? h(NTag, { size: 'tiny', bordered: false, type: 'success' }, () => t('已购买')) : null,
        r.source === 'import' ? h(NTag, { size: 'tiny', bordered: false }, () => t('导入')) : null,
      ]),
      r.title ? h('div', { class: 'rv-title' }, r.title) : null,
      h('div', { class: 'rv-body' }, r.body),
      r.images.length ? h('div', { class: 'rv-imgs' }, r.images.map((u) => h('a', { href: u, target: '_blank', rel: 'noopener' }, [h('img', { src: u, alt: '' })]))) : null,
      r.reply ? h('div', { class: 'rv-reply' }, [h('strong', t('你的回复：')), r.reply]) : null,
    ]),
  },
  {
    title: t('商品'), key: 'product', width: 180,
    render: (r) => r.product ? h(RouterLink, { to: { name: 'product-edit', params: { id: r.product.id } }, class: 'plink' }, () => r.product!.title) : '—',
  },
  { title: t('状态'), key: 'status', width: 96, render: (r) => h(NTag, { size: 'small', bordered: false, type: statusMeta.value[r.status].type }, () => statusMeta.value[r.status].label) },
  { title: t('时间'), key: 'created_at', width: 150, render: (r) => formatDate(r.created_at) },
  {
    title: '', key: 'actions', width: 210,
    render: (r) => h(NSpace, { size: 4 }, () => [
      r.status !== 'published' ? h(NButton, { size: 'tiny', type: 'primary', onClick: () => setStatus(r, 'published') }, () => t('发布')) : h(NButton, { size: 'tiny', onClick: () => setStatus(r, 'hidden') }, () => t('隐藏')),
      h(NButton, { size: 'tiny', secondary: true, onClick: () => openReply(r) }, () => (r.reply ? t('改回复') : t('回复'))),
      h(NButton, { size: 'tiny', quaternary: true, type: 'error', onClick: () => remove(r) }, () => t('删除')),
    ]),
  },
])

onMounted(() => load())
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Products</div>
        <h1 class="page-title">{{ t('评论') }}</h1>
        <p class="page-sub">{{ t('买家在商品页写的评论。已购买过的买家会显示「已购买」标记。') }}</p>
      </div>
      <NSpace>
        <NButton @click="importOpen = true">{{ t('导入评论') }}</NButton>
        <NButton @click="settingsOpen = true">{{ t('评论设置') }}</NButton>
      </NSpace>
    </div>

    <NAlert v-if="!settings.enabled" type="warning" :bordered="false">{{ t('评论功能已关闭，商品页不显示评论和评论表单。') }}</NAlert>

    <NCard content-style="padding: 0">
      <div class="toolbar">
        <NTabs v-model:value="tab" type="line" size="small" style="flex: 1">
          <NTab name="pending">{{ t('待审核') }} <span class="count">{{ counts.pending ?? 0 }}</span></NTab>
          <NTab name="published">{{ t('已发布') }} <span class="count">{{ counts.published ?? 0 }}</span></NTab>
          <NTab name="hidden">{{ t('已隐藏') }}</NTab>
          <NTab name="all">{{ t('全部') }}</NTab>
        </NTabs>
        <NSelect v-model:value="rating" :options="ratingOptions" clearable :placeholder="t('评分')" size="small" style="width: 120px" />
        <NInput v-model:value="q" :placeholder="t('搜索评论人、邮箱或内容')" clearable size="small" style="width: 220px" />
      </div>
      <div v-if="checked.length" class="bulk">
        <span>{{ t('已选 {0} 条', [checked.length]) }}</span>
        <NButton size="small" type="primary" @click="bulk('publish')">{{ t('发布') }}</NButton>
        <NButton size="small" @click="bulk('hide')">{{ t('隐藏') }}</NButton>
        <NButton size="small" quaternary type="error" @click="bulk('delete')">{{ t('删除') }}</NButton>
      </div>
      <NDataTable
        v-model:checked-row-keys="checked"
        remote
        :columns="columns"
        :data="rows"
        :row-key="(r: Review) => r.id"
        :loading="loading"
        :bordered="false"
        :pagination="pagination"
        :scroll-x="980"
        @update:page="load"
      />
    </NCard>

    <NModal :show="!!replying" preset="card" :title="t('回复评论')" style="max-width: 560px" @update:show="(v: boolean) => { if (!v) replying = null }">
      <p v-if="replying" class="quote">“{{ replying.body }}” — {{ replying.author }}</p>
      <NInput v-model:value="replyText" type="textarea" :autosize="{ minRows: 4, maxRows: 10 }" maxlength="2000" show-count :placeholder="t('回复会公开显示在评论下方，留空则删除回复')" />
      <template #footer>
        <NSpace justify="end">
          <NButton @click="replying = null">{{ t('取消') }}</NButton>
          <NButton type="primary" :loading="savingReply" @click="saveReply">{{ t('保存') }}</NButton>
        </NSpace>
      </template>
    </NModal>

    <NModal v-model:show="settingsOpen" preset="card" :title="t('评论设置')" style="max-width: 520px">
      <div class="settings">
        <div class="row"><NSwitch v-model:value="settings.enabled" /><span>{{ t('在商品页显示评论和评论表单') }}</span></div>
        <div>
          <div class="label">{{ t('新评论自动发布') }}</div>
          <NRadioGroup v-model:value="settings.auto_publish">
            <div class="radios">
              <NRadio value="verified">{{ t('只自动发布已购买买家的评论（推荐）') }}</NRadio>
              <NRadio value="all">{{ t('全部自动发布') }}</NRadio>
              <NRadio value="none">{{ t('全部人工审核') }}</NRadio>
            </div>
          </NRadioGroup>
        </div>
        <div>
          <div class="label">{{ t('邀请评价邮件') }}</div>
          <div class="row">
            <span>{{ t('发货多久后发送') }}</span>
            <NSelect v-model:value="settings.request_delay_days" :options="[3, 7, 14, 30].map((d) => ({ value: d, label: t('{0} 天', [d]) }))" style="width: 110px" />
          </div>
          <p class="muted" style="margin: 8px 0 0">
            {{ t('每个订单只发一次，买家已经评价过的商品不会再邀请。可以在「设置 → 邮件通知」里预览或关闭这封邮件。') }}
          </p>
        </div>
      </div>
      <template #footer>
        <NSpace justify="end"><NButton type="primary" :loading="savingSettings" @click="saveSettings">{{ t('保存') }}</NButton></NSpace>
      </template>
    </NModal>

    <NModal v-model:show="importOpen" preset="card" :title="t('导入评论')" style="max-width: 600px">
      <p class="muted">{{ t('上传 CSV 文件（UTF-8）。第一行是列名，必填列：product_handle（商品链接名）、rating（1–5）、author、body；可选列：email、title、created_at、images（图片网址，用空格分隔）、verified（yes/no）、reply。导入的评论直接发布。') }}</p>
      <NUpload :max="1" accept=".csv,text/csv" :show-file-list="false" :custom-request="() => {}" @before-upload="onImport">
        <NButton :loading="importing">{{ t('选择 CSV 文件') }}</NButton>
      </NUpload>
      <NAlert v-if="importResult" :type="importResult.error_count ? 'warning' : 'success'" :bordered="false" style="margin-top: 12px">
        {{ t('已导入 {0} 条评论', [importResult.imported]) }}<template v-if="importResult.error_count">{{ t('，{0} 行有问题：', [importResult.error_count]) }}</template>
        <ul v-if="importResult.errors.length" class="errs">
          <li v-for="e in importResult.errors.slice(0, 20)" :key="e.line">{{ t('第 {0} 行：{1}', [e.line, e.message]) }}</li>
        </ul>
      </NAlert>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; }
.toolbar { display: flex; align-items: center; gap: 12px; padding: 8px 16px 0; flex-wrap: wrap; }
.count { color: var(--ink-muted); font-size: 12px; margin-left: 2px; }
.bulk { display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: var(--surface-muted); font-size: 13px; }
:deep(.rv) { display: grid; gap: 4px; font-size: 13px; line-height: 1.6; }
:deep(.rv-top) { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
:deep(.stars) { color: var(--brand-600, #0E8A5F); letter-spacing: 1px; }
:deep(.rv-title) { font-weight: 600; }
:deep(.rv-body) { white-space: pre-line; color: var(--ink); }
:deep(.rv-imgs) { display: flex; gap: 6px; margin-top: 4px; }
:deep(.rv-imgs img) { width: 48px; height: 48px; object-fit: cover; border-radius: 6px; display: block; }
:deep(.rv-reply) { margin-top: 4px; padding: 6px 10px; background: var(--surface-muted); border-radius: 8px; }
:deep(.plink) { color: inherit; }
.quote { color: var(--ink-muted); font-size: 13px; margin: 0 0 12px; white-space: pre-line; }
.settings { display: grid; gap: 16px; }
.row { display: flex; gap: 10px; align-items: center; }
.label { font-size: 13px; margin-bottom: 8px; }
.radios { display: grid; gap: 8px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0 0 12px; }
.errs { margin: 8px 0 0; padding-left: 18px; }
</style>
