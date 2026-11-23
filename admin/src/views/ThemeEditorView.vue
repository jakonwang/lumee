<script setup lang="ts">
import { computed, h, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import {
  NAlert, NButton, NDrawer, NDrawerContent, NEmpty, NIcon, NModal, NTabs, NTabPane, NPopover, NCollapse, NCollapseItem, NTooltip, NSelect, NSpin, NTag, NInput,
  useMessage, useDialog,
} from 'naive-ui'
import {
  AddOutline, ArrowBackOutline, EyeOutline, EyeOffOutline, TrashOutline, ReorderTwoOutline, TimeOutline, SparklesOutline, CheckmarkDoneOutline, CalendarOutline,
  DesktopOutline, PhonePortraitOutline, OpenOutline, ChevronForwardOutline, CopyOutline, ArrowUndoOutline, ArrowRedoOutline, FlaskOutline,
} from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import FieldInput from '@/components/theme/FieldInput.vue'
import ThemeSchedulesDrawer from '@/components/theme/ThemeSchedulesDrawer.vue'
import AiDesignPanel from '@/components/theme/AiDesignPanel.vue'
import { type ThemeConfig, type ThemePlace, type ThemeResponse, type ThemeSchema, type ThemeVersion, type Section, newSection } from '@/api/theme'
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
// ?ai=… (a weekly suggestion from 装修效果) opens the AI assistant with that request filled in
const aiRequest = typeof route.query.ai === 'string' ? route.query.ai : ''
const tab = ref<'sections' | 'settings' | 'ai'>(aiRequest ? 'ai' : 'sections')
// AI design: status, and the proposal shown in the preview while comparing (null = the draft)
const aiStatus = ref<ThemeResponse['ai']>({ ready: false, used: 0, quota: 0, images: false })
const proposalUrl = ref<string | null>(null)
function showProposal(url: string | null) {
  proposalUrl.value = url
  reloadPreview()
}
function aiApplied(data: ThemeResponse) {
  skipNext = true
  apply(data)
  resetHistory()
  selectedId.value = null
  reloadPreview()
}
function aiSelect(id: string) {
  tab.value = 'sections'
  select(id)
}

// ── Which page is being designed: home, product / collection / cart template, or a campaign page ──
const places = ref<ThemePlace[]>([])
const place = ref(typeof route.query.place === 'string' ? route.query.place : 'home')
const placeInfo = computed(() => places.value.find((p) => p.key === place.value) ?? places.value[0])
/** home | product | collection | cart | page */
const placeKind = computed(() => placeInfo.value?.place ?? 'home')
/** The blocks of the page being designed (the same array as in config, so edits go straight in) */
const list = computed<Section[]>(() => {
  const c = config.value
  if (!c) return []
  if (place.value === 'home') return c.sections
  if (place.value.startsWith('page:')) {
    const id = place.value.slice(5)
    c.pages ??= {}
    return (c.pages[id] ??= [])
  }
  c.templates ??= {}
  return (c.templates[place.value] ??= [])
})
/** The block that is always on this page (product info, product grid, the bag): can't be removed, copied or hidden */
const fixedType = computed(() => schema.value?.templates?.[placeKind.value]?.fixed ?? null)
const placeOptions = computed(() => places.value.map((p) => ({ label: p.label, value: p.key })))
function switchPlace(key: string) {
  proposalUrl.value = null
  place.value = key
  selectedId.value = null
  addOpen.value = false
  previewUrl.value = placeInfo.value?.preview_url ?? previewUrl.value
  router.replace({ query: { ...route.query, place: key === 'home' ? undefined : key } })
  reloadPreview()
}
function allBlocks(c: ThemeConfig | null): Section[] {
  if (!c) return []
  return [...c.sections, ...Object.values(c.templates ?? {}).flat(), ...Object.values(c.pages ?? {}).flat()]
}

// ── Style kits: a regional starting look (shapes, spacing, fonts, background / accent colours) ──
const kits = computed(() => Object.entries(schema.value?.kits ?? {}).map(([key, k]) => ({ key, ...k })))
function applyKit(key: string) {
  const kit = schema.value?.kits?.[key]
  if (!kit || !config.value || !schema.value) return
  for (const [path, want] of Object.entries(kit.settings)) {
    const [g, k] = path.split('.') as [string, string]
    const field = schema.value.settings[g]?.fields[k]
    if (!field || !config.value.settings[g]) continue
    // Fonts: the first the theme offers; other selects must be one of their options
    const candidates = Array.isArray(want) ? want : [want]
    const value = field.type === 'select' ? candidates.find((v) => field.options && v in field.options) : candidates[0]
    if (value !== undefined) config.value.settings[g]![k] = value
  }
  message.success(t('已套用「{0}」，可以继续微调；不满意可以撤销', [kit.name]))
}

// ── Markets: each can change the main site's design (only what differs is kept) or have its own home page ──
const markets = ref<ThemeResponse['markets']>([])
const market = ref<ThemeResponse['market']>(null)
const base = ref<ThemeConfig | null>(null)
const ownSections = ref(false)
const marketId = computed(() => (route.query.market ? Number(route.query.market) : null))
/**
 * In a market, the structure of the page follows the main site (only the home page can be the market's
 * own): blocks can be edited and hidden, not added, removed or moved.
 */
const followsMain = computed(() => !!market.value && (place.value !== 'home' || !ownSections.value))
const marketOptions = computed(() => [
  { label: t('主站点（所有市场的默认样式）'), value: 0 },
  ...markets.value.map((m) => ({
    label: `${m.name} · ${m.currency}${m.customized ? ' · ' + t('已单独装修') : ''}${m.has_unpublished_changes ? ' · ' + t('未发布') : ''}`,
    value: m.id,
  })),
])
async function switchMarket(id: number) {
  proposalUrl.value = null
  if (saveState.value === 'pending') await saveDraft()
  selectedId.value = null
  await router.replace({ query: id ? { market: String(id) } : {} })
  await load()
  reloadPreview()
}
const same = (a: unknown, b: unknown) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null)
/** Has this market changed the setting / section field? */
function settingOver(g: string | number, k: string | number) {
  return !!market.value && !!base.value && !same(config.value?.settings[g]?.[k], base.value.settings[g]?.[k])
}
// Main site: country sites that set this field themselves (a change here won't reach them)
const marketOverrides = ref<Record<string, string[]>>({})
const overriddenBy = (key: string) => (market.value ? [] : marketOverrides.value[key] ?? [])
function sectionOver(s: Section, k: string) {
  if (!followsMain.value || !base.value) return false
  const b = allBlocks(base.value).find((x) => x.id === s.id)
  return !!b && !same(s.settings[k], b.settings[k])
}
function restoreSetting(g: string | number, k: string | number) {
  if (config.value && base.value) config.value.settings[g]![k] = JSON.parse(JSON.stringify(base.value.settings[g]?.[k] ?? null))
}
function restoreSectionField(s: Section, k: string) {
  const b = allBlocks(base.value).find((x) => x.id === s.id)
  if (b) s.settings[k] = JSON.parse(JSON.stringify(b.settings[k] ?? null))
}
function makeOwnHome() {
  ownSections.value = true
  saveDraft()
  message.success(t('这个市场现在有自己的首页，可以随意添加、删除和排序模块'))
}
function followMainHome() {
  dialog.warning({
    title: t('恢复跟随主站点的首页？'),
    content: t('这个市场自己的首页模块会被主站点的首页替换（发布前可以通过「放弃修改」恢复）。'),
    positiveText: t('恢复跟随'),
    negativeText: t('取消'),
    onPositiveClick: () => {
      if (!config.value || !base.value) return
      ownSections.value = false
      selectedId.value = null
      config.value.sections = JSON.parse(JSON.stringify(base.value.sections))   // home only
    },
  })
}

// ── Version history (one per publish) ──
const showVersions = ref(false)
const versions = ref<ThemeVersion[]>([])
async function openVersions() {
  showVersions.value = true
  versions.value = (await http.get('/theme/versions', { params: { id: themeId.value, market: marketId.value ?? undefined } })).data.data
}
function restoreVersion(v: ThemeVersion) {
  dialog.info({
    title: t('恢复到 {0} 的版本？', [formatDate(v.created_at)]),
    content: t('这个版本会放进草稿，确认无误后再点「发布」。'),
    positiveText: t('恢复到草稿'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      if (saveState.value === 'pending') await saveDraft()
      const { data } = await http.post<ThemeResponse>(`/theme/versions/${v.id}/restore`)
      skipNext = true
      apply(data)
      resetHistory()
      selectedId.value = null
      showVersions.value = false
      reloadPreview()
      message.success(t('已恢复到草稿，发布后生效'))
    },
  })
}
const selectedId = ref<string | null>(null)
const device = ref<'desktop' | 'mobile'>('desktop')

// Save status: idle → pending (typing) → saving → saved
const saveState = ref<'saved' | 'pending' | 'saving' | 'error'>('saved')
const publishing = ref(false)
const frameKey = ref(0)
const frameLoading = ref(true)
const iframe = ref<HTMLIFrameElement>()

const selected = computed(() => list.value.find((s) => s.id === selectedId.value) ?? null)
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
  slideshow: t('最多 3 张横幅轮播，每张可以单独设置手机图'),
  category_circles: t('一排圆形分类入口，手机上可以左右滑动'),
  flash_sale: t('倒计时 + 打折商品，时间到了自动隐藏'),
  coupon_bar: t('展示可用的折扣码，点一下就复制'),
  product_tabs: t('「新品 / 热卖 / 特价」切换的商品列表'),
  market_bestsellers: t('按这个市场最近的销量自动排列'),
  marquee: t('一行滚动的文字：包邮、退货、分期等'),
  trust_bar: t('按市场自动显示支付方式、免邮门槛、关税'),
  ugc_wall: t('顾客评价里的带图晒单'),
  hotspots: t('一张场景图，标出可以购买的商品'),
  shipping_eta: t('按备货和运输天数显示预计送达日期'),
  related_products: t('同分类、本市场热卖或最新商品'),
  product_reviews: t('商品评价和评分'),
  frequently_bought: t('经常一起购买的商品，一键加入购物袋'),
  collection_banner: t('分类标题、介绍，可以用分类图片做横幅'),
  cart_upsell: t('购物袋下方的凑单推荐'),
}
const canAdd = (type: string) => {
  if (!schema.value || !config.value) return false
  const def = schema.value.sections[type]
  if (!def?.on?.includes(placeKind.value) || type === fixedType.value) return false
  const count = list.value.filter((s) => s.type === type).length
  return list.value.length < schema.value.max_sections && (def.max_per_page === undefined || count < def.max_per_page)
}
const addOptions = computed(() => Object.entries(schema.value?.sections ?? {})
  .filter(([key, def]) => def.on?.includes(placeKind.value) && key !== fixedType.value)
  .map(([key, def]) => ({ key, label: def.label, hint: sectionHints[key] ?? '', disabled: !canAdd(key) })))
const addOpen = ref(false)

function apply(data: ThemeResponse) {
  themeName.value = data.name
  isActive.value = data.live
  schema.value = data.schema
  config.value = data.config
  previewUrl.value = data.preview_url
  storefrontUrl.value = data.storefront_url
  hasUnpublished.value = data.has_unpublished_changes
  experiment.value = data.experiment
  marketOverrides.value = data.market_overrides ?? {}
  places.value = data.places
  if (!data.places.some((p) => p.key === place.value)) place.value = 'home'
  previewUrl.value = placeInfo.value?.preview_url ?? data.preview_url
  markets.value = data.markets
  market.value = data.market
  base.value = data.base
  aiStatus.value = data.ai
  ownSections.value = !!data.market?.own_sections
  data.media.forEach((m) => { mediaUrls.value[m.id] = m.url })
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get<ThemeResponse>('/theme', { params: { id: themeId.value, market: marketId.value ?? undefined } })
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
    const { data } = await http.put<ThemeResponse>('/theme', { ...config.value, id: themeId.value, market: marketId.value, own_sections: ownSections.value })
    hasUnpublished.value = data.has_unpublished_changes
    markets.value = data.markets
    if (data.market) market.value = data.market
    places.value = data.places
    previewUrl.value = placeInfo.value?.preview_url ?? data.preview_url
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

// ── Automatic design, the design check, campaign schedules ──
interface Issue { level: 'error' | 'warning' | 'info'; code: string; message: string; place: string | null; block: string | null }
const issues = ref<Issue[]>([])
const notes = ref<string[]>([])
const showCheck = ref(false)
const checkMode = ref<'check' | 'publish' | 'generated'>('check')
const checking = ref(false)
const generating = ref(false)
const showSchedules = ref(false)
const levelType: Record<string, 'error' | 'warning' | 'info'> = { error: 'error', warning: 'warning', info: 'info' }
const levelLabel: Record<string, string> = { error: t('错误'), warning: t('提醒'), info: t('建议') }
async function runCheck(mode: 'check' | 'publish') {
  if (saveState.value !== 'saved') await saveDraft()
  checking.value = true
  try {
    issues.value = (await http.get('/theme/check', { params: { id: themeId.value, market: marketId.value ?? undefined } })).data.issues
  } finally {
    checking.value = false
  }
  checkMode.value = mode
  notes.value = []
  if (mode === 'publish' && !issues.value.some((i) => i.level !== 'info')) return confirmPublish()
  showCheck.value = true
}
function generate() {
  dialog.info({
    title: market.value ? t('自动装修「{0}」站点？', [market.value.name]) : t('自动装修主站点？'),
    content: t('根据地区、语言、热卖商品、支付方式和活动日历，生成首页模块、风格和当地语言的文案。结果放进草稿，会替换当前草稿的首页，线上店铺不受影响，可以撤销或放弃修改。'),
    positiveText: t('生成'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      if (saveState.value === 'pending') await saveDraft()
      generating.value = true
      try {
        const { data } = await http.post('/theme/generate', { id: themeId.value, market: marketId.value })
        skipNext = true
        apply(data)
        resetHistory()
        selectedId.value = null
        if (place.value !== 'home') switchPlace('home')
        else reloadPreview()
        notes.value = data.notes
        issues.value = data.issues
        checkMode.value = 'generated'
        showCheck.value = true
      } catch (e) {
        message.error(errorMessage(e))
      } finally {
        generating.value = false
      }
    },
  })
}
function goTo(i: Issue) {
  if (i.place && i.place !== place.value && places.value.some((p) => p.key === i.place)) switchPlace(i.place)
  if (i.block) {
    tab.value = 'sections'
    select(i.block)
  } else if (i.code.startsWith('contrast') || i.code === 'untranslated') {
    tab.value = 'settings'
  }
  showCheck.value = false
}

// ── Design A/B test: half the visitors see the draft (B), half the live design (A) ──
const experiment = ref<ThemeResponse['experiment']>(null)
const testing = ref(false)
function startTest() {
  const name = ref(market.value ? t('{0} 新设计', [market.value.name]) : t('首页新设计'))
  dialog.create({
    title: t('A/B 测试这个草稿'),
    content: () => h('div', { style: 'display: grid; gap: 10px' }, [
      h('p', { style: 'margin: 0; font-size: 13px; line-height: 1.6' }, t('一半访客看现在的设计（A），一半看这个草稿（B），同一位访客始终看到同一版。草稿会被冻结为 B 版，之后继续修改不影响测试。在「装修效果」页查看结果并决定是否采用。')),
      h(NInput, { value: name.value, maxlength: 120, placeholder: t('测试名称'), 'onUpdate:value': (v: string) => { name.value = v } }),
    ]),
    positiveText: t('开始测试'), negativeText: t('取消'),
    onPositiveClick: async () => {
      if (!name.value.trim()) return false
      if (saveState.value !== 'saved') await saveDraft()
      testing.value = true
      try {
        const { data } = await http.post('/theme/experiments', { id: themeId.value, market: marketId.value, name: name.value.trim() })
        experiment.value = data.data
        message.success(t('测试已开始'))
      } catch (e) { message.error(errorMessage(e)); return false } finally { testing.value = false }
    },
  })
}

async function publish() {
  await runCheck('publish')
}

async function confirmPublish() {
  showCheck.value = false
  if (!isActive.value && !market.value) {
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
    const { data } = await http.post<ThemeResponse>('/theme/publish', { id: themeId.value, market: marketId.value })
    hasUnpublished.value = data.has_unpublished_changes
    markets.value = data.markets
    const switched = !isActive.value && !data.market
    isActive.value = data.live
    message.success(data.market ? t('已发布到「{0}」站点', [data.market.name])
      : switched ? t('已启用「{0}」主题', [themeName.value]) : t('已发布，买家现在可以看到新的店铺样式'))
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
      const { data } = await http.post<ThemeResponse>('/theme/discard', { id: themeId.value, market: marketId.value })
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
  if (selectedId.value && !list.value.some((s) => s.id === selectedId.value)) selectedId.value = null
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
  if (!schema.value || !config.value || !canAdd(type) || followsMain.value) return
  const s = newSection(schema.value, type)
  // Insert after the selected section, or at the end
  const at = selected.value ? list.value.indexOf(selected.value) + 1 : list.value.length
  list.value.splice(at, 0, s)
  select(s.id)
}

function removeSection(id: string) {
  if (!config.value) return
  const i = list.value.findIndex((s) => s.id === id)
  if (list.value[i]?.type === fixedType.value) return
  const def = schema.value?.sections[list.value[i]!.type]
  dialog.warning({
    title: t('删除「{0}」模块？', [def?.label]),
    content: t('删除后可以在发布前通过「放弃修改」恢复。'),
    positiveText: t('删除'),
    negativeText: t('取消'),
    onPositiveClick: () => {
      list.value.splice(i, 1)
      selectedId.value = null
    },
  })
}

function duplicateSection(s: Section) {
  if (followsMain.value || s.type === fixedType.value) return
  if (!config.value || !canAdd(s.type)) {
    message.warning(t('这个模块已达到数量上限'))
    return
  }
  const copy: Section = { ...JSON.parse(JSON.stringify(s)), id: `${s.type}-${Math.random().toString(36).slice(2, 8)}` }
  list.value.splice(list.value.indexOf(s) + 1, 0, copy)
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
  if (from === null || from === i || !config.value || followsMain.value) return
  const blocks = list.value
  const [moved] = blocks.splice(from, 1)
  blocks.splice(i, 0, moved!)
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
        <NSelect v-if="markets.length" :value="marketId ?? 0" :options="marketOptions" size="small" class="market-pick" :aria-label="t('装修哪个站点')" @update:value="switchMarket" />
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
        <NTooltip><template #trigger>
          <NButton quaternary size="small" :loading="generating" :aria-label="t('自动装修')" @click="generate"><template #icon><NIcon><SparklesOutline /></NIcon></template></NButton>
        </template>{{ t('自动装修') }}</NTooltip>
        <NTooltip><template #trigger>
          <NButton quaternary size="small" :loading="checking" :aria-label="t('装修检查')" @click="runCheck('check')"><template #icon><NIcon><CheckmarkDoneOutline /></NIcon></template></NButton>
        </template>{{ t('装修检查') }}</NTooltip>
        <NTooltip><template #trigger>
          <NButton quaternary size="small" :aria-label="t('活动排期')" @click="showSchedules = true"><template #icon><NIcon><CalendarOutline /></NIcon></template></NButton>
        </template>{{ t('活动排期') }}</NTooltip>
        <NTooltip><template #trigger>
          <NButton quaternary size="small" :aria-label="t('历史版本')" @click="openVersions"><template #icon><NIcon><TimeOutline /></NIcon></template></NButton>
        </template>{{ t('历史版本') }}</NTooltip>
        <NTooltip v-if="isActive && hasUnpublished && !experiment"><template #trigger>
          <NButton quaternary size="small" :loading="testing" :aria-label="t('A/B 测试这个草稿')" @click="startTest"><template #icon><NIcon><FlaskOutline /></NIcon></template></NButton>
        </template>{{ t('A/B 测试这个草稿') }}</NTooltip>
        <NButton v-if="hasUnpublished" quaternary size="small" @click="discard">{{ t('放弃修改') }}</NButton>
        <a :href="storefrontUrl" target="_blank" class="plain">
          <NButton secondary size="small">
            <template #icon><NIcon><OpenOutline /></NIcon></template>
            {{ t('查看店铺') }}
          </NButton>
        </a>
        <NButton type="primary" size="small" :loading="publishing" :disabled="!hasUnpublished && saveState === 'saved'" @click="publish">{{ market ? t('发布到 {0}', [market.name]) : isActive ? t('发布') : t('发布并启用') }}</NButton>
      </div>
    </header>

    <div v-if="loading" class="center"><NSpin /></div>

    <div v-else-if="schema && config" class="body">
      <aside class="panel">
        <div class="place-pick">
          <span>{{ t('正在装修') }}</span>
          <NSelect :value="place" :options="placeOptions" size="small" :aria-label="t('装修哪个页面')" @update:value="switchPlace" />
        </div>
        <div v-if="experiment" class="market-note">
          {{ t('「{0}」A/B 测试进行中：一半访客看到的是测试开始时的草稿。', [experiment.name]) }}
          <RouterLink :to="{ name: 'design-performance' }">{{ t('查看结果') }}</RouterLink>
        </div>
        <div v-if="proposalUrl" class="proposal-note">{{ t('预览中是 AI 方案（还没有保存）') }}</div>
        <div v-if="!market && place === 'home' && overriddenBy('sections.*').length" class="mk-note home-note">
          {{ t('{0} 有自己的首页，这里首页的改动不会影响这些站点', [overriddenBy('sections.*').join('、')]) }}
        </div>
        <div v-if="market" class="market-note">
          {{ t('正在装修「{0}」站点（{1}）。没改过的内容跟随主站点，改过的会标出「本市场」。', [market.name, market.locale]) }}
        </div>
        <!-- Editing one section -->
        <template v-if="selected && selectedDef">
          <div class="panel-head">
            <NButton text @click="selectedId = null">
              <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
            </NButton>
            <span class="panel-title">{{ selectedDef.label }}</span>
            <template v-if="!followsMain && selected.type !== fixedType">
              <NTooltip><template #trigger>
                <NButton text :aria-label="t('复制模块')" @click="duplicateSection(selected)">
                  <template #icon><NIcon><CopyOutline /></NIcon></template>
                </NButton>
              </template>{{ t('复制模块') }}</NTooltip>
              <NButton text type="error" :aria-label="t('删除模块')" @click="removeSection(selected.id)">
                <template #icon><NIcon><TrashOutline /></NIcon></template>
              </NButton>
            </template>
          </div>
          <div class="panel-body">
            <div v-for="(field, key) in selectedDef.fields" :key="selected.id + key" class="fwrap" :class="{ over: sectionOver(selected, String(key)) }">
              <FieldInput v-model="selected.settings[key]" :field="field" :media-urls="mediaUrls" @uploaded="onUploaded" />
              <div v-if="sectionOver(selected, String(key))" class="over-bar">
                <span>{{ t('本市场') }}</span>
                <button type="button" @click="restoreSectionField(selected, String(key))">{{ t('恢复主站点') }}</button>
              </div>
              <div v-else-if="overriddenBy(`sections.${selected.id}.${key}`).length" class="mk-note">
                {{ t('{0} 单独设置了这一项，这里的修改不会影响这些站点', [overriddenBy(`sections.${selected.id}.${key}`).join('、')]) }}
              </div>
            </div>
          </div>
        </template>

        <NTabs v-else v-model:value="tab" type="line" justify-content="space-evenly" class="tabs">
          <NTabPane name="sections" :tab="place === 'home' ? t('首页模块') : t('页面模块')">
            <div class="panel-body">
              <NAlert v-if="followsMain && place === 'home'" type="info" :bordered="false" class="mode">
                {{ t('首页跟随主站点：可以改模块里的文字、图片和隐藏模块；要增删或调整顺序，请改成本市场自己的首页。') }}
                <NButton size="tiny" secondary type="primary" style="margin-top: 8px" @click="makeOwnHome">{{ t('改成本市场自己的首页') }}</NButton>
              </NAlert>
              <NAlert v-else-if="followsMain" type="info" :bordered="false" class="mode">
                {{ t('这个页面的模块跟随主站点：可以改模块里的文字、图片和隐藏模块；增删和排序请在主站点里调整。') }}
              </NAlert>
              <NAlert v-else-if="market && place === 'home'" type="success" :bordered="false" class="mode">
                {{ t('这是本市场自己的首页，主站点首页的改动不会影响这里。') }}
                <NButton size="tiny" quaternary style="margin-top: 8px" @click="followMainHome">{{ t('恢复跟随主站点') }}</NButton>
              </NAlert>
              <ul class="sections">
                <li
                  v-for="(s, i) in list"
                  :key="s.id"
                  class="sec"
                  :class="{ off: !s.enabled, dragging: dragIndex === i, fixed: followsMain }"
                  :draggable="!followsMain"
                  @dragstart="dragIndex = i"
                  @dragenter.prevent="onDragEnter(i)"
                  @dragover.prevent
                  @dragend="dragIndex = null"
                >
                  <NIcon v-if="!followsMain" class="grip" size="16"><ReorderTwoOutline /></NIcon>
                  <button type="button" class="sec-main" @click="select(s.id)">
                    <span class="sec-name">{{ schema.sections[s.type]?.label ?? s.type }}<NTag v-if="s.type === fixedType" size="tiny" :bordered="false" style="margin-inline-start: 6px">{{ t('固定') }}</NTag><NTag v-if="s.settings.visibility && s.settings.visibility !== 'all'" size="tiny" :bordered="false" type="info" style="margin-inline-start: 6px">{{ s.settings.visibility === 'mobile' ? t('仅手机') : t('仅电脑') }}</NTag></span>
                    <span v-if="sectionSummary(s)" class="sec-sub">{{ sectionSummary(s) }}</span>
                  </button>
                  <button v-if="s.type !== fixedType" type="button" class="icon-btn" :aria-label="s.enabled ? t('隐藏') : t('显示')" @click="s.enabled = !s.enabled">
                    <NIcon size="16"><EyeOutline v-if="s.enabled" /><EyeOffOutline v-else /></NIcon>
                  </button>
                  <button v-if="!followsMain && s.type !== fixedType" type="button" class="icon-btn" :aria-label="t('复制模块')" @click="duplicateSection(s)">
                    <NIcon size="15"><CopyOutline /></NIcon>
                  </button>
                  <NIcon class="chev" size="14"><ChevronForwardOutline /></NIcon>
                </li>
              </ul>
              <p v-if="!list.length" class="hint">{{ t('这个页面还没有模块，添加一个吧。') }}</p>

              <NPopover v-if="!followsMain" v-model:show="addOpen" trigger="click" placement="bottom" :show-arrow="false" style="padding: 6px; width: 288px">
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
              <p class="hint">{{ followsMain ? t('在右侧预览中点击模块也可以直接编辑。') : t('拖动模块调整顺序；在右侧预览中点击模块也可以直接编辑。') }}</p>
            </div>
          </NTabPane>

          <NTabPane name="ai" :tab="t('AI 助手')">
            <div class="panel-body">
              <AiDesignPanel
                :theme-id="themeId" :market-id="marketId" :place="place" :place-label="placeInfo?.label ?? ''"
                :markets="markets" :status="aiStatus" :initial="aiRequest"
                @preview="showProposal" @applied="aiApplied" @select="aiSelect" @switch-market="switchMarket" @status="(s) => (aiStatus = s)"
              />
            </div>
          </NTabPane>

          <NTabPane name="settings" :tab="t('主题设置')">
            <div class="panel-body">
              <div v-if="kits.length" class="kits">
                <div class="kits-title">{{ t('风格包') }}<span>{{ t('按地区一键套用版式、字体和配色（保留品牌主色）') }}</span></div>
                <button v-for="k in kits" :key="k.key" type="button" class="kit" @click="applyKit(k.key)">
                  <strong>{{ k.name }}</strong><span>{{ k.description }}</span>
                </button>
              </div>
              <NCollapse :default-expanded-names="['colors']" accordion>
                <NCollapseItem v-for="(group, gkey) in schema.settings" :key="gkey" :title="group.label" :name="gkey">
                  <div v-for="(field, key) in group.fields" :key="gkey + key" class="fwrap" :class="{ over: settingOver(gkey, key) }">
                    <FieldInput v-model="config.settings[gkey]![key]" :field="field" :media-urls="mediaUrls" @uploaded="onUploaded" />
                    <div v-if="settingOver(gkey, key)" class="over-bar">
                      <span>{{ t('本市场') }}</span>
                      <button type="button" @click="restoreSetting(gkey, key)">{{ t('恢复主站点') }}</button>
                    </div>
                    <div v-else-if="overriddenBy(`settings.${gkey}.${key}`).length" class="mk-note">
                      {{ t('{0} 单独设置了这一项，这里的修改不会影响这些站点', [overriddenBy(`settings.${gkey}.${key}`).join('、')]) }}
                    </div>
                  </div>
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
            :src="proposalUrl ?? previewUrl"
            :title="t('店铺预览')"
            @load="frameLoading = false"
          />
          <div v-if="frameLoading" class="frame-loading"><NSpin size="small" /></div>
        </div>
      </section>
    </div>

    <NModal v-model:show="showCheck" preset="card" style="max-width: 560px"
      :title="checkMode === 'generated' ? t('已生成草稿') : checkMode === 'publish' ? t('发布前检查') : t('装修检查')">
      <ul v-if="notes.length" class="notes">
        <li v-for="(n, i) in notes" :key="i">{{ n }}</li>
      </ul>
      <p v-if="!issues.length" class="ok">✓ {{ t('没有发现问题') }}</p>
      <div v-for="(i, n) in issues" :key="n" class="issue" :class="i.level">
        <NTag size="tiny" :bordered="false" :type="levelType[i.level]">{{ levelLabel[i.level] }}</NTag>
        <span class="issue-msg">{{ i.message }}</span>
        <NButton v-if="i.block || i.place || i.code.startsWith('contrast') || i.code === 'untranslated'" size="tiny" quaternary @click="goTo(i)">{{ t('去修改') }}</NButton>
      </div>
      <template #footer>
        <div class="check-foot">
          <NButton v-if="checkMode === 'publish'" @click="showCheck = false">{{ t('返回修改') }}</NButton>
          <NButton v-if="checkMode === 'publish'" :type="issues.some((i) => i.level === 'error') ? 'error' : 'primary'" @click="confirmPublish">{{ t('仍然发布') }}</NButton>
          <NButton v-else type="primary" @click="showCheck = false">{{ t('知道了') }}</NButton>
        </div>
      </template>
    </NModal>

    <ThemeSchedulesDrawer v-model:show="showSchedules" :theme-id="themeId" :market-id="marketId" :market-name="market?.name ?? null" />

    <NDrawer v-model:show="showVersions" :width="380" placement="right">
      <NDrawerContent :title="market ? t('历史版本 · {0}', [market.name]) : t('历史版本 · 主站点')" closable>
        <p class="hint" style="margin-top: 0">{{ t('每次发布都会保存一个版本（最近 30 个）。恢复的版本先放进草稿，发布后才会生效。') }}</p>
        <NEmpty v-if="!versions.length" :description="t('还没有发布过')" style="padding: 30px 0" />
        <div v-for="(v, i) in versions" :key="v.id" class="version">
          <div>
            <div class="v-title">{{ formatDate(v.created_at) }}<NTag v-if="i === 0" size="tiny" :bordered="false" type="success" style="margin-left: 6px">{{ t('当前线上') }}</NTag></div>
            <div class="hint" style="margin: 2px 0 0">{{ v.by ?? '—' }}{{ v.note ? ' · ' + v.note : '' }}</div>
          </div>
          <NButton v-if="i > 0" size="tiny" secondary @click="restoreVersion(v)">{{ t('恢复') }}</NButton>
        </div>
      </NDrawerContent>
    </NDrawer>
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
.market-pick { width: 190px; }
.proposal-note { padding: 8px 16px; font-size: 12.5px; background: #FFF7E6; color: #8A5A00; border-bottom: 1px solid var(--border); }
.notes { margin: 0 0 14px; padding-inline-start: 18px; font-size: 13px; color: var(--ink-muted); line-height: 1.7; }
.ok { color: var(--brand-700); font-size: 14px; margin: 0; }
.issue { display: flex; gap: 8px; align-items: flex-start; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; line-height: 1.5; }
.issue-msg { flex: 1; }
.check-foot { display: flex; justify-content: flex-end; gap: 8px; }
.place-pick { display: flex; align-items: center; gap: 8px; padding: 10px 16px; border-bottom: 1px solid var(--border); font-size: 12.5px; color: var(--ink-muted); white-space: nowrap; }
.kits { display: grid; gap: 6px; margin-bottom: 16px; }
.kits-title { font-size: 13px; font-weight: 600; display: grid; gap: 2px; margin-bottom: 2px; }
.kits-title span { font-size: 12px; font-weight: 400; color: var(--ink-muted); }
.kit { display: grid; gap: 2px; text-align: start; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); cursor: pointer; font: inherit; color: var(--ink); }
.kit:hover { border-color: var(--brand-300); background: var(--brand-50); }
.kit strong { font-size: 13px; font-weight: 600; }
.kit span { font-size: 12px; color: var(--ink-muted); line-height: 1.4; }
.mk-note.home-note { margin: 0; border-radius: 0; padding: 8px 16px; }
.mk-note { margin: -4px 0 10px; padding: 6px 10px; border-radius: 6px; background: #fffbeb; color: #92400e; font-size: 12px; line-height: 1.5; }
.market-note { padding: 10px 16px; font-size: 12.5px; line-height: 1.5; background: var(--brand-50); color: var(--brand-700); border-bottom: 1px solid var(--border); }
.mode { margin-bottom: 12px; font-size: 12.5px; }
.mode :deep(.n-alert-body__content) { display: grid; justify-items: start; }
.sec.fixed { cursor: default; }
.fwrap { position: relative; }
.fwrap.over { border-left: 2px solid var(--brand-500, #12A06B); padding-left: 10px; margin-left: -12px; }
.over-bar { display: flex; align-items: center; gap: 8px; margin: -6px 0 12px; font-size: 12px; color: var(--brand-700); }
.over-bar button { border: none; background: none; padding: 0; color: var(--ink-muted); text-decoration: underline; cursor: pointer; font: inherit; }
.version { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border); }
.v-title { font-size: 14px; font-weight: 500; }
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
