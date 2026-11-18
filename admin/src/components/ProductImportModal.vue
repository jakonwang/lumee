<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import {
  NModal, NButton, NRadioGroup, NRadio, NAlert, NProgress, NSteps, NStep, NTag, NSpin, useMessage,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'

interface RowError { row: number; message: string; handle?: string }
interface Import {
  id: number
  filename: string
  mode: 'create' | 'overwrite'
  status: 'preview' | 'queued' | 'running' | 'done' | 'failed'
  total: number
  processed: number
  summary: {
    rows: number; products: number; variants: number; images: number; existing: number; new: number
    errors: RowError[]; error_count: number
    sample: { title: string; handle: string; variants: number; price: number; images: number; status: string }[]
  }
  results: { created: number; updated: number; skipped: number; failed: number; images_failed: number; errors: RowError[] } | null
}

const show = defineModel<boolean>('show', { required: true })
const emit = defineEmits<{ done: [] }>()
const message = useMessage()
const auth = useAuth()

const step = ref(1)
const mode = ref<'create' | 'overwrite'>('create')
const file = ref<File | null>(null)
const uploading = ref(false)
const fileErrors = ref<RowError[]>([])
const imp = ref<Import | null>(null)
const input = ref<HTMLInputElement>()
let timer: ReturnType<typeof setInterval> | undefined

const percent = computed(() => (imp.value?.total ? Math.round((imp.value.processed / imp.value.total) * 100) : 0))
const finished = computed(() => imp.value?.status === 'done' || imp.value?.status === 'failed')

watch(show, (v) => {
  if (v) reset()
  else stopPolling()
})

function reset() {
  step.value = 1
  file.value = null
  imp.value = null
  fileErrors.value = []
  mode.value = 'create'
}

function pick(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  ;(e.target as HTMLInputElement).value = ''
  if (f) file.value = f
}

function onDrop(e: DragEvent) {
  const f = e.dataTransfer?.files?.[0]
  if (f) file.value = f
}

async function download(path: string, fallbackName: string) {
  try {
    const res = await http.get(path, { responseType: 'blob' })
    const name = /filename="?([^";]+)"?/.exec(res.headers['content-disposition'] ?? '')?.[1] ?? fallbackName
    const url = URL.createObjectURL(res.data)
    const a = Object.assign(document.createElement('a'), { href: url, download: name })
    a.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    message.error(errorMessage(e))
  }
}

async function upload() {
  if (!file.value) return
  uploading.value = true
  fileErrors.value = []
  try {
    const body = new FormData()
    body.append('file', file.value)
    body.append('mode', mode.value)
    const { data } = await http.post('/products/import', body)
    imp.value = data.data
    step.value = 2
  } catch (e) {
    const err = e as { response?: { data?: { errors_list?: RowError[] } } }
    fileErrors.value = err.response?.data?.errors_list ?? []
    message.error(errorMessage(e))
  } finally {
    uploading.value = false
  }
}

async function start() {
  if (!imp.value) return
  try {
    imp.value = (await http.post(`/products/import/${imp.value.id}/start`)).data.data
    step.value = 3
    timer = setInterval(poll, 1500)
  } catch (e) {
    message.error(errorMessage(e))
  }
}

async function poll() {
  if (!imp.value) return
  try {
    imp.value = (await http.get(`/products/import/${imp.value.id}`)).data.data
    if (finished.value) {
      stopPolling()
      emit('done')
    }
  } catch {
    /* keep polling; transient errors are fine */
  }
}

function stopPolling() {
  clearInterval(timer)
  timer = undefined
}
onBeforeUnmount(stopPolling)
</script>

<template>
  <NModal v-model:show="show" preset="card" :title="t('批量导入商品')" style="width: min(720px, 94vw)" :mask-closable="!uploading">
    <NSteps :current="step" size="small" style="margin-bottom: 20px">
      <NStep :title="t('上传文件')" />
      <NStep :title="t('确认')" />
      <NStep :title="t('导入')" />
    </NSteps>

    <!-- Step 1: upload -->
    <template v-if="step === 1">
      <p class="lead">
        {{ t('使用 CSV 文件批量添加或更新商品，支持多规格和图片链接。格式与 Shopify 导出的 CSV 相同，可以直接导入。') }}
        <a href="#" @click.prevent="download('/products/import/template', 'lumee-products-template.csv')">{{ t('下载模板') }}</a>
      </p>

      <div class="drop" :class="{ has: file }" @click="input?.click()" @dragover.prevent @drop.prevent="onDrop">
        <template v-if="file">
          <strong>{{ file.name }}</strong>
          <span class="muted">{{ t('{0} KB · 点击更换', [(file.size / 1024).toFixed(1)]) }}</span>
        </template>
        <template v-else>
          <strong>{{ t('点击选择或拖入 CSV 文件') }}</strong>
          <span class="muted">{{ t('最大 5MB，最多 1000 个商品；Excel 文件请先「另存为 → CSV」') }}</span>
        </template>
      </div>
      <input ref="input" type="file" accept=".csv,text/csv" hidden @change="pick" />

      <div class="mode">
        <div class="mode-title">{{ t('遇到网址（Handle）相同的已有商品时') }}</div>
        <NRadioGroup v-model:value="mode">
          <NRadio value="create">{{ t('跳过，不修改已有商品') }}</NRadio>
          <NRadio value="overwrite">{{ t('用文件内容覆盖已有商品') }}</NRadio>
        </NRadioGroup>
      </div>

      <NAlert v-if="fileErrors.length" type="error" :bordered="false" :title="t('文件中没有可导入的商品')" style="margin-top: 16px">
        <ul class="errs"><li v-for="(e, i) in fileErrors.slice(0, 10)" :key="i">{{ t('第 {0} 行：{1}', [e.row, e.message]) }}</li></ul>
      </NAlert>

      <div class="foot">
        <NButton type="primary" :disabled="!file" :loading="uploading" @click="upload">{{ t('检查文件') }}</NButton>
      </div>
    </template>

    <!-- Step 2: preview -->
    <template v-else-if="step === 2 && imp">
      <div class="stats">
        <div><b>{{ imp.summary.products }}</b><span>{{ t('个商品') }}</span></div>
        <div><b>{{ imp.summary.variants }}</b><span>{{ t('个 SKU') }}</span></div>
        <div><b>{{ imp.summary.images }}</b><span>{{ t('张图片') }}</span></div>
        <div><b>{{ imp.summary.new }}</b><span>{{ t('新商品') }}</span></div>
        <div><b>{{ imp.summary.existing }}</b><span>{{ imp.mode === 'overwrite' ? t('将覆盖') : t('已存在·跳过') }}</span></div>
      </div>

      <NAlert v-if="imp.summary.error_count" type="warning" :bordered="false" :title="t('发现 {0} 处问题，相关行会被跳过', [imp.summary.error_count])" style="margin-bottom: 12px">
        <ul class="errs"><li v-for="(e, i) in imp.summary.errors.slice(0, 20)" :key="i">{{ t('第 {0} 行：{1}', [e.row, e.message]) }}</li></ul>
        <p v-if="imp.summary.error_count > 20" class="muted">{{ t('…另有 {0} 条', [imp.summary.error_count - 20]) }}</p>
      </NAlert>

      <table class="tbl">
        <thead><tr><th>{{ t('商品') }}</th><th>SKU</th><th>{{ t('起价') }}</th><th>{{ t('图片') }}</th><th>{{ t('状态') }}</th></tr></thead>
        <tbody>
          <tr v-for="p in imp.summary.sample" :key="p.handle">
            <td><div>{{ p.title }}</div><div class="muted">/{{ p.handle }}</div></td>
            <td>{{ p.variants }}</td>
            <td class="num">{{ auth.currentStore?.currency }} {{ p.price.toFixed(2) }}</td>
            <td>{{ p.images }}</td>
            <td><NTag size="tiny" :bordered="false" :type="p.status === 'active' ? 'success' : 'default'">{{ p.status === 'active' ? t('在售') : p.status === 'draft' ? t('草稿') : t('已归档') }}</NTag></td>
          </tr>
        </tbody>
      </table>
      <p v-if="imp.summary.products > imp.summary.sample.length" class="muted">{{ t('仅显示前 {0} 个商品', [imp.summary.sample.length]) }}</p>

      <div class="foot">
        <NButton @click="step = 1">{{ t('重新选择文件') }}</NButton>
        <NButton type="primary" @click="start">{{ t('开始导入 {0} 个商品', [imp.summary.products]) }}</NButton>
      </div>
    </template>

    <!-- Step 3: progress + results -->
    <template v-else-if="step === 3 && imp">
      <div v-if="!finished" class="progress">
        <NProgress type="line" :percentage="percent" :show-indicator="true" />
        <p class="muted">
          <template v-if="imp.status === 'queued'"><NSpin :size="12" /> {{ t('排队中… 如果一直没有开始，请确认后台队列在运行（php artisan queue:work）。') }}</template>
          <template v-else>{{ t('正在导入 {0} / {1}，下载图片可能需要一些时间。可以关闭此窗口，导入会在后台继续。', [imp.processed, imp.total]) }}</template>
        </p>
      </div>

      <template v-else-if="imp.results">
        <NAlert :type="imp.status === 'failed' || imp.results.failed ? 'warning' : 'success'" :bordered="false"
          :title="imp.status === 'failed' ? t('导入中断') : t('导入完成')" style="margin-bottom: 12px">
          {{ t('新增 {0} 个，更新 {1} 个，跳过 {2} 个，失败 {3} 个', [imp.results.created ?? 0, imp.results.updated ?? 0, imp.results.skipped ?? 0, imp.results.failed ?? 0]) }}
          <template v-if="imp.results.images_failed">{{ t('；{0} 张图片下载失败', [imp.results.images_failed]) }}</template>
        </NAlert>
        <div v-if="imp.results.errors?.length" class="err-box">
          <div v-for="(e, i) in imp.results.errors" :key="i" class="err-row">
            <span class="muted">{{ t('第 {0} 行', [e.row]) }}</span> {{ e.message }}
          </div>
        </div>
      </template>

      <div class="foot">
        <NButton v-if="finished" type="primary" @click="show = false">{{ t('完成') }}</NButton>
        <NButton v-else @click="show = false">{{ t('后台继续，关闭窗口') }}</NButton>
      </div>
    </template>
  </NModal>
</template>

<style scoped>
.lead { font-size: 14px; color: var(--ink-muted); margin: 0 0 16px; line-height: 1.7; }
.lead a { color: var(--brand-700); }
.drop {
  border: 1.5px dashed var(--brand-300); border-radius: 10px; background: var(--brand-50);
  padding: 28px 16px; text-align: center; cursor: pointer; display: grid; gap: 4px;
}
.drop:hover { background: var(--brand-100); }
.drop.has { border-style: solid; }
.mode { margin-top: 18px; }
.mode-title { font-size: 13px; margin-bottom: 8px; }
.muted { color: var(--ink-muted); font-size: 12px; }
.foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.errs { margin: 6px 0 0; padding-left: 18px; font-size: 13px; }
.stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-bottom: 16px; }
.stats div { background: var(--surface-muted); border-radius: 8px; padding: 10px; text-align: center; }
.stats b { display: block; font-size: 20px; font-family: var(--font-display); }
.stats span { font-size: 12px; color: var(--ink-muted); }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { text-align: left; font-weight: 500; color: var(--ink-muted); padding: 8px; background: var(--surface-muted); }
.tbl td { padding: 8px; border-top: 1px solid var(--border); vertical-align: top; }
.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.progress { display: grid; gap: 10px; padding: 12px 0; }
.err-box { max-height: 240px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; padding: 4px 12px; font-size: 13px; }
.err-row { padding: 6px 0; }
.err-row + .err-row { border-top: 1px solid var(--border); }
@media (max-width: 560px) { .stats { grid-template-columns: repeat(3, 1fr); } }
</style>
