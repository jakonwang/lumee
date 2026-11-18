<script setup lang="ts">
/**
 * Theme library: the live theme (big card with a live thumbnail) and saved themes underneath.
 * Add from a template, duplicate, rename, export, import a Lumée package or convert a Shopify theme.
 * Nothing here changes the live store except "发布".
 */
import { computed, h, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  NButton, NCard, NDropdown, NEmpty, NIcon, NInput, NModal, NRadioGroup, NRadio, NSkeleton, NTag, NAlert, NSpin,
  useDialog, useMessage,
} from 'naive-ui'
import { AddOutline, CloudUploadOutline, EllipsisHorizontal, OpenOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import type { ImportReport, LibraryTheme, ThemeInfo } from '@/api/theme'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

const router = useRouter()
const message = useMessage()
const dialog = useDialog()

const themes = ref<LibraryTheme[]>([])
const templates = ref<ThemeInfo[]>([])
const max = ref(20)
const loading = ref(true)
async function load() {
  const { data } = await http.get('/themes')
  themes.value = data.data
  templates.value = data.templates
  max.value = data.max
  loading.value = false
}
onMounted(load)

const live = computed(() => themes.value.find((x) => x.live) ?? null)
const library = computed(() => themes.value.filter((x) => !x.live))
const full = computed(() => themes.value.length >= max.value)
const storefront = computed(() => live.value?.preview_url.split('/?')[0] ?? '')

const sourceLabel: Record<string, string> = { copy: t('副本'), import: t('导入'), shopify: 'Shopify', market: t('主题市场') }

// Marketplace: themes from developers, reviewed by Lumée
interface MarketTheme { id: number; name: string; tagline: string | null; description: string | null; preview_url: string | null; demo_url: string | null; template: string; swatches: string[]; installs: number; developer: string | null; installed: boolean; price: string; purchased: boolean }
const market = ref<MarketTheme[]>([])
const installing = ref<number | null>(null)
async function loadMarket() {
  try {
    market.value = (await http.get('/themes/market')).data.data
  } catch {
    market.value = []
  }
}
function installMarket(m: MarketTheme) {
  if (Number(m.price) > 0 && !m.purchased) {
    dialog.info({
      title: t('购买主题「{0}」', [m.name]),
      content: t('一次性收费 US${0}，计入你的 Lumée 平台账单。购买后可以永久使用，以后重新添加不再收费。', [m.price]),
      positiveText: t('购买并添加'), negativeText: t('取消'),
      onPositiveClick: () => doInstall(m, m.price),
    })
    return
  }
  doInstall(m)
}
async function doInstall(m: MarketTheme, confirmPrice?: string) {
  installing.value = m.id
  try {
    await http.post(`/themes/market/${m.id}/install`, confirmPrice ? { confirm_price: confirmPrice } : {})
    message.success(t('已添加到主题库，可以先自定义再发布'))
    await Promise.all([load(), loadMarket()])
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    installing.value = null
  }
}
onMounted(loadMarket)

function openPreview(url: string) {
  window.open(url, '_blank', 'noopener')
}

// The live thumbnail renders the storefront at 1280px wide, scaled down to the card
const thumb = ref<HTMLElement>()
const scale = ref(0.45)
const ro = new ResizeObserver(([e]) => { if (e) scale.value = e.contentRect.width / 1280 })
watch(thumb, (el, old) => { if (old) ro.unobserve(old); if (el) ro.observe(el) })
onBeforeUnmount(() => ro.disconnect())

function edit(x: LibraryTheme) {
  router.push({ name: 'theme-editor', params: { id: x.id } })
}

// ---- Row actions ----
function menu(x: LibraryTheme) {
  return [
    { key: 'preview', label: t('预览') },
    { key: 'copy', label: t('复制'), disabled: full.value },
    { key: 'rename', label: t('重命名') },
    { key: 'export', label: t('导出主题包') },
    ...(x.live ? [] : [{ type: 'divider', key: 'd' }, { key: 'delete', label: () => h('span', { style: 'color: var(--danger)' }, t('删除')) }]),
  ]
}
function onMenu(key: string, x: LibraryTheme) {
  if (key === 'preview') window.open(x.preview_url, '_blank', 'noopener')
  if (key === 'copy') copy(x)
  if (key === 'rename') { renaming.value = x; newName.value = x.name }
  if (key === 'export') exportTheme(x)
  if (key === 'delete') remove(x)
}

async function copy(x: LibraryTheme) {
  try {
    await http.post('/themes', { copy_of: x.id })
    message.success(t('已复制到主题库'))
    load()
  } catch (e) { message.error(errorMessage(e)) }
}

const renaming = ref<LibraryTheme | null>(null)
const newName = ref('')
async function saveName() {
  if (!renaming.value || !newName.value.trim()) return
  try {
    await http.patch(`/themes/${renaming.value.id}`, { name: newName.value.trim() })
    renaming.value = null
    load()
  } catch (e) { message.error(errorMessage(e)) }
}

function remove(x: LibraryTheme) {
  dialog.warning({
    title: t('删除「{0}」？', [x.name]),
    content: t('删除后无法恢复。建议先导出主题包备份。'),
    positiveText: t('删除'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        await http.delete(`/themes/${x.id}`)
        message.success(t('已删除'))
        load()
      } catch (e) { message.error(errorMessage(e)) }
    },
  })
}

function publish(x: LibraryTheme) {
  dialog.info({
    title: t('发布「{0}」？', [x.name]),
    content: t('发布后，店铺会立即换成这个主题。当前使用的主题会保留在主题库里，随时可以切换回去。'),
    positiveText: t('发布'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        await http.post('/theme/publish', { id: x.id })
        message.success(t('已发布「{0}」', [x.name]))
        load()
      } catch (e) { message.error(errorMessage(e)) }
    },
  })
}

async function exportTheme(x: LibraryTheme) {
  try {
    const res = await http.get(`/themes/${x.id}/export`, { responseType: 'blob' })
    const name = /filename="?([^";]+)"?/.exec(res.headers['content-disposition'] ?? '')?.[1] ?? 'theme.zip'
    const url = URL.createObjectURL(res.data as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = name
    a.click()
    URL.revokeObjectURL(url)
  } catch (e) { message.error(errorMessage(e)) }
}

// ---- Add from a template ----
const showTemplates = ref(false)
async function addTemplate(key: string) {
  try {
    const { data } = await http.post('/themes', { theme: key })
    showTemplates.value = false
    message.success(t('已添加到主题库'))
    await load()
    edit(data.data)
  } catch (e) { message.error(errorMessage(e)) }
}

// ---- Import ----
const importKind = ref<'lumee' | 'shopify' | null>(null)
const base = ref('fresh')
const file = ref<File | null>(null)
const importing = ref(false)
const report = ref<{ theme: LibraryTheme; report: ImportReport } | null>(null)
const fileInput = ref<HTMLInputElement>()
function openImport(kind: 'lumee' | 'shopify') {
  importKind.value = kind
  file.value = null
  report.value = null
}
function onPick(e: Event) {
  file.value = (e.target as HTMLInputElement).files?.[0] ?? null
}
function onDrop(e: DragEvent) {
  file.value = e.dataTransfer?.files?.[0] ?? null
}
async function runImport() {
  if (!file.value) return
  importing.value = true
  const form = new FormData()
  form.append('file', file.value)
  if (importKind.value === 'shopify') form.append('base', base.value)
  try {
    const { data } = await http.post('/themes/import', form)
    report.value = { theme: data.data, report: data.report }
    load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    importing.value = false
  }
}

const sectionLabels: Record<string, string> = {
  hero: t('首屏大图'), featured_products: t('精选商品'), collection_list: t('分类展示'), image_text: t('图文介绍'),
  rich_text: t('文字段落'), newsletter: t('邮件订阅'), features: t('服务亮点（三栏）'), faq: t('常见问题（折叠）'),
  video: t('视频'), testimonials: t('顾客评价'),
}
const converted = computed(() => report.value?.report.sections.filter((s) => s.to) ?? [])
const skipped = computed(() => report.value?.report.sections.filter((s) => !s.to) ?? [])

const addOptions = computed(() => [
  { key: 'template', label: t('从模板添加'), disabled: full.value },
  { key: 'lumee', label: t('导入主题包（.zip）'), disabled: full.value },
  { key: 'shopify', label: t('从 Shopify 主题导入'), disabled: full.value },
])
function onAdd(key: string) {
  if (key === 'template') showTemplates.value = true
  else openImport(key as 'lumee' | 'shopify')
}
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Online Store</div>
        <h1 class="page-title">{{ t('主题') }}</h1>
        <p class="page-sub">{{ t('管理店铺的外观。主题库里的主题可以随意编辑和预览，发布后才会对买家生效。') }}</p>
      </div>
      <div class="actions">
        <a v-if="storefront" :href="storefront" target="_blank" class="plain">
          <NButton secondary><template #icon><NIcon><OpenOutline /></NIcon></template>{{ t('查看店铺') }}</NButton>
        </a>
        <NDropdown :options="addOptions" trigger="click" @select="onAdd">
          <NButton type="primary"><template #icon><NIcon><AddOutline /></NIcon></template>{{ t('添加主题') }}</NButton>
        </NDropdown>
      </div>
    </div>

    <!-- Live theme -->
    <NCard v-if="loading" size="small"><NSkeleton height="260px" /></NCard>
    <NCard v-else-if="live" class="live-card" content-style="padding: 0">
      <div class="live">
        <div ref="thumb" class="thumb" aria-hidden="true" :style="{ '--s': scale }">
          <iframe :src="live.preview_url" tabindex="-1" loading="lazy" scrolling="no" :title="t('店铺预览')" />
        </div>
        <div class="live-info">
          <div class="live-top">
            <NTag type="success" size="small" :bordered="false">{{ t('使用中') }}</NTag>
            <NTag v-if="live.has_unpublished_changes" size="small" :bordered="false" type="warning">{{ t('有未发布的修改') }}</NTag>
          </div>
          <h2 class="live-name">{{ live.name }}</h2>
          <div class="meta">{{ t('基于 {0} 模板', [live.template]) }} · {{ t('{0} 个首页模块', [live.sections]) }}</div>
          <div class="meta" v-if="live.published_at">{{ t('上次发布 {0}', [formatDate(live.published_at)]) }}</div>
          <div class="swatches"><span v-for="c in live.swatches" :key="c" :style="{ background: c }" :title="c" /></div>
          <div class="live-actions">
            <NButton type="primary" @click="edit(live)">{{ t('自定义') }}</NButton>
            <NButton secondary @click="openPreview(live.preview_url)">{{ t('预览') }}</NButton>
            <NDropdown :options="menu(live)" trigger="click" @select="(k: string) => onMenu(k, live!)">
              <NButton secondary :aria-label="t('更多操作')"><template #icon><NIcon><EllipsisHorizontal /></NIcon></template></NButton>
            </NDropdown>
          </div>
        </div>
      </div>
    </NCard>

    <!-- Library -->
    <NCard size="small" content-style="padding: 0">
      <template #header>
        {{ t('主题库') }} <span class="count">{{ themes.length }} / {{ max }}</span>
      </template>
      <template #header-extra><span class="muted">{{ t('未发布的主题只有你能看到') }}</span></template>
      <div v-if="!loading && !library.length" class="empty">
        <NEmpty :description="t('主题库里还没有其他主题')" size="small">
          <template #extra>
            <div class="empty-actions">
              <NButton size="small" @click="showTemplates = true">{{ t('从模板添加') }}</NButton>
              <NButton size="small" @click="openImport('shopify')">{{ t('从 Shopify 主题导入') }}</NButton>
            </div>
          </template>
        </NEmpty>
      </div>
      <div v-for="x in library" :key="x.id" class="row">
        <div class="mini-swatch"><span v-for="c in x.swatches" :key="c" :style="{ background: c }" /></div>
        <div class="row-main">
          <div class="row-name">{{ x.name }}
            <NTag v-if="sourceLabel[x.source]" size="tiny" :bordered="false">{{ sourceLabel[x.source] }}</NTag>
          </div>
          <div class="meta">{{ t('基于 {0} 模板', [x.template]) }} · {{ t('最近编辑 {0}', [formatDate(x.updated_at)]) }}</div>
        </div>
        <div class="row-actions">
          <NButton size="small" secondary @click="publish(x)">{{ t('发布') }}</NButton>
          <NButton size="small" @click="edit(x)">{{ t('自定义') }}</NButton>
          <NDropdown :options="menu(x)" trigger="click" @select="(k: string) => onMenu(k, x)">
            <NButton size="small" quaternary :aria-label="t('更多操作')"><template #icon><NIcon><EllipsisHorizontal /></NIcon></template></NButton>
          </NDropdown>
        </div>
      </div>
    </NCard>

    <!-- Marketplace -->
    <NCard v-if="market.length" size="small" :title="t('主题市场')">
      <template #header-extra><span class="muted">{{ t('第三方设计师的主题，经 Lumée 审核') }}</span></template>
      <div class="market">
        <div v-for="m in market" :key="m.id" class="mk">
          <div class="mk-shot">
            <img v-if="m.preview_url" :src="m.preview_url" alt="" loading="lazy" />
            <div v-else class="tpl-swatches"><span v-for="c in m.swatches" :key="c" :style="{ background: c }" /></div>
          </div>
          <div class="tpl-name">{{ m.name }}</div>
          <div class="meta">{{ m.developer }} · {{ t('{0} 家店铺安装', [m.installs]) }}</div>
          <div class="mk-price">{{ m.purchased ? t('已购买') : Number(m.price) ? `US$${m.price}` : t('免费') }}</div>
          <div v-if="m.tagline" class="meta">{{ m.tagline }}</div>
          <div class="mk-actions">
            <a v-if="m.demo_url" :href="m.demo_url" target="_blank" rel="noopener" class="plain"><NButton size="small" quaternary>{{ t('看演示') }}</NButton></a>
            <NButton size="small" type="primary" secondary :disabled="full" :loading="installing === m.id" @click="installMarket(m)">
              {{ m.installed ? t('再添加一份') : t('添加到主题库') }}
            </NButton>
          </div>
        </div>
      </div>
    </NCard>

    <!-- Template gallery -->
    <NModal v-model:show="showTemplates" preset="card" :title="t('从模板添加')" style="width: 640px; max-width: 94vw">
      <div class="templates">
        <div v-for="tp in templates" :key="tp.key" class="tpl">
          <div class="tpl-swatches"><span v-for="c in tp.swatches" :key="c" :style="{ background: c }" /></div>
          <div class="tpl-name">{{ tp.name }}</div>
          <div class="meta">{{ tp.description }}</div>
          <NButton size="small" type="primary" secondary block @click="addTemplate(tp.key)">{{ t('添加到主题库') }}</NButton>
        </div>
      </div>
    </NModal>

    <!-- Import -->
    <NModal :show="importKind !== null" preset="card" style="width: 600px; max-width: 94vw"
      :title="importKind === 'shopify' ? t('从 Shopify 主题导入') : t('导入主题包')" @update:show="(v: boolean) => !v && (importKind = null)">
      <template v-if="!report">
        <p class="intro" v-if="importKind === 'shopify'">
          {{ t('在 Shopify 后台「在线商店 › 主题 › 操作 › 下载主题文件」得到 .zip，上传到这里。我们会转换颜色、字体、公告栏和首页模块（横幅、精选商品、分类、图文、多栏、常见问题、视频等）的内容和设置。') }}
        </p>
        <p class="intro" v-else>{{ t('上传从 Lumée 导出的主题包（.zip），主题设置、首页模块和图片都会一起导入。分类会按 handle 匹配本店铺的分类。') }}</p>

        <label class="drop" :class="{ has: file }" @dragover.prevent @drop.prevent="onDrop">
          <input ref="fileInput" type="file" accept=".zip,.json" class="sr" @change="onPick">
          <NIcon size="28" class="drop-ico"><CloudUploadOutline /></NIcon>
          <span v-if="file" class="drop-file">{{ file.name }} · {{ (file.size / 1024 / 1024).toFixed(1) }} MB</span>
          <span v-else>{{ t('点击选择文件或拖到这里（.zip / .json，最大 50MB）') }}</span>
        </label>

        <div v-if="importKind === 'shopify'" class="base">
          <div class="base-label">{{ t('转换到哪个模板') }}</div>
          <NRadioGroup v-model:value="base">
            <NRadio v-for="tp in templates" :key="tp.key" :value="tp.key">{{ tp.name }}</NRadio>
          </NRadioGroup>
        </div>
        <NAlert v-if="importKind === 'shopify'" type="info" :bordered="false" class="note">
          {{ t('Shopify 的 Liquid 代码和自定义模块无法在 Lumée 运行，不会导入；Shopify 文件库里的图片需要在编辑器里重新上传。导入的主题不会自动发布。') }}
        </NAlert>
        <div class="modal-actions">
          <NButton @click="importKind = null">{{ t('取消') }}</NButton>
          <NButton type="primary" :disabled="!file" :loading="importing" @click="runImport">{{ t('导入') }}</NButton>
        </div>
      </template>

      <!-- Result -->
      <template v-else>
        <NAlert type="success" :bordered="false">{{ t('已导入为「{0}」，已放进主题库（未发布）。', [report.theme.name]) }}</NAlert>
        <div v-if="converted.length" class="rep">
          <div class="rep-title">{{ t('已转换的首页模块（{0}）', [converted.length]) }}</div>
          <div v-for="(s, i) in converted" :key="i" class="rep-row"><code>{{ s.from }}</code><span class="arrow">→</span>{{ sectionLabels[s.to!] ?? s.to }}</div>
        </div>
        <div v-if="report.report.settings.length" class="rep">
          <div class="rep-title">{{ t('已转换的主题设置（{0}）', [report.report.settings.length]) }}</div>
          <div class="meta">{{ report.report.settings.join(' · ') }}</div>
        </div>
        <div v-if="skipped.length" class="rep">
          <div class="rep-title">{{ t('未转换（{0}）', [skipped.length]) }}</div>
          <div class="meta">{{ skipped.map((s) => s.from).join(' · ') }}</div>
        </div>
        <ul v-if="report.report.notes.length" class="notes"><li v-for="n in report.report.notes" :key="n">{{ n }}</li></ul>
        <div class="modal-actions">
          <NButton @click="importKind = null">{{ t('完成') }}</NButton>
          <NButton type="primary" @click="edit(report.theme)">{{ t('去编辑') }}</NButton>
        </div>
      </template>
      <div v-if="importing" class="busy"><NSpin size="small" /> {{ t('正在转换…') }}</div>
    </NModal>

    <NModal :show="!!renaming" preset="card" :title="t('重命名主题')" style="width: 420px; max-width: 94vw" @update:show="(v: boolean) => !v && (renaming = null)">
      <NInput v-model:value="newName" maxlength="80" show-count @keyup.enter="saveName" />
      <div class="modal-actions">
        <NButton @click="renaming = null">{{ t('取消') }}</NButton>
        <NButton type="primary" :disabled="!newName.trim()" @click="saveName">{{ t('保存') }}</NButton>
      </div>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
.head .page-sub { margin-bottom: 0; }
.actions { display: flex; gap: 8px; }
.plain { text-decoration: none; }
.muted, .meta { color: var(--ink-muted); font-size: 12px; }
.count { font-size: 12px; font-weight: 400; color: var(--ink-muted); margin-left: 6px; }

.live { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); }
.thumb { position: relative; aspect-ratio: 16 / 10; overflow: hidden; background: var(--surface-muted); border-right: 1px solid var(--border); pointer-events: none; }
.thumb iframe { position: absolute; top: 0; left: 0; width: 1280px; height: 800px; border: 0; transform: scale(var(--s, .45)); transform-origin: 0 0; }
.live-info { padding: 24px; display: flex; flex-direction: column; gap: 8px; }
.live-top { display: flex; gap: 6px; }
.live-name { font-family: var(--font-display); font-size: 24px; margin: 4px 0 0; }
.swatches { display: flex; gap: 6px; margin: 6px 0; }
.swatches span { width: 22px; height: 22px; border-radius: 50%; border: 1px solid var(--border); }
.live-actions { display: flex; gap: 8px; margin-top: auto; padding-top: 12px; }

.row { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-top: 1px solid var(--border); }
.mini-swatch { display: grid; grid-template-columns: 1fr 1fr; width: 40px; height: 40px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border); flex: none; }
.row-main { flex: 1; min-width: 0; }
.row-name { font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 6px; }
.row-actions { display: flex; gap: 6px; align-items: center; }
.empty { padding: 28px 0; border-top: 1px solid var(--border); }
.empty-actions { display: flex; gap: 8px; justify-content: center; }

.templates { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
.tpl { border: 1px solid var(--border); border-radius: 12px; padding: 14px; display: grid; gap: 8px; }
.tpl-swatches { display: grid; grid-template-columns: repeat(4, 1fr); height: 64px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border); }
.tpl-name { font-weight: 600; }

.intro { font-size: 13px; color: var(--ink-muted); line-height: 1.6; margin: 0 0 14px; }
.drop { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 28px 16px; border: 1.5px dashed var(--border); border-radius: 12px; cursor: pointer; font-size: 13px; color: var(--ink-muted); text-align: center; }
.drop:hover, .drop.has { border-color: var(--brand-300); background: var(--brand-50); }
.drop-ico { color: var(--brand-600); }
.drop-file { color: var(--ink); font-weight: 500; }
.sr { position: absolute; width: 1px; height: 1px; opacity: 0; }
.base { margin-top: 16px; }
.base-label { font-size: 13px; margin-bottom: 6px; }
.note { margin-top: 14px; }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
.busy { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--ink-muted); margin-top: 10px; }
.rep { margin-top: 14px; }
.rep-title { font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.rep-row { font-size: 13px; padding: 3px 0; }
.rep-row code { font-size: 12px; background: var(--surface-muted); padding: 1px 6px; border-radius: 4px; }
.arrow { color: var(--brand-600); margin: 0 8px; }
.notes { margin: 14px 0 0; padding-left: 18px; font-size: 12px; color: var(--ink-muted); line-height: 1.7; }
@media (max-width: 860px) {
  .live { grid-template-columns: 1fr; }
  .thumb { border-right: 0; border-bottom: 1px solid var(--border); }
  .row { flex-wrap: wrap; }
}
.market { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
.mk { border: 1px solid var(--border); border-radius: 12px; padding: 12px; display: grid; gap: 6px; align-content: start; }
.mk-shot { aspect-ratio: 16 / 10; border-radius: 8px; overflow: hidden; border: 1px solid var(--border); background: var(--surface-muted); }
.mk-shot img { width: 100%; height: 100%; object-fit: cover; }
.mk-shot .tpl-swatches { height: 100%; border: 0; border-radius: 0; }
.mk-actions { display: flex; justify-content: flex-end; gap: 6px; margin-top: 4px; }
.mk-price { font-size: 13px; font-weight: 600; color: var(--brand-700, #0A7350); }
</style>
