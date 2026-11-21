<script setup lang="ts">
/**
 * 获客雷达 (Lead finder): search a market for B2B buyers of the merchant's product, read their
 * websites for contact details, score them, then work them like a pipeline.
 */
import { computed, defineAsyncComponent, h, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import {
  NAlert, NButton, NCard, NCheckbox, NDataTable, NDropdown, NDynamicTags, NEmpty, NIcon, NInput, NInputNumber, NModal, NPopconfirm, NProgress, NRadioButton,
  NRadioGroup, NSelect, NSkeleton, useMessage, type DataTableColumns, type DataTableRowKey,
} from 'naive-ui'
import {
  AlarmOutline, CallOutline, CheckmarkCircle, CloudDownloadOutline, GlobeOutline, LinkOutline, ListOutline, LogoWhatsapp, MailOutline, MapOutline,
  RadioOutline, SearchOutline, SettingsOutline, SparklesOutline, Star, StarOutline, TrashOutline,
} from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { download } from '@/utils/download'
import {
  SEGMENT_LABELS, SEGMENT_OPTIONS, STATUS_LABELS, STATUS_OPTIONS, dueText, emailBadge, flag,
  type LeadMeta, type LeadRow, type LeadSearch, type LeadSettings, type LeadStats, type MapLead,
} from '@/api/leads'
import ScoreRing from '@/components/leads/ScoreRing.vue'
import LeadDrawer from '@/components/leads/LeadDrawer.vue'
const LeadMap = defineAsyncComponent(() => import('@/components/leads/LeadMap.vue'))
import LeadSettingsModal from '@/components/leads/LeadSettingsModal.vue'
import { t } from '@/i18n'

const message = useMessage()
const meta = ref<LeadMeta | null>(null)
const settings = computed<LeadSettings | null>(() => meta.value?.settings ?? null)
const showSettings = ref(false)
const countryName = (c: string | null) => meta.value?.countries.find((x) => x.code === c)?.name ?? c ?? ''

// ---------- AI: describe the buyers in one sentence ----------
const aiText = ref('')
const planning = ref(false)
const planNote = ref('')
const aiExamples = [t('帮我找墨西哥城和瓜达拉哈拉做手机配件批发的公司'), t('德国的手机维修连锁店'), t('迪拜做电子产品进口的贸易商')]
async function aiPlan() {
  if (!settings.value?.ai_ready) { showSettings.value = true; return }
  if (aiText.value.trim().length < 4) return message.warning(t('请描述一下你要找的客户'))
  planning.value = true
  try {
    const { data } = await http.post('/lead-finder/plan', { text: aiText.value }, { timeout: 90000 })
    const p = data.data
    skipReset = true
    Object.assign(form, { industry: p.industry, country: p.country, cities: p.cities, keywords: p.keywords })
    await nextTick()
    skipReset = false
    planNote.value = p.explain
    message.success(t('AI 已生成搜索条件，确认后点「开始搜索」'))
  } catch (e) { message.error(errorMessage(e)) } finally { planning.value = false }
}
let skipReset = false

// ---------- New search ----------
const form = reactive({ industry: 'phone_film', country: 'US', cities: [] as string[], keywords: [] as string[], source: 'osm' as 'google' | 'osm', limit: 60 })
const composerOpen = ref(true)
const starting = ref(false)
const profile = computed(() => meta.value?.profiles.find((p) => p.key === form.industry))
const country = computed(() => meta.value?.countries.find((c) => c.code === form.country))
const countryOptions = computed(() => (meta.value?.countries ?? []).map((c) => ({ label: `${flag(c.code)}  ${c.name} · ${c.en}`, value: c.code })))
const citySuggestions = computed(() => (country.value?.cities ?? []).filter((c) => !form.cities.includes(c)))
const keywordSuggestions = computed(() => (meta.value?.keywords[form.industry]?.[form.country] ?? []).filter((k) => !form.keywords.includes(k)))
function resetKeywords() {
  if (skipReset) { skipReset = false; return }   // AI just filled these in
  planNote.value = ''
  form.keywords = (meta.value?.keywords[form.industry]?.[form.country] ?? []).slice(0, 4)
  form.cities = (country.value?.cities ?? []).slice(0, 2)
}
watch(() => [form.industry, form.country], resetKeywords)
const estimate = computed(() => {
  const areas = Math.max(1, form.cities.length)
  if (form.source === 'google') return t('约 {0} 次 Google 地图请求', [Math.min(areas * form.keywords.length * 3, Math.ceil(form.limit / 20) * areas * form.keywords.length)])
  return t('免费')
})

async function startSearch() {
  if (!form.keywords.length) return message.warning(t('请至少填写一个搜索关键词'))
  if (form.source === 'google' && !settings.value?.google_ready) { showSettings.value = true; return message.info(t('请先填写 Google 地图 API Key')) }
  starting.value = true
  try {
    await http.post('/lead-searches', form)
    message.success(t('已开始搜索，结果会陆续出现在下方'))
    composerOpen.value = false
    loadSearches()
  } catch (e) { message.error(errorMessage(e)) } finally { starting.value = false }
}

// ---------- Import websites ----------
const showImport = ref(false)
const importText = ref('')
const importing = ref(false)
async function importSites() {
  importing.value = true
  try {
    await http.post('/lead-searches/import', { websites: importText.value, industry: form.industry, country: form.country })
    message.success(t('已开始分析这些网站'))
    showImport.value = false
    importText.value = ''
    loadSearches()
  } catch (e) { message.error(errorMessage(e)) } finally { importing.value = false }
}

// ---------- Searches (live progress) ----------
const searches = ref<LeadSearch[]>([])
const active = computed(() => searches.value.filter((s) => s.status === 'queued' || s.status === 'running'))
const recent = computed(() => searches.value.filter((s) => s.status !== 'queued' && s.status !== 'running').slice(0, 6))
let poll: ReturnType<typeof setTimeout> | undefined
let lastProgress = ''
async function loadSearches() {
  clearTimeout(poll)
  const before = active.value.length
  searches.value = (await http.get('/lead-searches')).data.data
  // Re-load the table only when a running search made progress — not on every tick
  const progress = active.value.map((s) => `${s.id}:${s.new_leads}:${s.enriched}`).join('|')
  if (active.value.length) {
    if (progress !== lastProgress) load(true)
    poll = setTimeout(loadSearches, 4000)
  } else if (before) {
    load(true)
  }
  lastProgress = progress
}
function progressOf(s: LeadSearch) {
  if (s.status === 'queued') return 2
  if (s.stage === 'searching') return 10
  return s.to_enrich ? Math.round(12 + (88 * s.enriched) / s.to_enrich) : 12
}
function stageText(s: LeadSearch) {
  if (s.status === 'queued') return t('排队中…（如果一直不动，请确认队列进程 queue:work 已启动）')
  if (s.stage === 'searching') return t('正在搜索地图商家… 已找到 {0} 家', [s.found])
  return t('正在读取官网、提取联系方式并评分… {0} / {1}', [s.enriched, s.to_enrich])
}
async function cancelSearch(s: LeadSearch) {
  await http.delete(`/lead-searches/${s.id}`)
  loadSearches()
}

// ---------- Leads ----------
const rows = ref<LeadRow[]>([])
const stats = ref<LeadStats | null>(null)
const loading = ref(false)
const filters = reactive({ q: '', grade: null as string | null, segment: null as string | null, status: null as string | null, has_email: false,
  starred: false, due: false, country: null as string | null, search_id: null as number | null, sort: 'score' })
const view = ref<'list' | 'map'>('list')
const mapLeads = ref<MapLead[]>([])
const mapLoading = ref(false)
async function loadMap() {
  mapLoading.value = true
  try { mapLeads.value = (await http.get('/leads/map', { params: params() })).data.data } finally { mapLoading.value = false }
}
watch(view, (v) => { if (v === 'map') loadMap() })
const pagination = reactive({ page: 1, pageSize: 25, itemCount: 0, showSizePicker: true, pageSizes: [25, 50, 100] })
const checked = ref<DataTableRowKey[]>([])

function params() {
  return {
    q: filters.q || undefined, grade: filters.grade || undefined, segment: filters.segment || undefined, status: filters.status || undefined,
    has_email: filters.has_email ? 1 : undefined, starred: filters.starred ? 1 : undefined, due: filters.due ? 1 : undefined, country: filters.country || undefined,
    search_id: filters.search_id || undefined, sort: filters.sort,
  }
}
async function load(quiet = false) {
  if (!quiet) loading.value = true
  try {
    const { data } = await http.get('/leads', { params: { ...params(), page: pagination.page, per_page: pagination.pageSize } })
    rows.value = data.data
    pagination.itemCount = data.total
    stats.value = data.stats
  } finally { loading.value = false }
  if (view.value === 'map') loadMap()
}
let qTimer: ReturnType<typeof setTimeout> | undefined
watch(() => filters.q, () => { clearTimeout(qTimer); qTimer = setTimeout(() => { pagination.page = 1; load() }, 300) })
watch(() => [filters.grade, filters.segment, filters.status, filters.has_email, filters.starred, filters.due, filters.country, filters.search_id, filters.sort], () => { pagination.page = 1; load() })

function showDue() {
  Object.assign(filters, { due: true, grade: null, status: null, sort: 'follow_up' })
  view.value = 'list'
  document.querySelector('.table-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function quick(row: LeadRow, body: Record<string, unknown>) {
  Object.assign(row, body)
  try { await http.patch(`/leads/${row.id}`, body); load(true) } catch (e) { message.error(errorMessage(e)) }
}

const gradeTabs = computed(() => [
  { label: t('全部'), value: null },
  { label: t('A 级 · 高意向'), value: 'A' }, { label: 'B', value: 'B' }, { label: 'C', value: 'C' }, { label: 'D', value: 'D' },
])

const columns = computed<DataTableColumns<LeadRow>>(() => [
  { type: 'selection', width: 40 },
  { title: t('评分'), key: 'score', width: 70, render: (r) => h(ScoreRing, { score: r.score, size: 40 }) },
  {
    title: t('公司'), key: 'name', minWidth: 280,
    render: (r) => h('div', { class: 'co' }, [
      h('div', { class: 'co-name' }, [
        h('button', { class: ['star', { on: r.starred }], 'aria-label': t('标星'), onClick: (e: Event) => { e.stopPropagation(); quick(r, { starred: !r.starred }) } },
          [h(NIcon, { size: 15 }, { default: () => h(r.starred ? Star : StarOutline) })]),
        h('span', { class: 'nm' }, r.name),
        r.ai_scored ? h('span', { class: 'ai', title: t('已经过 AI 分析') }, [h(NIcon, { size: 11 }, { default: () => h(SparklesOutline) })]) : null,
      ]),
      h('div', { class: 'co-sub' }, [`${flag(r.country)} ${[r.city, countryName(r.country)].filter(Boolean).join(' · ')}`, r.rating ? `  ·  ★ ${r.rating} (${r.reviews_count})` : '']),
      r.summary ? h('div', { class: 'co-sum' }, r.summary) : (r.score_reasons[0] ? h('div', { class: 'co-sum' }, r.score_reasons[0]) : null),
    ]),
  },
  { title: t('客户类型'), key: 'segment', width: 120, render: (r) => h('span', { class: ['seg', r.segment] }, SEGMENT_LABELS[r.segment]) },
  {
    title: t('联系方式'), key: 'contacts', width: 210,
    render: (r) => {
      const items: ReturnType<typeof h>[] = []
      if (r.emails.length) items.push(h('span', { class: 'ct on', title: r.emails.join('\n') }, [h(NIcon, { size: 14 }, { default: () => h(MailOutline) }), r.emails.length > 1 ? ` ${r.emails.length}` : '']))
      if (r.phone) items.push(h('span', { class: 'ct on', title: r.phone }, [h(NIcon, { size: 14 }, { default: () => h(CallOutline) })]))
      if (r.socials.whatsapp) items.push(h('span', { class: 'ct on', title: 'WhatsApp' }, [h(NIcon, { size: 14 }, { default: () => h(LogoWhatsapp) })]))
      if (r.website) items.push(h('span', { class: 'ct on', title: r.domain ?? '' }, [h(NIcon, { size: 14 }, { default: () => h(GlobeOutline) })]))
      const social = Object.keys(r.socials).filter((k) => k !== 'whatsapp').length
      if (social) items.push(h('span', { class: 'ct on', title: Object.keys(r.socials).join(', ') }, [h(NIcon, { size: 14 }, { default: () => h(LinkOutline) }), ` ${social}`]))
      const first = r.emails[0] ?? r.phone
      const badge = r.emails[0] ? emailBadge(r.email_checks[r.emails[0]]) : null
      return h('div', { class: 'cts' }, [h('div', { class: 'ct-icons' }, items.length ? items : [h('span', { class: 'muted' }, r.enrich_status === 'pending' ? t('读取中…') : '—')]),
        first ? h('div', { class: 'ct-first' }, [
          badge?.tone === 'ok' ? h(NIcon, { size: 12, class: 'ok-ico' }, { default: () => h(CheckmarkCircle) }) : null,
          h('span', first),
          badge && badge.tone === 'warn' ? h('span', { class: 'eb warn' }, badge.label) : null,
        ]) : null])
    },
  },
  {
    title: t('状态'), key: 'status', width: 140,
    render: (r) => {
      const due = dueText(r.next_follow_up_at)
      return h('div', { class: 'st' }, [
        h(NDropdown, { trigger: 'click', options: STATUS_OPTIONS.map((o) => ({ label: o.label, key: o.value })), size: 'small', onSelect: (v: string) => quick(r, { status: v }) },
          { default: () => h('button', { class: ['pill', r.status], onClick: (e: Event) => e.stopPropagation() }, [STATUS_LABELS[r.status], h('span', { class: 'caret' }, '▾')]) }),
        due ? h('div', { class: ['due', { overdue: due.overdue }] }, [h(NIcon, { size: 12 }, { default: () => h(AlarmOutline) }), due.text]) : null,
      ])
    },
  },
])

// Bulk
const bulkBusy = ref(false)
async function bulk(action: string, extra: Record<string, unknown> = {}) {
  bulkBusy.value = true
  try {
    const { data } = await http.post('/leads/bulk', { ids: checked.value, action, ...extra })
    if (data.search) { message.success(action === 'analyze' ? t('AI 分析已开始') : t('已开始重新抓取')); loadSearches() }
    if (action === 'delete') checked.value = []
    load(true)
  } catch (e) { message.error(errorMessage(e)) } finally { bulkBusy.value = false }
}
const bulkStatus = ref<string | null>(null)
watch(bulkStatus, (v) => { if (v) { bulk('status', { status: v }); bulkStatus.value = null } })

async function exportCsv() {
  const sel = checked.value.length ? { ids: checked.value.join(',') } : {}
  await download('/leads/export', { ...params(), ...sel }, 'leads.csv')
}

// Drawer
const openId = ref<number | null>(null)

const searchOptions = computed(() => searches.value.map((s) => ({ label: s.name, value: s.id })))
const funnel = computed(() => {
  const s = stats.value
  if (!s) return []
  return [
    { label: t('线索'), value: s.total }, { label: t('有邮箱'), value: s.with_email }, { label: t('已联系'), value: s.contacted },
    { label: t('已回复'), value: s.replied }, { label: t('成交'), value: s.won },
  ]
})

onMounted(async () => {
  const metaReq = http.get('/lead-finder/meta')
  await Promise.all([
    metaReq.then(({ data }) => {
      meta.value = data
      if (data.settings.google_ready) form.source = 'google'
      resetKeywords()
    }),
    load(), loadSearches(),
  ])
  if (stats.value?.total) composerOpen.value = false
})
onBeforeUnmount(() => clearTimeout(poll))
</script>

<template>
  <div class="lf">
    <div class="page-head">
      <div>
        <div class="eyebrow">Lead Finder</div>
        <h1 class="page-title">{{ t('获客雷达') }}</h1>
        <p class="page-sub">{{ t('输入产品和目标市场，自动找到当地的批发商、维修店和零售商，读取他们官网公开的联系方式，按匹配度打分，并一键生成当地语言的开发信。') }}</p>
      </div>
      <div class="head-acts">
        <NButton @click="showImport = true"><template #icon><NIcon><LinkOutline /></NIcon></template>{{ t('导入网址') }}</NButton>
        <NButton @click="exportCsv"><template #icon><NIcon><CloudDownloadOutline /></NIcon></template>{{ checked.length ? t('导出选中') : t('导出') }}</NButton>
        <NButton @click="showSettings = true"><template #icon><NIcon><SettingsOutline /></NIcon></template>{{ t('设置') }}</NButton>
      </div>
    </div>

    <!-- KPI -->
    <div class="kpis">
      <template v-if="stats">
        <div class="kpi"><div class="k-label">{{ t('线索总数') }}</div><div class="k-val">{{ stats.total }}</div><div class="k-sub">{{ t('本周新增 {0}', [stats.this_week]) }}</div></div>
        <div class="kpi"><div class="k-label">{{ t('A 级高意向') }}</div><div class="k-val">{{ stats.grade_a }}</div><div class="k-sub">{{ t('评分 ≥ 75') }}</div></div>
        <button class="kpi kpi-btn" :class="{ hot: stats.due_today > 0, on: filters.due }" @click="showDue">
          <div class="k-label"><NIcon size="13"><AlarmOutline /></NIcon> {{ t('今日待跟进') }}</div>
          <div class="k-val">{{ stats.due_today }}</div>
          <div class="k-sub">{{ stats.due_today ? t('点击查看') : t('暂无到期提醒') }}</div>
        </button>
        <div class="kpi"><div class="k-label">{{ t('有邮箱') }}</div><div class="k-val">{{ stats.with_email }}</div><div class="k-sub">{{ stats.total ? Math.round((100 * stats.with_email) / stats.total) : 0 }}%{{ stats.guessed_email ? ` · ${t('另有 {0} 个推测', [stats.guessed_email])}` : '' }} · {{ t('有电话 {0}', [stats.with_phone]) }}</div></div>
        <div class="kpi wide">
          <div class="k-label">{{ t('跟进漏斗') }}</div>
          <div class="funnel">
            <div v-for="(f, i) in funnel" :key="f.label" class="f-step">
              <div class="f-bar"><div class="f-fill" :style="{ width: `${funnel[0].value ? Math.max(4, (100 * f.value) / funnel[0].value) : 0}%`, opacity: 1 - i * 0.15 }" /></div>
              <div class="f-meta"><span>{{ f.label }}</span><b>{{ f.value }}</b></div>
            </div>
          </div>
        </div>
      </template>
      <NSkeleton v-else height="96px" :sharp="false" style="grid-column: 1 / -1; border-radius: 12px" />
    </div>

    <NAlert v-if="settings && !settings.google_ready && !settings.ai_ready" type="success" :bordered="false" class="setup">
      {{ t('现在用的是免费的 OpenStreetMap 数据。接入 Google 地图可以找到更多带电话和官网的商家，接入 AI 后会自动判断客户匹配度并写开发信。') }}
      <a href="#" @click.prevent="showSettings = true">{{ t('去设置') }} →</a>
    </NAlert>

    <!-- Composer -->
    <NCard class="composer" :class="{ closed: !composerOpen }" content-style="padding: 0">
      <button class="comp-head" :aria-expanded="composerOpen" @click="composerOpen = !composerOpen">
        <span class="comp-ico"><NIcon size="18"><RadioOutline /></NIcon></span>
        <span class="comp-title">{{ t('新建搜索') }}</span>
        <span v-if="!composerOpen" class="muted">{{ profile?.label }} · {{ flag(form.country) }} {{ country?.name }}</span>
        <span class="comp-chev">{{ composerOpen ? t('收起') : t('展开') }}</span>
      </button>
      <div v-if="composerOpen && meta" class="comp-body">
        <div class="ai-box">
          <div class="ai-row">
            <NIcon size="18" class="ai-ico"><SparklesOutline /></NIcon>
            <NInput v-model:value="aiText" :placeholder="t('用一句话描述你要找的客户，AI 自动生成搜索条件')" :bordered="false" class="ai-input" @keyup.enter="aiPlan" />
            <NButton type="primary" secondary :loading="planning" @click="aiPlan">{{ settings?.ai_ready ? t('AI 生成') : t('开启 AI') }}</NButton>
          </div>
          <div class="ai-ex">
            <span class="muted">{{ t('试试：') }}</span>
            <button v-for="ex in aiExamples" :key="ex" @click="aiText = ex">{{ ex }}</button>
          </div>
          <div v-if="planNote" class="ai-note"><NIcon size="14"><CheckmarkCircle /></NIcon> {{ planNote }}</div>
        </div>
        <div class="field">
          <label>{{ t('我卖什么') }}</label>
          <div class="chips">
            <button v-for="p in meta.profiles" :key="p.key" class="pchip" :class="{ on: form.industry === p.key }" @click="form.industry = p.key">{{ p.label }}</button>
          </div>
          <div v-if="profile?.buyers.length" class="buyers">{{ t('会帮你找：') }}<span v-for="b in profile.buyers" :key="b">{{ b }}</span></div>
        </div>
        <div class="grid2">
          <div class="field">
            <label>{{ t('目标国家') }}</label>
            <NSelect v-model:value="form.country" :options="countryOptions" filterable />
          </div>
          <div class="field">
            <label>{{ t('城市') }} <span class="muted">{{ form.source === 'google' ? t('（不填 = 全国）') : t('（OpenStreetMap 需要城市）') }}</span></label>
            <NDynamicTags v-model:value="form.cities" :max="8" />
            <div v-if="citySuggestions.length" class="sugg">
              <button v-for="c in citySuggestions.slice(0, 6)" :key="c" @click="form.cities.push(c)">+ {{ c }}</button>
            </div>
          </div>
        </div>
        <div class="field">
          <label>{{ t('搜索关键词') }} <span class="muted">{{ t('已按当地语言推荐，可以改') }}</span></label>
          <NDynamicTags v-model:value="form.keywords" :max="8" />
          <div v-if="keywordSuggestions.length" class="sugg">
            <button v-for="k in keywordSuggestions" :key="k" @click="form.keywords.push(k)">+ {{ k }}</button>
          </div>
        </div>
        <div class="grid2 bottom">
          <div class="field">
            <label>{{ t('数据来源') }}</label>
            <NRadioGroup v-model:value="form.source">
              <NRadioButton value="google">Google {{ t('地图') }}{{ settings?.google_ready ? '' : ` · ${t('需配置')}` }}</NRadioButton>
              <NRadioButton value="osm">OpenStreetMap · {{ t('免费') }}</NRadioButton>
            </NRadioGroup>
          </div>
          <div class="field">
            <label>{{ t('最多找多少家') }}</label>
            <div class="go">
              <NInputNumber v-model:value="form.limit" :min="10" :max="300" :step="20" style="width: 120px" />
              <span class="muted est">{{ estimate }}{{ settings?.ai_auto && settings.ai_ready ? ` · ${t('含 AI 分析')}` : '' }}</span>
              <NButton type="primary" size="large" :loading="starting" class="go-btn" @click="startSearch">
                <template #icon><NIcon><SearchOutline /></NIcon></template>{{ t('开始搜索') }}
              </NButton>
            </div>
          </div>
        </div>
      </div>
    </NCard>

    <!-- Live searches -->
    <div v-if="active.length" class="runs">
      <div v-for="s in active" :key="s.id" class="run">
        <div class="run-top">
          <span class="pulse" />
          <b>{{ s.name }}</b>
          <span class="muted">{{ stageText(s) }}</span>
          <NButton size="tiny" quaternary @click="cancelSearch(s)">{{ t('取消') }}</NButton>
        </div>
        <NProgress type="line" :percentage="progressOf(s)" :show-indicator="false" :height="6" color="#14A06E" rail-color="#E8EEEA" />
      </div>
    </div>
    <div v-if="recent.length" class="recent">
      <span class="muted">{{ t('最近搜索：') }}</span>
      <button v-for="s in recent" :key="s.id" class="rchip" :class="{ on: filters.search_id === s.id, failed: s.status === 'failed' }"
        :title="s.error ?? ''" @click="filters.search_id = filters.search_id === s.id ? null : s.id">
        {{ s.name }} <span v-if="s.status === 'failed'">· {{ t('失败') }}</span><span v-else>· {{ t('{0} 家', [s.found]) }}</span>
      </button>
    </div>
    <NAlert v-for="s in recent.filter((x) => x.status === 'failed' && x.error && Date.now() - new Date(x.finished_at ?? x.created_at).getTime() < 3600e3).slice(0, 1)" :key="`e${s.id}`"
      type="warning" :bordered="false" class="setup">{{ s.name }}：{{ s.error }}</NAlert>

    <!-- Leads -->
    <NCard content-style="padding: 0" class="table-card">
      <div class="toolbar">
        <div class="seg-tabs">
          <button v-for="g in gradeTabs" :key="String(g.value)" :class="{ on: filters.grade === g.value }" @click="filters.grade = g.value">{{ g.label }}</button>
        </div>
        <div class="filters">
          <NInput v-model:value="filters.q" size="small" clearable :placeholder="t('搜索公司、域名、城市、邮箱')" style="width: 220px">
            <template #prefix><NIcon><SearchOutline /></NIcon></template>
          </NInput>
          <NSelect v-model:value="filters.segment" size="small" clearable :options="SEGMENT_OPTIONS" :placeholder="t('客户类型')" style="width: 130px" />
          <NSelect v-model:value="filters.status" size="small" clearable :options="STATUS_OPTIONS" :placeholder="t('状态')" style="width: 110px" />
          <NSelect v-model:value="filters.country" size="small" clearable filterable :options="countryOptions" :placeholder="t('国家')" style="width: 150px" />
          <NSelect v-model:value="filters.search_id" size="small" clearable :options="searchOptions" :placeholder="t('搜索任务')" style="width: 170px" />
          <NCheckbox v-model:checked="filters.has_email" size="small">{{ t('有邮箱') }}</NCheckbox>
          <NCheckbox v-model:checked="filters.starred" size="small">{{ t('已标星') }}</NCheckbox>
          <NCheckbox v-model:checked="filters.due" size="small">{{ t('待跟进') }}</NCheckbox>
          <NSelect v-model:value="filters.sort" size="small" style="width: 120px"
            :options="[{ label: t('按评分'), value: 'score' }, { label: t('最新加入'), value: 'newest' }, { label: t('评价最多'), value: 'reviews' }, { label: t('跟进时间'), value: 'follow_up' }, { label: t('按名称'), value: 'name' }]" />
          <div class="view-toggle" role="group" :aria-label="t('视图')">
            <button :class="{ on: view === 'list' }" @click="view = 'list'"><NIcon size="15"><ListOutline /></NIcon>{{ t('列表') }}</button>
            <button :class="{ on: view === 'map' }" @click="view = 'map'"><NIcon size="15"><MapOutline /></NIcon>{{ t('地图') }}</button>
          </div>
        </div>
      </div>
      <div v-if="checked.length" class="bulkbar">
        <b>{{ t('已选 {0} 条', [checked.length]) }}</b>
        <NSelect v-model:value="bulkStatus" size="small" :options="STATUS_OPTIONS" :placeholder="t('改状态')" style="width: 120px" />
        <NButton size="small" :loading="bulkBusy" @click="bulk('analyze')"><template #icon><NIcon><SparklesOutline /></NIcon></template>{{ t('AI 分析') }}</NButton>
        <NButton size="small" :loading="bulkBusy" @click="bulk('recheck')">{{ t('重新抓取官网') }}</NButton>
        <NButton size="small" @click="bulk('star')"><template #icon><NIcon><Star /></NIcon></template>{{ t('标星') }}</NButton>
        <NPopconfirm @positive-click="bulk('delete')">
          <template #trigger><NButton size="small" quaternary><template #icon><NIcon><TrashOutline /></NIcon></template>{{ t('删除') }}</NButton></template>
          {{ t('删除这 {0} 条线索？', [checked.length]) }}
        </NPopconfirm>
        <NButton size="small" quaternary @click="checked = []">{{ t('取消选择') }}</NButton>
      </div>

      <NEmpty v-if="!loading && !rows.length && !stats?.total" class="empty" :description="t('还没有线索。在上面选择产品和国家，点「开始搜索」，几分钟后这里就会出现带联系方式的潜在客户。')">
        <template #extra><NButton type="primary" @click="composerOpen = true">{{ t('新建搜索') }}</NButton></template>
      </NEmpty>
      <LeadMap v-else-if="view === 'map'" :leads="mapLeads" :loading="mapLoading" @open="(id: number) => (openId = id)" />
      <NDataTable v-else v-model:checked-row-keys="checked" remote :row-key="(r: LeadRow) => r.id" :columns="columns" :data="rows" :loading="loading"
        :bordered="false" :pagination="pagination" :scroll-x="900" :row-props="(r: LeadRow) => ({ style: 'cursor:pointer', onClick: () => (openId = r.id) })"
        @update:page="(p: number) => { pagination.page = p; load() }" @update:page-size="(s: number) => { pagination.pageSize = s; pagination.page = 1; load() }" />
    </NCard>

    <p class="legal muted">{{ t('数据来自 Google 地图 / OpenStreetMap 的公开商家资料和对方官网公开的联系方式。开发信请一对一发送并提供退订方式，遵守当地法规（如欧盟 GDPR、美国 CAN-SPAM）。') }}</p>

    <LeadDrawer :id="openId" :ai-ready="!!settings?.ai_ready" :country-name="countryName" @close="openId = null" @changed="load(true)" @settings="showSettings = true" />
    <LeadSettingsModal v-if="settings" v-model:show="showSettings" :settings="settings" @saved="(s: LeadSettings) => { meta!.settings = s; if (s.google_ready) form.source = 'google' }" />

    <NModal v-model:show="showImport" preset="card" :title="t('导入网址')" style="width: 560px; max-width: calc(100vw - 32px)">
      <p class="muted" style="margin-top: 0">{{ t('已经有一批潜在客户的网站（展会名单、亚马逊店铺官网、同行的客户）？每行粘贴一个网址，系统会读取官网、提取联系方式并评分。最多 100 个。') }}</p>
      <NInput v-model:value="importText" type="textarea" :autosize="{ minRows: 8, maxRows: 14 }" placeholder="example-phonestore.com&#10;https://www.another-distributor.com" />
      <template #footer>
        <div style="display: flex; justify-content: flex-end; gap: 8px">
          <NButton @click="showImport = false">{{ t('取消') }}</NButton>
          <NButton type="primary" :loading="importing" :disabled="!importText.trim()" @click="importSites">{{ t('开始分析') }}</NButton>
        </div>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.page-sub { max-width: 720px; }
.head-acts { display: flex; gap: 8px; flex-wrap: wrap; }
.muted { color: var(--ink-muted); font-size: 12px; }

.kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)) minmax(0, 1.8fr); gap: 12px; margin-bottom: 16px; }
.kpi-btn { display: flex; flex-direction: column; align-items: stretch; justify-content: flex-start; text-align: left; font: inherit; color: inherit; cursor: pointer; transition: border-color .15s, background-color .15s; }
.kpi-btn:hover { border-color: var(--brand-300); }
.kpi-btn.hot { background: var(--brand-50); border-color: var(--brand-100); }
.kpi-btn.hot .k-val { color: var(--brand-700); }
.kpi-btn.on { border-color: var(--brand-500); }
.k-label { display: flex; align-items: center; gap: 4px; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 14px 16px; }
.k-label { font-size: 12px; color: var(--ink-muted); }
.k-val { font-family: var(--font-display); font-size: 26px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
.k-sub { font-size: 12px; color: var(--ink-muted); margin-top: 2px; }
.funnel { display: grid; gap: 3px; margin-top: 6px; }
.f-step { display: grid; grid-template-columns: 1fr 96px; align-items: center; gap: 10px; line-height: 1.35; }
.f-bar { height: 6px; background: #EEF2EF; border-radius: 99px; overflow: hidden; }
.f-fill { height: 100%; background: var(--brand-600); border-radius: 99px; transition: width .4s; }
.f-meta { display: flex; justify-content: space-between; font-size: 12px; color: var(--ink-muted); }
.f-meta b { color: var(--ink); font-weight: 600; font-variant-numeric: tabular-nums; }
.setup { margin-bottom: 14px; }
.setup a { color: var(--brand-700); font-weight: 500; margin-left: 6px; }

.composer { margin-bottom: 14px; border-color: var(--brand-100); }
.comp-head { width: 100%; display: flex; align-items: center; gap: 10px; padding: 14px 18px; background: none; border: 0; cursor: pointer; text-align: left; font: inherit; color: var(--ink); }
.comp-ico { width: 30px; height: 30px; border-radius: 8px; background: var(--brand-50); color: var(--brand-600); display: inline-flex; align-items: center; justify-content: center; }
.comp-title { font-weight: 600; font-size: 15px; }
.comp-chev { margin-left: auto; font-size: 12px; color: var(--brand-700); }
.ai-box { border: 1px solid var(--brand-100); background: linear-gradient(180deg, var(--brand-50), #fff); border-radius: 12px; padding: 10px 12px; display: grid; gap: 8px; }
.ai-row { display: flex; align-items: center; gap: 8px; }
.ai-ico { color: var(--brand-600); flex: none; }
.ai-input { flex: 1; background: transparent; }
.ai-input :deep(.n-input__input-el) { font-size: 14px; }
.ai-ex { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding-left: 26px; }
.ai-ex button { border: 0; background: #fff; border-radius: 99px; padding: 3px 10px; font-size: 12px; color: #3D4943; cursor: pointer; box-shadow: 0 0 0 1px var(--border); }
.ai-ex button:hover { color: var(--brand-700); box-shadow: 0 0 0 1px var(--brand-300); }
.ai-note { display: flex; align-items: center; gap: 6px; padding-left: 26px; font-size: 13px; color: var(--brand-800); }
.comp-body { padding: 4px 18px 18px; display: grid; gap: 16px; border-top: 1px solid var(--border); padding-top: 16px; }
.field { display: grid; gap: 8px; min-width: 0; }
.field > label { font-size: 13px; font-weight: 600; }
.field > label .muted { font-weight: 400; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.chips { display: flex; flex-wrap: wrap; gap: 8px; }
.pchip { border: 1px solid var(--border); background: var(--surface); border-radius: 99px; padding: 6px 14px; font: inherit; font-size: 13px; cursor: pointer; color: var(--ink); }
.pchip:hover { border-color: var(--brand-300); }
.pchip.on { background: var(--brand-50); border-color: var(--brand-500); color: var(--brand-800); font-weight: 600; }
.buyers { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 12px; color: var(--ink-muted); }
.buyers span { background: var(--surface-muted); border-radius: 6px; padding: 2px 8px; color: #3D4943; }
.sugg { display: flex; flex-wrap: wrap; gap: 6px; }
.sugg button { border: 1px dashed var(--brand-300); background: none; color: var(--brand-700); border-radius: 6px; padding: 2px 8px; font-size: 12px; cursor: pointer; }
.sugg button:hover { background: var(--brand-50); }
.go { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.est { flex: 1; min-width: 120px; }
.go-btn { min-width: 140px; }

.runs { display: grid; gap: 10px; margin-bottom: 14px; }
.run { background: var(--surface); border: 1px solid var(--brand-100); border-radius: 10px; padding: 12px 16px; display: grid; gap: 8px; }
.run-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; }
.run-top .muted { flex: 1; }
.pulse { width: 8px; height: 8px; border-radius: 50%; background: var(--brand-500); box-shadow: 0 0 0 0 rgba(20, 160, 110, .5); animation: pulse 1.6s infinite; }
@keyframes pulse { 70% { box-shadow: 0 0 0 8px rgba(20, 160, 110, 0); } 100% { box-shadow: 0 0 0 0 rgba(20, 160, 110, 0); } }
@media (prefers-reduced-motion: reduce) { .pulse { animation: none; } }
.recent { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
.rchip { border: 1px solid var(--border); background: var(--surface); border-radius: 99px; padding: 3px 10px; font-size: 12px; cursor: pointer; color: #3D4943; }
.rchip.on { border-color: var(--brand-500); background: var(--brand-50); color: var(--brand-800); }
.rchip.failed { color: var(--ink-muted); }

.table-card { overflow: hidden; }
.toolbar { display: grid; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--border); }
.seg-tabs { display: flex; gap: 4px; flex-wrap: wrap; }
.seg-tabs button { border: 0; background: none; padding: 5px 12px; border-radius: 7px; font: inherit; font-size: 13px; color: #5C6963; cursor: pointer; }
.seg-tabs button:hover { background: #F1F4F2; }
.seg-tabs button.on { background: var(--brand-50); color: var(--brand-800); font-weight: 600; }
.filters { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.view-toggle { display: inline-flex; margin-left: auto; border: 1px solid var(--border); border-radius: 8px; padding: 2px; background: var(--surface-muted); }
.view-toggle button { display: inline-flex; align-items: center; gap: 4px; border: 0; background: none; padding: 3px 10px; border-radius: 6px; font: inherit; font-size: 12.5px; color: #5C6963; cursor: pointer; }
.view-toggle button.on { background: #fff; color: var(--brand-800); font-weight: 600; box-shadow: 0 1px 2px rgba(16, 24, 20, .08); }
.bulkbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 10px 16px; background: var(--brand-50); border-bottom: 1px solid var(--brand-100); font-size: 13px; }
.empty { padding: 56px 16px; }
.legal { margin: 14px 2px 0; line-height: 1.6; }

:deep(.co) { min-width: 0; }
:deep(.co-name) { display: flex; align-items: center; gap: 4px; font-weight: 600; font-size: 14px; }
:deep(.co-name .nm) { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
:deep(.star) { border: 0; background: none; padding: 1px; cursor: pointer; color: #C5CEC9; display: inline-flex; flex: none; }
:deep(.star.on) { color: var(--brand-600); }
:deep(.ai) { display: inline-flex; color: var(--brand-600); background: var(--brand-50); border-radius: 99px; padding: 2px 4px; }
:deep(.co-sub) { font-size: 12px; color: var(--ink-muted); margin-top: 2px; white-space: pre; }
:deep(.co-sum) { font-size: 12.5px; color: #4A5650; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; }
:deep(.seg) { font-size: 12px; padding: 3px 8px; border-radius: 6px; background: var(--surface-muted); color: #3D4943; white-space: nowrap; }
:deep(.seg.wholesale) { background: var(--brand-100); color: var(--brand-800); font-weight: 600; }
:deep(.seg.repair), :deep(.seg.ecommerce), :deep(.seg.brand) { background: var(--brand-50); color: var(--brand-700); }
:deep(.cts) { min-width: 0; }
:deep(.ct-icons) { display: flex; gap: 6px; }
:deep(.ct) { display: inline-flex; align-items: center; gap: 2px; font-size: 11px; color: #B7C2BC; }
:deep(.ct.on) { color: var(--brand-600); }
:deep(.ct-first) { display: flex; align-items: center; gap: 4px; font-size: 12px; color: #4A5650; margin-top: 4px; min-width: 0; }
:deep(.ct-first > span:not(.eb)) { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
:deep(.ok-ico) { color: var(--brand-600); flex: none; }
:deep(.eb) { flex: none; font-size: 10.5px; padding: 0 5px; border-radius: 4px; background: var(--surface-muted); color: var(--ink-muted); }
:deep(.eb.warn) { background: #fff; color: #5C6963; box-shadow: inset 0 0 0 1px #C5CEC9; }
:deep(.st) { display: grid; gap: 4px; }
:deep(.due) { display: inline-flex; align-items: center; gap: 3px; font-size: 11.5px; color: var(--ink-muted); }
:deep(.due.overdue) { color: var(--brand-700); font-weight: 600; }
:deep(.pill) { display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 10px; border-radius: 99px; border: 1px solid var(--border); background: #fff; font: inherit; font-size: 12.5px; color: #3D4943; cursor: pointer; white-space: nowrap; }
:deep(.pill:hover) { border-color: var(--brand-300); }
:deep(.pill .caret) { font-size: 9px; color: var(--ink-muted); }
:deep(.pill.contacted), :deep(.pill.replied) { background: var(--brand-50); border-color: var(--brand-100); color: var(--brand-700); }
:deep(.pill.qualified), :deep(.pill.won) { background: var(--brand-100); border-color: var(--brand-300); color: var(--brand-800); font-weight: 600; }
:deep(.pill.lost) { color: var(--ink-muted); background: var(--surface-muted); }

@media (max-width: 1200px) { .kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); } .kpi.wide { grid-column: 1 / -1; } }
@media (max-width: 760px) { .grid2 { grid-template-columns: 1fr; } .kpis { grid-template-columns: 1fr 1fr; } }
</style>
