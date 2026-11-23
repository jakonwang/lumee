<script setup lang="ts">
/**
 * Marketing › Creators: find Instagram / TikTok / YouTube creators in a niche and country, score them,
 * write the first message in their language, and turn the ones who agree into affiliates.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { NButton, NCard, NDynamicTags, NEmpty, NInput, NInputNumber, NModal, NPagination, NSelect, NSpin, NTag, useDialog, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { compactNumber, flag } from '@/api/leads'
import ScoreRing from '@/components/leads/ScoreRing.vue'
import { t } from '@/i18n'

type Status = 'new' | 'contacted' | 'replied' | 'agreed' | 'declined'
type Platform = 'instagram' | 'tiktok' | 'youtube'
interface Creator {
  id: number; platform: Platform; handle: string; url: string; name: string; country: string | null; niche: string | null; followers: number | null
  emails: string[]; whatsapp: string | null; website: string | null; bio: string | null; score: number; score_reasons: string[]; status: Status; note: string | null
  affiliate: { id: number; code: string; link: string } | null; suggested_code: string | null
}

const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const rows = ref<Creator[]>([])
const meta = ref({ total: 0, page: 1, last_page: 1 })
const counts = ref<Record<string, number>>({})
const countries = ref<{ code: string; name: string }[]>([])
const ready = ref({ serp: false, instagram: false, ai: false })
const defaultRate = ref(10)
const filter = reactive({ status: null as Status | null, platform: null as Platform | null, page: 1 })

const STATUS: Record<Status, string> = { new: t('新发现'), contacted: t('已联系'), replied: t('已回复'), agreed: t('已合作'), declined: t('不合作') }
const PLATFORM: Record<Platform, string> = { instagram: 'Instagram', tiktok: 'TikTok', youtube: 'YouTube' }
const statusOptions = (Object.keys(STATUS) as Status[]).map((s) => ({ label: STATUS[s], value: s }))

async function load() {
  try {
    const d = (await http.get('/creators', { params: { status: filter.status || undefined, platform: filter.platform || undefined, page: filter.page } })).data
    rows.value = d.data
    meta.value = d.meta
    counts.value = d.counts
    countries.value = d.countries
    ready.value = d.ready
    defaultRate.value = d.default_rate
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => [filter.status, filter.platform], () => { filter.page = 1; load() })
watch(() => filter.page, load)

// Search
const search = reactive({ keywords: [] as string[], country: 'US', platforms: ['instagram', 'tiktok'] as Platform[] })
const searching = ref(false)
const countryOptions = computed(() => countries.value.map((c) => ({ label: `${flag(c.code)} ${c.name}`, value: c.code })))
function togglePlatform(p: Platform) {
  search.platforms = search.platforms.includes(p) ? search.platforms.filter((x) => x !== p) : [...search.platforms, p]
}
async function runSearch() {
  if (!search.keywords.length) return message.warning(t('请至少填写一个关键词'))
  if (!search.platforms.length) return message.warning(t('请至少选择一个平台'))
  searching.value = true
  try {
    const r = (await http.post('/creators/search', search, { timeout: 180000 })).data
    message.success(t('找到 {0} 个达人，其中 {1} 个是新的', [r.found, r.new]))
    filter.status = null
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    searching.value = false
  }
}

async function setStatus(c: Creator, status: Status) {
  try {
    const d = (await http.patch(`/creators/${c.id}`, { status })).data.data
    Object.assign(c, d)
  } catch (e) {
    message.error(errorMessage(e))
  }
}

// First message
const outreach = ref<{ creator: Creator; subject: string; message: string; message_zh: string | null; code: string; ai: boolean } | null>(null)
const writing = ref<number | null>(null)
async function write(c: Creator) {
  writing.value = c.id
  try {
    outreach.value = { creator: c, ...(await http.post(`/creators/${c.id}/outreach`, {}, { timeout: 90000 })).data.data }
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    writing.value = null
  }
}
async function copy(text: string) {
  try { await navigator.clipboard.writeText(text); message.success(t('已复制')) } catch { message.error(t('复制失败，请手动选择复制')) }
}
async function markContacted() {
  const c = outreach.value!.creator
  await setStatus(c, 'contacted')
  outreach.value = null
}

// Agreed → affiliate
const aff = reactive({ creator: null as Creator | null, email: '', code: '', rate: 10 })
const converting = ref(false)
function openAffiliate(c: Creator) {
  Object.assign(aff, { creator: c, email: c.emails[0] ?? '', code: c.suggested_code ?? '', rate: defaultRate.value })
}
async function convert() {
  converting.value = true
  try {
    const d = (await http.post(`/creators/${aff.creator!.id}/affiliate`, { email: aff.email, code: aff.code || null, commission_rate: aff.rate })).data.data
    Object.assign(aff.creator!, d)
    aff.creator = null
    message.success(t('已添加为推广员'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    converting.value = false
  }
}

function remove(c: Creator) {
  dialog.warning({
    title: t('删除「{0}」？', [c.name]), positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => { await http.delete(`/creators/${c.id}`); await load() },
  })
}
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Marketing</div>
        <h1 class="page-title">{{ t('达人合作') }}</h1>
        <p class="page-sub">{{ t('按领域和国家找 Instagram、TikTok、YouTube 达人，按粉丝量、领域相关度和联系方式打分；用对方的语言写合作邀请，同意合作后一键转为推广员（专属推广码，按销售额拿佣金）。') }}</p>
      </div>
    </div>

    <NCard size="small" :title="t('找达人')">
      <div class="search">
        <div class="field">
          <label>{{ t('领域关键词（最多 3 个，用当地语言更准）') }}</label>
          <NDynamicTags v-model:value="search.keywords" :max="3" />
        </div>
        <div class="field">
          <label>{{ t('国家') }}</label>
          <NSelect v-model:value="search.country" :options="countryOptions" filterable style="width: 220px" />
        </div>
        <div class="field">
          <label>{{ t('平台') }}</label>
          <div class="plats">
            <button v-for="(label, p) in PLATFORM" :key="p" type="button" class="pchip" :class="{ on: search.platforms.includes(p) }" :aria-pressed="search.platforms.includes(p)" @click="togglePlatform(p)">{{ label }}</button>
          </div>
        </div>
        <NButton type="primary" :loading="searching" :disabled="!ready.serp" @click="runSearch">{{ t('开始找') }}</NButton>
      </div>
      <p class="muted hint">
        <template v-if="!ready.serp">{{ t('需要先在「获客雷达 › 设置」里填写 SerpApi Key。') }}<RouterLink :to="{ name: 'leads' }">{{ t('去设置') }}</RouterLink></template>
        <template v-else>{{ t('通过 Google 搜索结果找公开的达人主页，每次最多 9 次搜索。') }}{{ ready.instagram ? t('Instagram 商业账号会补全准确的粉丝数和简介。') : '' }}</template>
      </p>
    </NCard>

    <NCard size="small" content-style="padding: 0">
      <div class="filters">
        <NSelect v-model:value="filter.status" size="small" clearable :placeholder="t('全部状态')" :options="statusOptions.map((o) => ({ ...o, label: `${o.label} ${counts[o.value] ?? 0}` }))" style="width: 150px" />
        <NSelect v-model:value="filter.platform" size="small" clearable :placeholder="t('全部平台')" :options="Object.entries(PLATFORM).map(([value, label]) => ({ value, label }))" style="width: 140px" />
        <span class="muted">{{ t('共 {0} 个', [meta.total]) }}</span>
      </div>
      <div v-if="loading" class="center"><NSpin /></div>
      <template v-else>
        <div v-for="c in rows" :key="c.id" class="row">
          <ScoreRing :score="c.score" />
          <div class="main">
            <div class="name">
              <a :href="c.url" target="_blank" rel="noopener">{{ c.name }}</a>
              <span class="muted">@{{ c.handle }} · {{ PLATFORM[c.platform] }}</span>
              <span v-if="c.followers" class="fans">{{ t('{0} 粉丝', [compactNumber(c.followers)]) }}</span>
              <span class="muted">{{ flag(c.country) }}</span>
            </div>
            <div v-if="c.bio" class="bio">{{ c.bio }}</div>
            <div class="muted reasons">{{ c.score_reasons.join(' · ') }}</div>
            <div class="contacts">
              <a v-for="e in c.emails" :key="e" :href="`mailto:${e}`">{{ e }}</a>
              <a v-if="c.whatsapp" :href="c.whatsapp" target="_blank" rel="noopener">WhatsApp</a>
              <span v-if="c.affiliate" class="aff">{{ t('推广码') }} <code>{{ c.affiliate.code }}</code>
                <button type="button" class="copy" @click="copy(c.affiliate.link)">{{ t('复制链接') }}</button></span>
            </div>
          </div>
          <div class="btns">
            <NSelect :value="c.status" size="small" :options="statusOptions" style="width: 110px" @update:value="(v: Status) => setStatus(c, v)" />
            <NButton size="small" secondary :loading="writing === c.id" @click="write(c)">{{ t('写邀请') }}</NButton>
            <NButton v-if="!c.affiliate" size="small" quaternary @click="openAffiliate(c)">{{ t('转为推广员') }}</NButton>
            <NButton size="small" quaternary type="error" @click="remove(c)">{{ t('删除') }}</NButton>
          </div>
        </div>
        <NEmpty v-if="!rows.length" :description="t('还没有达人，在上面搜索')" style="padding: 32px 0" />
        <div v-if="meta.last_page > 1" class="pager"><NPagination v-model:page="filter.page" :page-count="meta.last_page" /></div>
      </template>
    </NCard>

    <NModal :show="!!outreach" preset="card" :title="t('合作邀请 · {0}', [outreach?.creator.name ?? ''])" style="max-width: 620px" @update:show="(v: boolean) => { if (!v) outreach = null }">
      <div v-if="outreach" class="msg">
        <NTag size="small" :bordered="false">{{ outreach.ai ? t('AI 撰写') : t('模板') }}</NTag>
        <label>{{ t('邮件标题') }}<NInput :value="outreach.subject" readonly /></label>
        <label>{{ t('内容（可以直接私信或发邮件）') }}<NInput v-model:value="outreach.message" type="textarea" :autosize="{ minRows: 5, maxRows: 12 }" /></label>
        <div v-if="outreach.message_zh" class="zh"><div class="muted">{{ t('中文翻译') }}</div>{{ outreach.message_zh }}</div>
      </div>
      <template #footer>
        <div class="foot">
          <NButton @click="copy(outreach!.message)">{{ t('复制内容') }}</NButton>
          <NButton type="primary" @click="markContacted">{{ t('标记为已联系') }}</NButton>
        </div>
      </template>
    </NModal>

    <NModal :show="!!aff.creator" preset="card" :title="t('转为推广员 · {0}', [aff.creator?.name ?? ''])" style="max-width: 480px" @update:show="(v: boolean) => { if (!v) aff.creator = null }">
      <div class="msg">
        <label>{{ t('邮箱（用于结算佣金）') }}<NInput v-model:value="aff.email" maxlength="255" /></label>
        <label>{{ t('推广码') }}<NInput v-model:value="aff.code" maxlength="40" /></label>
        <label>{{ t('佣金比例（%）') }}<NInputNumber v-model:value="aff.rate" :min="0" :max="90" :precision="2" /></label>
        <p class="muted">{{ t('添加后可以在「联盟营销」里查看点击、订单和佣金。') }}</p>
      </div>
      <template #footer><div class="foot"><NButton @click="aff.creator = null">{{ t('取消') }}</NButton><NButton type="primary" :loading="converting" @click="convert">{{ t('添加') }}</NButton></div></template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; max-width: 720px; }
.center { display: flex; justify-content: center; padding: 48px; }
.muted { color: var(--ink-muted); font-size: 12px; }
.search { display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
.field { display: grid; gap: 6px; font-size: 13px; }
.field:first-child { flex: 1 1 260px; }
.plats { display: flex; gap: 6px; }
.pchip { border: 1px solid var(--border); background: var(--surface); border-radius: 16px; padding: 4px 12px; font-size: 13px; cursor: pointer; color: var(--ink); }
.pchip.on { border-color: var(--brand-500); background: var(--brand-50); color: var(--brand-700); }
.hint { margin: 10px 0 0; }
.filters { display: flex; gap: 8px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.row { display: flex; gap: 12px; padding: 12px 16px; border-top: 1px solid var(--border); flex-wrap: wrap; align-items: flex-start; }
.filters + .row { border-top: 0; }
.main { flex: 1; min-width: 240px; display: grid; gap: 4px; }
.name { display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }
.name a { font-weight: 600; color: var(--ink); }
.fans { font-size: 12px; color: var(--brand-700); font-weight: 500; }
.bio { font-size: 13px; color: var(--ink); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.contacts { display: flex; gap: 12px; flex-wrap: wrap; font-size: 13px; }
.contacts a { color: var(--brand-700); }
.aff { font-size: 12px; }
.copy { background: none; border: 0; color: var(--brand-700); cursor: pointer; font-size: 12px; }
.btns { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
.pager { display: flex; justify-content: center; padding: 12px; }
.msg { display: grid; gap: 12px; }
.msg label { display: grid; gap: 4px; font-size: 13px; }
.zh { font-size: 13px; line-height: 1.6; background: var(--surface-muted); border-radius: 8px; padding: 8px 10px; white-space: pre-wrap; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
</style>
