<script setup lang="ts">
/**
 * Marketing › SEO landing pages: one page per market and keyword people search there, in the market's
 * language, listing the matching products. Generated from research-radar ideas (or a keyword), edited, published.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { NButton, NCard, NCheckbox, NEmpty, NInput, NModal, NSelect, NSpin, NSwitch, NTag, useDialog, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

interface Market { id: number; name: string; handle: string; default_locale: string; currency: string }
interface Row { id: number; market_id: number | null; locale: string; keyword: string; slug: string; title: string; status: 'draft' | 'published'; ai: boolean; products_count: number; url: string; updated_at: string }
interface Suggestion { id: number; keyword: string; market_id: number | null; score: number }
interface Detail extends Row { meta_description: string | null; heading: string; intro: string | null; faq: { q: string; a: string }[]; product_ids: number[] | null; products: { id: number; title: string }[] }

const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const rows = ref<Row[]>([])
const suggestions = ref<Suggestion[]>([])
const markets = ref<Market[]>([])
const aiReady = ref(false)
const minProducts = ref(2)
const picked = ref<number[]>([])
const useAi = ref(false)
const working = ref(false)

async function load() {
  try {
    const d = (await http.get('/seo-pages')).data
    rows.value = d.data
    suggestions.value = d.suggestions
    markets.value = d.markets
    aiReady.value = d.ai_ready
    minProducts.value = d.min_products
    useAi.value = useAi.value && d.ai_ready
    picked.value = picked.value.filter((id) => d.suggestions.some((s: Suggestion) => s.id === id))
  } finally {
    loading.value = false
  }
}
onMounted(load)

const marketName = (id: number | null) => (id ? markets.value.find((m) => m.id === id)?.name ?? '—' : t('默认站点'))
const marketOptions = computed(() => [{ label: t('默认站点'), value: 0 }, ...markets.value.map((m) => ({ label: `${m.name} · ${m.default_locale}`, value: m.id }))])

async function generate(body: Record<string, unknown>) {
  working.value = true
  try {
    const made: Row[] = (await http.post('/seo-pages', { ...body, ai: useAi.value }, { timeout: 180000 })).data.data
    const thin = made.filter((r) => r.products_count < minProducts.value).length
    message.success(t('已生成 {0} 个页面（草稿）', [made.length]) + (thin ? t('，其中 {0} 个匹配的商品太少，暂时不能发布', [thin]) : ''))
    picked.value = []
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    working.value = false
  }
}

// One keyword by hand
const manual = reactive({ keyword: '', market: 0 })
function addManual() {
  if (!manual.keyword.trim()) return
  generate({ keyword: manual.keyword.trim(), market_id: manual.market || null }).then(() => { manual.keyword = '' })
}

async function setStatus(ids: number[], status: 'draft' | 'published') {
  try {
    const r = (await http.post('/seo-pages/publish', { ids, status })).data
    if (r.errors.length) message.warning(r.errors[0].message)
    else message.success(status === 'published' ? t('已发布') : t('已转为草稿'))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  }
}

function remove(r: Row) {
  dialog.warning({
    title: t('删除「{0}」？', [r.keyword]), content: t('删除后这个网址会变成 404，已被搜索引擎收录的页面会逐渐消失。'),
    positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => { await http.delete(`/seo-pages/${r.id}`); await load() },
  })
}

// Edit
const editing = ref<Detail | null>(null)
const saving = ref(false)
async function open(r: Row) {
  try {
    editing.value = (await http.get(`/seo-pages/${r.id}`)).data.data
  } catch (e) {
    message.error(errorMessage(e))
  }
}
async function save(status: 'draft' | 'published') {
  const e = editing.value!
  saving.value = true
  try {
    editing.value = (await http.put(`/seo-pages/${e.id}`, {
      title: e.title, meta_description: e.meta_description, heading: e.heading, intro: e.intro, slug: e.slug,
      faq: e.faq.filter((f) => f.q.trim() && f.a.trim()), product_ids: e.product_ids, status,
    })).data.data
    message.success(status === 'published' ? t('已发布') : t('已保存'))
    await load()
  } catch (err) {
    message.error(errorMessage(err))
  } finally {
    saving.value = false
  }
}
async function copy(text: string) {
  try { await navigator.clipboard.writeText(text); message.success(t('已复制')) } catch { message.error(t('复制失败，请手动选择复制')) }
}
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Marketing</div>
        <h1 class="page-title">{{ t('SEO 落地页') }}</h1>
        <p class="page-sub">{{ t('为每个国家、每个当地人常搜的关键词生成一个页面（用当地语言），展示匹配的商品，帮你从 Google 免费获得流量。发布后自动加入站点地图。') }}</p>
      </div>
    </div>

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else>
      <NCard size="small" :title="t('从选品雷达生成')">
        <p class="muted top">{{ t('这些是各市场有搜索需求、还没有落地页的关键词（来自「选品雷达」）。') }}<RouterLink :to="{ name: 'research' }">{{ t('去选品雷达找更多') }}</RouterLink></p>
        <div v-if="suggestions.length" class="sugg">
          <label v-for="s in suggestions" :key="s.id" class="chip" :class="{ on: picked.includes(s.id) }">
            <NCheckbox :checked="picked.includes(s.id)" @update:checked="(v: boolean) => picked = v ? [...picked, s.id] : picked.filter((x) => x !== s.id)" />
            <span>{{ s.keyword }}</span><span class="muted">{{ marketName(s.market_id) }} · {{ s.score }}</span>
          </label>
        </div>
        <NEmpty v-else :description="t('选品雷达里还没有新的关键词')" size="small" style="padding: 12px 0" />
        <div class="gen">
          <div class="manual">
            <NInput v-model:value="manual.keyword" size="small" :placeholder="t('或者直接输入关键词，例如 fundas de silicona')" maxlength="120" @keyup.enter="addManual" />
            <NSelect v-model:value="manual.market" size="small" :options="marketOptions" style="width: 200px" />
            <NButton size="small" :disabled="!manual.keyword.trim()" :loading="working" @click="addManual">{{ t('生成') }}</NButton>
          </div>
          <div class="ai">
            <NSwitch v-model:value="useAi" size="small" :disabled="!aiReady" />
            <span class="muted">{{ aiReady ? t('AI 用当地语言写标题、介绍和常见问题（一次最多 10 个）') : t('填写 Claude API Key 后可用 AI 写文案，否则使用简单模板') }}</span>
            <NButton type="primary" size="small" :disabled="!picked.length" :loading="working" @click="generate({ opportunity_ids: picked })">{{ t('生成 {0} 个页面', [picked.length]) }}</NButton>
          </div>
        </div>
      </NCard>

      <NCard size="small" content-style="padding: 0">
        <div v-for="r in rows" :key="r.id" class="row">
          <div class="main">
            <div>
              <button type="button" class="link title" @click="open(r)">{{ r.keyword }}</button>
              <NTag size="tiny" :bordered="false" :type="r.status === 'published' ? 'success' : 'default'">{{ r.status === 'published' ? t('已发布') : t('草稿') }}</NTag>
              <NTag v-if="r.ai" size="tiny" :bordered="false">AI</NTag>
            </div>
            <div class="muted">{{ marketName(r.market_id) }} · {{ r.locale }} · {{ t('{0} 个商品', [r.products_count]) }}<span v-if="r.products_count < minProducts" class="warn"> · {{ t('商品太少，不能发布') }}</span></div>
            <div class="muted url"><code>{{ r.url }}</code></div>
          </div>
          <div class="btns">
            <NButton size="small" quaternary @click="copy(r.url)">{{ t('复制网址') }}</NButton>
            <NButton v-if="r.status === 'draft'" size="small" secondary :disabled="r.products_count < minProducts" @click="setStatus([r.id], 'published')">{{ t('发布') }}</NButton>
            <NButton v-else size="small" quaternary @click="setStatus([r.id], 'draft')">{{ t('取消发布') }}</NButton>
            <NButton size="small" quaternary type="error" @click="remove(r)">{{ t('删除') }}</NButton>
          </div>
        </div>
        <NEmpty v-if="!rows.length" :description="t('还没有落地页')" style="padding: 32px 0" />
      </NCard>
    </template>

    <NModal :show="!!editing" preset="card" :title="t('编辑落地页')" style="max-width: 720px" @update:show="(v: boolean) => { if (!v) editing = null }">
      <div v-if="editing" class="form">
        <label>{{ t('网页标题（搜索结果里显示，50–60 个字符）') }}<NInput v-model:value="editing.title" maxlength="160" show-count /></label>
        <label>{{ t('描述（搜索结果里显示，140–155 个字符）') }}<NInput v-model:value="editing.meta_description" type="textarea" maxlength="300" show-count :autosize="{ minRows: 2 }" /></label>
        <label>{{ t('页面大标题') }}<NInput v-model:value="editing.heading" maxlength="160" /></label>
        <label>{{ t('网址') }}<NInput v-model:value="editing.slug" maxlength="140"><template #prefix><span class="muted">/s/</span></template></NInput></label>
        <label>{{ t('介绍（HTML：只保留段落、列表和加粗）') }}<NInput v-model:value="editing.intro" type="textarea" :autosize="{ minRows: 4, maxRows: 10 }" /></label>
        <div>
          <div class="lbl">{{ t('常见问题') }}</div>
          <div v-for="(f, i) in editing.faq" :key="i" class="faq">
            <NInput v-model:value="f.q" size="small" :placeholder="t('问题')" />
            <NInput v-model:value="f.a" size="small" type="textarea" :autosize="{ minRows: 1, maxRows: 3 }" :placeholder="t('回答')" />
            <NButton size="tiny" quaternary @click="editing.faq.splice(i, 1)">{{ t('删除') }}</NButton>
          </div>
          <NButton v-if="editing.faq.length < 8" size="tiny" @click="editing.faq.push({ q: '', a: '' })">{{ t('添加问题') }}</NButton>
        </div>
        <div>
          <div class="lbl">{{ t('展示的商品（{0} 个，按关键词自动匹配，最适合这个市场的排在前面）', [editing.products.length]) }}</div>
          <div class="prods"><span v-for="p in editing.products" :key="p.id" class="prod">{{ p.title }}</span></div>
        </div>
      </div>
      <template #footer>
        <div class="foot">
          <a v-if="editing?.status === 'published'" :href="editing.url" target="_blank" rel="noopener">{{ t('查看页面') }}</a>
          <span class="grow" />
          <NButton :loading="saving" @click="save('draft')">{{ t('保存草稿') }}</NButton>
          <NButton type="primary" :loading="saving" :disabled="(editing?.products.length ?? 0) < minProducts" @click="save('published')">{{ t('保存并发布') }}</NButton>
        </div>
      </template>
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
.top { margin: 0 0 10px; }
.sugg { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--border); border-radius: 16px; padding: 3px 10px 3px 6px; font-size: 13px; cursor: pointer; }
.chip.on { border-color: var(--brand-500); background: var(--brand-50); }
.gen { display: grid; gap: 10px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
.manual, .ai { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.manual .n-input { flex: 1 1 240px; }
.ai .muted { flex: 1 1 240px; }
.row { display: flex; gap: 12px; padding: 12px 16px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.row:first-child { border-top: 0; }
.main { flex: 1; min-width: 260px; display: grid; gap: 4px; }
.title { font-weight: 600; margin-right: 6px; }
.link { background: none; border: 0; padding: 0; cursor: pointer; color: var(--brand-700); font-size: 14px; }
.url code { word-break: break-all; }
.warn { color: var(--danger); }
.btns { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
.form { display: grid; gap: 12px; }
.form label { display: grid; gap: 4px; font-size: 13px; }
.lbl { font-size: 13px; margin-bottom: 6px; }
.faq { display: grid; grid-template-columns: 1fr 1.4fr auto; gap: 6px; margin-bottom: 6px; align-items: start; }
.prods { display: flex; flex-wrap: wrap; gap: 4px; }
.prod { font-size: 12px; border: 1px solid var(--border); border-radius: 6px; padding: 2px 6px; }
.foot { display: flex; align-items: center; gap: 8px; }
.grow { flex: 1; }
@media (max-width: 640px) { .faq { grid-template-columns: 1fr; } }
</style>
