<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import {
  NButton, NIcon, NTabs, NTabPane, NPopover, NCollapse, NCollapseItem, NTooltip, NSpin, NTag,
  useMessage, useDialog,
} from 'naive-ui'
import {
  AddOutline, ArrowBackOutline, EyeOutline, EyeOffOutline, TrashOutline, ReorderTwoOutline,
  DesktopOutline, PhonePortraitOutline, OpenOutline, ChevronForwardOutline, CopyOutline, ArrowUndoOutline, ArrowRedoOutline,
} from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import FieldInput from '@/components/theme/FieldInput.vue'
import { type ThemeConfig, type ThemeResponse, type ThemeSchema, type Section, newSection } from '@/api/theme'
import { t } from '@/i18n'

const message = useMessage()
const route = useRoute()
const router = useRouter()
const dialog = useDialog()

const loading = ref(true)
const schema = ref<ThemeSchema | null>(null)
const config = ref<ThemeConfig | null>(null)
const mediaUrls = ref<Record<number, string>>({})
const previewUrl = ref('')
const storefrontUrl = ref('')
const hasUnpublished = ref(false)
// Library theme being edited (may not be the live one until it's published)
const themeId = computed(() => Number(route.params.id))
const themeName = ref('')
const isActive = ref(false)
const tab = ref<'sections' | 'settings'>('sections')
const selectedId = ref<string | null>(null)
const device = ref<'desktop' | 'mobile'>('desktop')

// Save status: idle → pending (typing) → saving → saved
const saveState = ref<'saved' | 'pending' | 'saving' | 'error'>('saved')
const publishing = ref(false)
const frameKey = ref(0)
const frameLoading = ref(true)
const iframe = ref<HTMLIFrameElement>()

const selected = computed(() => config.value?.sections.find((s) => s.id === selectedId.value) ?? null)
const selectedDef = computed(() => (selected.value && schema.value ? schema.value.sections[selected.value.type] : null))
const previewOrigin = computed(() => { try { return new URL(previewUrl.value).origin } catch { return '' } })

const sectionHints: Record<string, string> = {
  hero: t('大图 + 标题 + 按钮，适合放在首页顶部'),
  featured_products: t('展示某个分类或最新的商品'),
  collection_list: t('用图片卡片展示几个分类'),
  image_text: t('一张图片配一段文字，讲品牌或卖点'),
  rich_text: t('一段居中或左对齐的文字'),
  newsletter: t('收集顾客邮箱，用于邮件营销'),
  features: t('三栏图标文字：包邮、退换、安全支付等'),
  faq: t('可折叠的常见问题，减少售前咨询'),
  video: t('嵌入 YouTube 或 Vimeo 视频'),
  testimonials: t('展示三条顾客好评'),
}
const canAdd = (type: string) => {
  if (!schema.value || !config.value) return false
  const def = schema.value.sections[type]
  const count = config.value.sections.filter((s) => s.type === type).length
  return config.value.sections.length < schema.value.max_sections && (def?.max_per_page === undefined || count < def.max_per_page)
}
const addOptions = computed(() => Object.entries(schema.value?.sections ?? {}).map(([key, def]) => ({
  key, label: def.label, hint: sectionHints[key] ?? '', disabled: !canAdd(key),
})))
const addOpen = ref(false)

function apply(data: ThemeResponse) {
  themeName.value = data.name
  isActive.value = data.live
  schema.value = data.schema
  config.value = data.config
  previewUrl.value = data.preview_url
  storefrontUrl.value = data.storefront_url
  hasUnpublished.value = data.has_unpublished_changes
  data.media.forEach((m) => { mediaUrls.value[m.id] = m.url })
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get<ThemeResponse>('/theme', { params: { id: themeId.value } })
    apply(data)
    resetHistory()
    await nextTick()
    saveState.value = 'saved'
  } catch (e) {
    message.error(errorMessage(e))
    router.replace({ name: 'online-store' })
  } finally {
    loading.value = false
  }
}

// ── Autosave draft (debounced) and refresh the preview ──
let timer: ReturnType<typeof setTimeout> | undefined
let skipNext = false
watch(config, () => {
  if (skipNext) { skipNext = false; return }
  if (loading.value) return
  record()
  saveState.value = 'pending'
  clearTimeout(timer)
  timer = setTimeout(saveDraft, 600)
}, { deep: true })

async function saveDraft() {
  if (!config.value) return
  clearTimeout(timer)
  saveState.value = 'saving'
  try {
    const { data } = await http.put<ThemeResponse>('/theme', { ...config.value, id: themeId.value })
    hasUnpublished.value = data.has_unpublished_changes
    previewUrl.value = data.preview_url
    saveState.value = 'saved'
    reloadPreview()
  } catch (e) {
    saveState.value = 'error'
    message.error(t('草稿保存失败：') + errorMessage(e))
  }
}

function reloadPreview() {
  frameLoading.value = true
  frameKey.value++
}

async function publish() {
  if (!isActive.value) {
    // Switching the live theme deserves a confirmation
    dialog.info({
      title: t('启用「{0}」主题？', [themeName.value]),
      content: t('发布后，店铺会立即换成这个主题。之前的主题设置会保留，随时可以切换回去。'),
      positiveText: t('发布并启用'),
      negativeText: t('取消'),
      onPositiveClick: () => doPublish(),
    })
    return
  }
  await doPublish()
}

async function doPublish() {
  if (saveState.value !== 'saved') await saveDraft()
  publishing.value = true
  try {
    const { data } = await http.post<ThemeResponse>('/theme/publish', { id: themeId.value })
    hasUnpublished.value = data.has_unpublished_changes
    const switched = !isActive.value
    isActive.value = data.live
    message.success(switched ? t('已启用「{0}」主题', [themeName.value]) : t('已发布，买家现在可以看到新的店铺样式'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    publishing.value = false
  }
}

function discard() {
  dialog.warning({
    title: t('放弃未发布的修改？'),
    content: t('店铺装修会恢复到上次发布时的样子，此操作无法撤销。'),
    positiveText: t('放弃修改'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      const { data } = await http.post<ThemeResponse>('/theme/discard', { id: themeId.value })
      skipNext = true
      apply(data)
      resetHistory()
      selectedId.value = null
      reloadPreview()
      message.success(t('已恢复到已发布版本'))
    },
  })
}

// ── Undo / redo (a burst of typing counts as one step) ──
const history = ref<string[]>([])
const future = ref<string[]>([])
let lastSnap = ''
let burst: ReturnType<typeof setTimeout> | undefined
let restoring = false
function resetHistory() {
  history.value = []
  future.value = []
  lastSnap = JSON.stringify(config.value)
}
function record() {
  if (restoring) { restoring = false; lastSnap = JSON.stringify(config.value); return }
  if (!burst) {
    history.value.push(lastSnap)
    if (history.value.length > 60) history.value.shift()
    future.value = []
  }
  clearTimeout(burst)
  burst = setTimeout(() => { burst = undefined; lastSnap = JSON.stringify(config.value) }, 700)
}
function settle() {
  if (burst) { clearTimeout(burst); burst = undefined; lastSnap = JSON.stringify(config.value) }
}
function restore(snap: string) {
  restoring = true
  lastSnap = snap
  config.value = JSON.parse(snap)
  if (selectedId.value && !config.value!.sections.some((s) => s.id === selectedId.value)) selectedId.value = null
}
function undo() {
  settle()
  const prev = history.value.pop()
  if (prev === undefined) return
  future.value.push(lastSnap)
  restore(prev)
}
function redo() {
  settle()
  const next = future.value.pop()
  if (next === undefined) return
  history.value.push(lastSnap)
  restore(next)
}
function onKey(e: KeyboardEvent) {
  const el = e.target as HTMLElement
  if (el.closest('input, textarea, [contenteditable="true"]')) return   // keep the browser's own undo while typing
  if (!(e.ctrlKey || e.metaKey) || e.altKey) return
  const k = e.key.toLowerCase()
  if (k === 'z' && !e.shiftKey) { e.preventDefault(); undo() }
  else if (k === 'y' || (k === 'z' && e.shiftKey)) { e.preventDefault(); redo() }
}

// ── Sections ──
function addSection(type: string) {
  addOpen.value = false
  if (!schema.value || !config.value || !canAdd(type)) return
  const s = newSection(schema.value, type)
  // Insert after the selected section, or at the end
  const at = selected.value ? config.value.sections.indexOf(selected.value) + 1 : config.value.sections.length
  config.value.sections.splice(at, 0, s)
  select(s.id)
}

function removeSection(id: string) {
  if (!config.value) return
  const i = config.value.sections.findIndex((s) => s.id === id)
  const def = schema.value?.sections[config.value.sections[i]!.type]
  dialog.warning({
    title: t('删除「{0}」模块？', [def?.label]),
    content: t('删除后可以在发布前通过「放弃修改」恢复。'),
    positiveText: t('删除'),
    negativeText: t('取消'),
    onPositiveClick: () => {
      config.value!.sections.splice(i, 1)
      selectedId.value = null
    },
  })
}

function duplicateSection(s: Section) {
  if (!config.value || !canAdd(s.type)) {
    message.warning(t('这个模块已达到数量上限'))
    return
  }
  const copy: Section = { ...JSON.parse(JSON.stringify(s)), id: `${s.type}-${Math.random().toString(36).slice(2, 8)}` }
  config.value.sections.splice(config.value.sections.indexOf(s) + 1, 0, copy)
  select(copy.id)
}

function select(id: string | null) {
  selectedId.value = id
  if (id) iframe.value?.contentWindow?.postMessage({ type: 'lumee:select-section', id }, previewOrigin.value || '*')
}

function sectionSummary(s: { type: string; settings: Record<string, unknown> }) {
  const h = s.settings.heading || s.settings.eyebrow || s.settings.item1_title
  return typeof h === 'string' && h ? h : ''
}

// Native drag-to-reorder
const dragIndex = ref<number | null>(null)
function onDragEnter(i: number) {
  const from = dragIndex.value
  if (from === null || from === i || !config.value) return
  const list = config.value.sections
  const [moved] = list.splice(from, 1)
  list.splice(i, 0, moved!)
  dragIndex.value = i
}

// ── Preview iframe messages ──
function onMessage(e: MessageEvent) {
  if (e.origin !== previewOrigin.value || !e.data || typeof e.data !== 'object') return
  if (e.data.type === 'lumee:section-clicked' && typeof e.data.id === 'string') {
    tab.value = 'sections'
    selectedId.value = e.data.id
  }
  if (e.data.type === 'lumee:preview-ready') {
    frameLoading.value = false
    if (selectedId.value) select(selectedId.value)
  }
}

function onUploaded(id: number, url: string) {
  mediaUrls.value[id] = url
}

onMounted(() => {
  window.addEventListener('message', onMessage)
  window.addEventListener('keydown', onKey)
  load()
})
onBeforeUnmount(() => {
  window.removeEventListener('message', onMessage)
  window.removeEventListener('keydown', onKey)
  if (saveState.value === 'pending') saveDraft()
})
onBeforeRouteLeave(async () => {
  if (saveState.value === 'pending') await saveDraft()
  return true
})
</script>

<template>
  <div class="editor">
    <header class="bar">
      <div class="bar-left">
        <NButton quaternary size="small" :aria-label="t('返回主题库')" @click="router.push({ name: 'online-store' })">
          <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
          {{ t('主题库') }}
        </NButton>
        <span class="divider" aria-hidden="true" />
        <span class="title">{{ themeName }}</span>
        <NTag v-if="isActive" size="tiny" :bordered="false" type="success">{{ t('使用中') }}</NTag>
        <NTag v-else size="tiny" :bordered="false" type="warning">{{ t('未发布') }}</NTag>
        <span class="status" :class="saveState">
          <template v-if="saveState === 'saving'">{{ t('保存中…') }}</template>
          <template v-else-if="saveState === 'pending'">{{ t('有修改') }}</template>
          <template v-else-if="saveState === 'error'">{{ t('保存失败') }}</template>
          <template v-else-if="hasUnpublished">{{ t('草稿已保存 · 尚未发布') }}</template>
          <template v-else>{{ t('与线上一致') }}</template>
        </span>
      </div>
      <div class="bar-right">
        <div class="device" role="group" :aria-label="t('撤销和重做')">
          <NTooltip><template #trigger>
            <button type="button" :disabled="!history.length" :aria-label="t('撤销')" @click="undo"><NIcon size="17"><ArrowUndoOutline /></NIcon></button>
          </template>{{ t('撤销（Ctrl+Z）') }}</NTooltip>
          <NTooltip><template #trigger>
            <button type="button" :disabled="!future.length" :aria-label="t('重做')" @click="redo"><NIcon size="17"><ArrowRedoOutline /></NIcon></button>
          </template>{{ t('重做（Ctrl+Y）') }}</NTooltip>
        </div>
        <div class="device" role="group" :aria-label="t('预览尺寸')">
          <NTooltip><template #trigger>
            <button type="button" :class="{ on: device === 'desktop' }" :aria-label="t('电脑')" @click="device = 'desktop'"><NIcon size="18"><DesktopOutline /></NIcon></button>
          </template>{{ t('电脑') }}</NTooltip>
          <NTooltip><template #trigger>
            <button type="button" :class="{ on: device === 'mobile' }" :aria-label="t('手机')" @click="device = 'mobile'"><NIcon size="18"><PhonePortraitOutline /></NIcon></button>
          </template>{{ t('手机') }}</NTooltip>
        </div>
        <NButton v-if="hasUnpublished" quaternary size="small" @click="discard">{{ t('放弃修改') }}</NButton>
        <a :href="storefrontUrl" target="_blank" class="plain">
          <NButton secondary size="small">
            <template #icon><NIcon><OpenOutline /></NIcon></template>
            {{ t('查看店铺') }}
          </NButton>
        </a>
        <NButton type="primary" size="small" :loading="publishing" :disabled="!hasUnpublished && saveState === 'saved'" @click="publish">{{ isActive ? t('发布') : t('发布并启用') }}</NButton>
      </div>
    </header>

    <div v-if="loading" class="center"><NSpin /></div>

    <div v-else-if="schema && config" class="body">
      <aside class="panel">
        <!-- Editing one section -->
        <template v-if="selected && selectedDef">
          <div class="panel-head">
            <NButton text @click="selectedId = null">
              <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
            </NButton>
            <span class="panel-title">{{ selectedDef.label }}</span>
            <NTooltip><template #trigger>
              <NButton text :aria-label="t('复制模块')" @click="duplicateSection(selected)">
                <template #icon><NIcon><CopyOutline /></NIcon></template>
              </NButton>
            </template>{{ t('复制模块') }}</NTooltip>
            <NButton text type="error" :aria-label="t('删除模块')" @click="removeSection(selected.id)">
              <template #icon><NIcon><TrashOutline /></NIcon></template>
            </NButton>
          </div>
          <div class="panel-body">
            <FieldInput
              v-for="(field, key) in selectedDef.fields"
              :key="selected.id + key"
              v-model="selected.settings[key]"
              :field="field"
              :media-urls="mediaUrls"
              @uploaded="onUploaded"
            />
          </div>
        </template>

        <NTabs v-else v-model:value="tab" type="line" justify-content="space-evenly" class="tabs">
          <NTabPane name="sections" :tab="t('首页模块')">
            <div class="panel-body">
              <ul class="sections">
                <li
                  v-for="(s, i) in config.sections"
                  :key="s.id"
                  class="sec"
                  :class="{ off: !s.enabled, dragging: dragIndex === i }"
                  draggable="true"
                  @dragstart="dragIndex = i"
                  @dragenter.prevent="onDragEnter(i)"
                  @dragover.prevent
                  @dragend="dragIndex = null"
                >
                  <NIcon class="grip" size="16"><ReorderTwoOutline /></NIcon>
                  <button type="button" class="sec-main" @click="select(s.id)">
                    <span class="sec-name">{{ schema.sections[s.type]?.label ?? s.type }}</span>
                    <span v-if="sectionSummary(s)" class="sec-sub">{{ sectionSummary(s) }}</span>
                  </button>
                  <button type="button" class="icon-btn" :aria-label="s.enabled ? t('隐藏') : t('显示')" @click="s.enabled = !s.enabled">
                    <NIcon size="16"><EyeOutline v-if="s.enabled" /><EyeOffOutline v-else /></NIcon>
                  </button>
                  <button type="button" class="icon-btn" :aria-label="t('复制模块')" @click="duplicateSection(s)">
                    <NIcon size="15"><CopyOutline /></NIcon>
                  </button>
                  <NIcon class="chev" size="14"><ChevronForwardOutline /></NIcon>
                </li>
              </ul>
              <p v-if="!config.sections.length" class="hint">{{ t('首页还没有模块，添加一个吧。') }}</p>

              <NPopover v-model:show="addOpen" trigger="click" placement="bottom" :show-arrow="false" style="padding: 6px; width: 288px">
                <template #trigger>
                  <NButton dashed block>
                    <template #icon><NIcon><AddOutline /></NIcon></template>
                    {{ t('添加模块') }}
                  </NButton>
                </template>
                <div class="picker">
                  <button v-for="o in addOptions" :key="o.key" type="button" class="pick" :disabled="o.disabled" @click="addSection(o.key)">
                    <span class="pick-name">{{ o.label }}</span>
                    <span class="pick-hint">{{ o.disabled ? t('已达到数量上限') : o.hint }}</span>
                  </button>
                </div>
              </NPopover>
              <p class="hint">{{ t('拖动模块调整顺序；在右侧预览中点击模块也可以直接编辑。') }}</p>
            </div>
          </NTabPane>

          <NTabPane name="settings" :tab="t('主题设置')">
            <div class="panel-body">
              <NCollapse :default-expanded-names="['colors']" accordion>
                <NCollapseItem v-for="(group, gkey) in schema.settings" :key="gkey" :title="group.label" :name="gkey">
                  <FieldInput
                    v-for="(field, key) in group.fields"
                    :key="gkey + key"
                    v-model="config.settings[gkey]![key]"
                    :field="field"
                    :media-urls="mediaUrls"
                    @uploaded="onUploaded"
                  />
                </NCollapseItem>
              </NCollapse>
            </div>
          </NTabPane>
        </NTabs>
      </aside>

      <section class="stage">
        <div class="frame-wrap" :class="device">
          <iframe
            :key="frameKey"
            ref="iframe"
            :src="previewUrl"
            :title="t('店铺预览')"
            @load="frameLoading = false"
          />
          <div v-if="frameLoading" class="frame-loading"><NSpin size="small" /></div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.editor { display: flex; flex-direction: column; height: calc(100vh - 60px); }
.bar {
  display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
  padding: 10px 20px; border-bottom: 1px solid var(--border); background: var(--surface);
}
.bar-left, .bar-right { display: flex; align-items: center; gap: 10px; }
.title { font-size: 15px; font-weight: 600; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.divider { width: 1px; height: 20px; background: var(--border); }
.picker { display: grid; gap: 2px; max-height: 60vh; overflow-y: auto; }
.pick { display: grid; gap: 2px; text-align: left; padding: 8px 10px; border-radius: 8px; border: none; background: none; cursor: pointer; font: inherit; color: var(--ink); }
.pick:hover:not(:disabled) { background: var(--brand-50); }
.pick:disabled { cursor: not-allowed; opacity: .5; }
.pick-name { font-size: 14px; font-weight: 500; }
.pick-hint { font-size: 12px; color: var(--ink-muted); line-height: 1.4; }
.device button:disabled { opacity: .4; cursor: not-allowed; }
.status { font-size: 12px; color: var(--ink-muted); }
.status.saving, .status.pending { color: var(--warning); }
.status.error { color: var(--danger); }
.plain { text-decoration: none; }
.device { display: flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.device button { border: none; background: var(--surface); padding: 5px 10px; cursor: pointer; color: var(--ink-muted); display: flex; }
.device button.on { background: var(--brand-100); color: var(--brand-700); }
.center { flex: 1; display: flex; align-items: center; justify-content: center; }
.body { flex: 1; display: grid; grid-template-columns: 320px 1fr; min-height: 0; }
.panel { border-right: 1px solid var(--border); background: var(--surface); display: flex; flex-direction: column; min-height: 0; }
.tabs { display: flex; flex-direction: column; height: 100%; }
.tabs :deep(.n-tabs-pane-wrapper) { flex: 1; overflow-y: auto; }
.panel-head { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid var(--border); }
.panel-title { flex: 1; font-weight: 600; }
.panel-body { padding: 16px; overflow-y: auto; }
.sections { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 6px; }
.sec {
  display: flex; align-items: center; gap: 6px; padding: 8px 8px 8px 6px; border-radius: 10px;
  border: 1px solid var(--border); background: var(--surface); cursor: grab;
}
.sec:hover { border-color: var(--brand-300); background: var(--brand-50); }
.sec.dragging { opacity: .4; }
.sec.off .sec-name, .sec.off .sec-sub { color: var(--ink-muted); text-decoration: line-through; }
.grip { color: var(--ink-muted); flex: none; }
.sec-main { flex: 1; min-width: 0; text-align: left; background: none; border: none; padding: 2px 4px; cursor: pointer; font: inherit; color: inherit; }
.sec-name { display: block; font-size: 14px; font-weight: 500; }
.sec-sub { display: block; font-size: 12px; color: var(--ink-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.icon-btn { border: none; background: none; padding: 4px; cursor: pointer; color: var(--ink-muted); display: flex; border-radius: 6px; }
.icon-btn:hover { background: var(--brand-100); color: var(--brand-700); }
.chev { color: var(--ink-muted); }
.hint { font-size: 12px; color: var(--ink-muted); margin: 10px 0 0; }
.stage { background: #E9EFEC; padding: 20px; overflow: auto; display: flex; justify-content: center; min-height: 0; }
.frame-wrap { position: relative; width: 100%; height: 100%; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 8px 30px rgba(28, 38, 33, .12); transition: width .25s ease; }
.frame-wrap.mobile { width: 390px; max-width: 100%; }
.frame-wrap iframe { width: 100%; height: 100%; border: 0; display: block; }
.frame-loading { position: absolute; top: 12px; right: 12px; background: rgba(255,255,255,.9); border-radius: 999px; padding: 6px; display: flex; }
@media (max-width: 900px) {
  .body { grid-template-columns: 1fr; grid-template-rows: auto 1fr; }
  .panel { max-height: 45vh; border-right: none; border-bottom: 1px solid var(--border); }
}
</style>
