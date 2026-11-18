<script setup lang="ts">
/** URL redirects: old storefront paths → new address (301). Import/export CSV (Shopify format works too). */
import { h, onMounted, ref, watch } from 'vue'
import { NButton, NCard, NDataTable, NForm, NFormItem, NInput, NModal, NAlert, useDialog, useMessage, type DataTableColumns, type DataTableRowKey } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { download } from '@/utils/download'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Redirect { id: number; path: string; target: string; hits: number; last_hit_at: string | null }

const message = useMessage()
const dialog = useDialog()
const rows = ref<Redirect[]>([])
const loading = ref(false)
const q = ref('')
const pagination = ref({ page: 1, pageSize: 20, itemCount: 0 })
const checked = ref<DataTableRowKey[]>([])

const columns: DataTableColumns<Redirect> = [
  { type: 'selection' },
  { title: t('原路径'), key: 'path', minWidth: 220, render: (r) => h('code', { class: 'path' }, r.path) },
  { title: t('跳转到'), key: 'target', minWidth: 240, render: (r) => h('code', { class: 'path' }, r.target) },
  { title: t('访问次数'), key: 'hits', width: 100, align: 'right' },
  { title: t('最近访问'), key: 'last_hit_at', width: 160, render: (r) => (r.last_hit_at ? formatDate(r.last_hit_at) : '—') },
  { title: '', key: 'edit', width: 70, render: (r) => h(NButton, { size: 'tiny', quaternary: true, onClick: () => openEdit(r) }, { default: () => t('编辑') }) },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/redirects', { params: { q: q.value || undefined, page: pagination.value.page } })
    rows.value = data.data
    pagination.value.itemCount = data.total
  } finally { loading.value = false }
}
let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => { pagination.value.page = 1; load() }, 300) })
onMounted(load)

// ---- Create / edit ----
const editing = ref<Redirect | null>(null)
const show = ref(false)
const form = ref({ path: '', target: '' })
const saving = ref(false)
function openNew() { editing.value = null; form.value = { path: '', target: '' }; show.value = true }
function openEdit(r: Redirect) { editing.value = r; form.value = { path: r.path, target: r.target }; show.value = true }
async function save() {
  saving.value = true
  try {
    if (editing.value) await http.put(`/redirects/${editing.value.id}`, form.value)
    else await http.post('/redirects', form.value)
    show.value = false
    message.success(t('已保存'))
    load()
  } catch (e) { message.error(errorMessage(e)) } finally { saving.value = false }
}

function removeChecked() {
  dialog.warning({
    title: t('删除 {0} 条重定向？', [checked.value.length]),
    positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.post('/redirects/delete', { ids: checked.value })
      checked.value = []
      load()
    },
  })
}

// ---- Import / export ----
const fileInput = ref<HTMLInputElement>()
const importResult = ref<{ created: number; updated: number; errors: { line: number; message: string }[] } | null>(null)
async function onImport(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (!f) return
  const form = new FormData()
  form.append('file', f)
  try {
    importResult.value = (await http.post('/redirects/import', form)).data
    load()
  } catch (err) { message.error(errorMessage(err)) } finally { (e.target as HTMLInputElement).value = '' }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <div class="eyebrow">Online Store</div>
        <h1 class="page-title">{{ t('网址重定向') }}</h1>
        <p class="page-sub">{{ t('页面改名、商品下架或从其他平台搬家后，把旧网址自动跳转到新地址（301），不丢搜索排名。') }}</p>
      </div>
      <div class="actions">
        <input ref="fileInput" type="file" accept=".csv" class="sr" @change="onImport">
        <NButton secondary @click="fileInput?.click()">{{ t('导入 CSV') }}</NButton>
        <NButton secondary @click="download('/redirects/export', {}, 'redirects.csv')">{{ t('导出') }}</NButton>
        <NButton type="primary" @click="openNew">{{ t('添加重定向') }}</NButton>
      </div>
    </div>

    <NAlert v-if="importResult" type="success" :bordered="false" closable style="margin-bottom: 12px" @close="importResult = null">
      {{ t('导入完成：新增 {0} 条，更新 {1} 条。', [importResult.created, importResult.updated]) }}
      <template v-if="importResult.errors.length">
        {{ t('{0} 行没有导入：', [importResult.errors.length]) }}
        <span v-for="er in importResult.errors.slice(0, 5)" :key="er.line">{{ t('第 {0} 行', [er.line]) }} {{ er.message }}；</span>
      </template>
    </NAlert>

    <NCard content-style="padding: 0">
      <div class="toolbar">
        <NInput v-model:value="q" :placeholder="t('搜索路径')" clearable size="small" style="width: 260px" />
        <NButton v-if="checked.length" size="small" type="error" secondary @click="removeChecked">{{ t('删除所选（{0}）', [checked.length]) }}</NButton>
        <span class="muted">{{ t('CSV 两列：原路径、跳转到；可直接使用 Shopify 导出的重定向文件。') }}</span>
      </div>
      <NDataTable v-model:checked-row-keys="checked" remote :columns="columns" :data="rows" :loading="loading" :bordered="false"
        :pagination="pagination" :row-key="(r: Redirect) => r.id" :scroll-x="820" @update:page="(p: number) => { pagination.page = p; load() }" />
    </NCard>

    <NModal v-model:show="show" preset="card" :title="editing ? t('编辑重定向') : t('添加重定向')" style="width: 520px; max-width: 94vw">
      <NForm @submit.prevent="save">
        <NFormItem :label="t('原路径')">
          <NInput v-model:value="form.path" placeholder="/products/old-handle" />
        </NFormItem>
        <NFormItem :label="t('跳转到')">
          <NInput v-model:value="form.target" placeholder="/products/new-handle" />
        </NFormItem>
        <p class="muted">{{ t('原路径不区分大小写，网址里 ? 后面的参数会被忽略。只有原路径打不开（404）时才会跳转。') }}</p>
        <div class="modal-actions">
          <NButton @click="show = false">{{ t('取消') }}</NButton>
          <NButton type="primary" attr-type="submit" :loading="saving" :disabled="!form.path || !form.target">{{ t('保存') }}</NButton>
        </div>
      </NForm>
    </NModal>
  </div>
</template>

<style scoped>
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.actions { display: flex; gap: 8px; }
.toolbar { display: flex; gap: 12px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.muted { color: var(--ink-muted); font-size: 12px; }
.sr { display: none; }
:deep(.path) { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; word-break: break-all; }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; }
</style>
