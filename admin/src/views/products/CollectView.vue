<script setup lang="ts">
/**
 * Products › Collect (采集): paste product URLs (or a whole Shopify store), or send pages from the
 * browser extension → items are cleaned, priced and checked for duplicates → review, edit,
 * AI-rewrite → publish in bulk to the catalog and the chosen markets.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  NAlert, NButton, NCard, NCheckbox, NDrawer, NDrawerContent, NDynamicTags, NEmpty, NForm, NFormItem, NInput, NInputNumber,
  NModal, NPagination, NRadio, NRadioGroup, NSelect, NSpin, NTag, NTooltip, useDialog, useMessage,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

type Status = 'ready' | 'pending' | 'failed' | 'published' | 'rejected'
interface Note { type: string; message: string }
interface Row {
  id: number; url: string; platform: string | null; status: Status; error: string | null; title: string | null; image: string | null
  currency: string | null; variants_count: number; price_min: string | null; price_max: string | null; cost_min: string | null; rule: string | null
  notes: Note[]; duplicate: { product_id: number; title: string; reason: string } | null; ai: string | null; product_id: number | null
}
interface Variant { options: Record<string, string>; sku: string | null; cost: string; price: string | null; compare_at_price: string | null; inventory: number | null; image: string | null }
interface Detail extends Row { data: { title: string; description: string; product_type?: string; tags?: string[]; images: string[]; currency: string; variants: Variant[] }; original: { title?: string } | null }

const message = useMessage()
const dialog = useDialog()

// List
const status = ref<Status>('ready')
const q = ref('')
const page = ref(1)
const loading = ref(true)
const rows = ref<Row[]>([])
const meta = ref({ total: 0, page: 1, last_page: 1 })
const counts = ref<Record<string, number>>({})
const ctx = ref({ store_currency: 'USD', ai_ready: false, extension_connected: false, markets: [] as { id: number; name: string; currency: string }[],
  collections: [] as { id: number; title: string }[], rules: [] as { id: number; name: string }[], currencies: [] as string[] })
const selected = ref<number[]>([])

async function load(quiet = false) {
  quiet || (loading.value = true)
  try {
    const d = (await http.get('/collect/items', { params: { status: status.value, q: q.value || undefined, page: page.value } })).data
    rows.value = d.data
    meta.value = d.meta
    counts.value = d.counts
    ctx.value = { store_currency: d.store_currency, ai_ready: d.ai_ready, extension_connected: d.extension_connected, markets: d.markets, collections: d.collections, rules: d.rules, currencies: d.currencies }
    selected.value = selected.value.filter((id) => rows.value.some((r) => r.id === id))
  } finally {
    loading.value = false
  }
}
watch([status, page], () => { selected.value = []; load() })
let qTimer: number | undefined
watch(q, () => { clearTimeout(qTimer); qTimer = window.setTimeout(() => { page.value = 1; load() }, 300) })

// While something is being collected or published, refresh quietly
const publishing = ref(false)
let poll: number | undefined
const busy = computed(() => (counts.value.pending ?? 0) > 0 || publishing.value)
watch(busy, (b) => {
  clearInterval(poll)
  if (b) poll = window.setInterval(() => load(true), 4000)
})
onMounted(() => load())
onBeforeUnmount(() => clearInterval(poll))

const tabs = computed(() => ([
  ['ready', t('待审核')], ['pending', t('采集中')], ['failed', t('采集失败')], ['published', t('已上架')], ['rejected', t('已忽略')],
] as [Status, string][]).map(([key, label]) => ({ key, label, n: counts.value[key] ?? 0 })))
const allChecked = computed(() => rows.value.length > 0 && rows.value.every((r) => selected.value.includes(r.id)))
function toggleAll(v: boolean) { selected.value = v ? rows.value.map((r) => r.id) : [] }
function toggle(id: number, v: boolean) { selected.value = v ? [...selected.value, id] : selected.value.filter((x) => x !== id) }
const host = (u: string) => { try { return new URL(u).host.replace(/^www\./, '') } catch { return u } }
const platformLabel: Record<string, string> = { shopify: 'Shopify', woocommerce: 'WooCommerce', page: t('网页'), extension: t('插件') }
const noteType = (n: Note) => (['brand', 'pricing', 'cost', 'images'].includes(n.type) ? 'error' : 'warning')

// Collect
const urls = ref('')
const mode = ref<'products' | 'store'>('products')
const currency = ref('USD')
const collecting = ref(false)
async function collect() {
  collecting.value = true
  try {
    const r = (await http.post('/collect/items', { urls: urls.value, mode: mode.value, currency: currency.value })).data
    message.success(mode.value === 'store' ? t('已开始读取店铺的全部商品') : t('已开始采集 {0} 个网址', [r.queued]) + (r.skipped ? t('，{0} 个已上架过，已跳过', [r.skipped]) : ''))
    urls.value = ''
    status.value = 'pending'
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    collecting.value = false
  }
}

// Bulk actions
const working = ref(false)
async function bulk(action: string, extra: Record<string, unknown> = {}) {
  if (!selected.value.length) return
  working.value = true
  try {
    const r = (await http.post('/collect/items/bulk', { ids: selected.value, action, ...extra })).data
    if (r.errors?.length) message.warning(t('{0} 个完成，{1} 个失败：{2}', [r.done, r.errors.length, r.errors[0].message]))
    else message.success(t('已处理 {0} 个', [r.done]))
    if (action !== 'ai' && action !== 'reprice') selected.value = []
    await load(true)
    return r
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    working.value = false
  }
}
function remove() {
  dialog.warning({
    title: t('删除选中的 {0} 个商品？', [selected.value.length]), content: t('只从采集列表删除，不影响已上架的商品。'),
    positiveText: t('删除'), negativeText: t('取消'), onPositiveClick: () => bulk('delete'),
  })
}
const repriceRule = ref<number | null>(null)

// Publish
const showPublish = ref(false)
const pub = reactive({ status: 'draft' as 'draft' | 'active', collection_ids: [] as number[], allMarkets: true, market_ids: [] as number[], inventory: 0 })
async function publish() {
  publishing.value = true
  const r = await bulk('publish', { status: pub.status, collection_ids: pub.collection_ids, market_ids: pub.allMarkets ? null : pub.market_ids, inventory: pub.inventory })
  showPublish.value = false
  if (r?.done) message.info(t('正在后台上架，下载图片需要一点时间'))
  window.setTimeout(() => { publishing.value = false }, 20000)
}

// Editor
const editing = ref<Detail | null>(null)
const saving = ref(false)
async function open(r: Row) {
  try {
    editing.value = (await http.get(`/collect/items/${r.id}`)).data.data
  } catch (e) {
    message.error(errorMessage(e))
  }
}
const optionLabel = (v: Variant) => Object.values(v.options).join(' / ') || t('默认')
function moveImage(i: number, dir: -1 | 1) {
  const imgs = editing.value!.data.images
  const j = i + dir
  if (j < 0 || j >= imgs.length) return;
  [imgs[i], imgs[j]] = [imgs[j], imgs[i]]
}
async function save() {
  const e = editing.value!
  saving.value = true
  try {
    editing.value = (await http.put(`/collect/items/${e.id}`, {
      title: e.data.title, description: e.data.description, product_type: e.data.product_type ?? null, tags: e.data.tags ?? [], images: e.data.images,
      variants: e.data.variants.map((v) => ({ price: v.price || null, compare_at_price: v.compare_at_price || null, cost: v.cost, sku: v.sku, inventory: v.inventory })),
    })).data.data
    message.success(t('已保存'))
    await load(true)
  } catch (err) {
    message.error(errorMessage(err))
  } finally {
    saving.value = false
  }
}

// Pricing rules
interface Rule { name: string; scope: 'all' | 'product_type' | 'tag'; scope_value: string | null; multiplier: number; add_amount: string | number; compare_multiplier: number | null; rounding: string }
const showRules = ref(false)
const rules = ref<Rule[]>([])
const savingRules = ref(false)
async function openRules() {
  rules.value = (await http.get('/collect/pricing-rules')).data.data
  showRules.value = true
}
const scopeOptions = [{ label: t('全部商品'), value: 'all' }, { label: t('商品类型是'), value: 'product_type' }, { label: t('带有标签'), value: 'tag' }]
const roundingOptions = ['none', '0.99', '0.95', '0.90', '1', '10', '100', '1000'].map((v) => ({ label: v === 'none' ? t('不处理') : v, value: v }))
function addRule() { rules.value.push({ name: t('新规则'), scope: 'all', scope_value: null, multiplier: 2.5, add_amount: 0, compare_multiplier: null, rounding: '0.99' }) }
function moveRule(i: number, dir: -1 | 1) {
  const j = i + dir
  if (j < 0 || j >= rules.value.length) return;
  [rules.value[i], rules.value[j]] = [rules.value[j], rules.value[i]]
}
async function saveRules() {
  savingRules.value = true
  try {
    rules.value = (await http.put('/collect/pricing-rules', { rules: rules.value })).data.data
    message.success(t('已保存'))
    showRules.value = false
    await load(true)
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    savingRules.value = false
  }
}

// Browser extension
const showExt = ref(false)
const ext = ref<{ key: string; api: string } | null>(null)
async function newKey() {
  try {
    ext.value = (await http.post('/collect/extension-key')).data
    ctx.value.extension_connected = true
  } catch (e) {
    message.error(errorMessage(e))
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
        <div class="eyebrow">Sourcing</div>
        <h1 class="page-title">{{ t('商品采集') }}</h1>
        <p class="muted">{{ t('从供应商或其他店铺采集商品，自动清洗标题和描述、按规则定价、检查重复，审核后批量上架。') }}</p>
      </div>
      <div class="head-actions">
        <NButton secondary @click="openRules">{{ t('定价规则') }}</NButton>
        <NButton secondary @click="showExt = true">{{ t('浏览器插件') }}</NButton>
      </div>
    </div>

    <NCard size="small">
      <NInput v-model:value="urls" type="textarea" :autosize="{ minRows: 3, maxRows: 8 }"
        :placeholder="mode === 'store' ? t('Shopify 店铺网址，例如 https://example-shop.com') : t('粘贴商品网址，每行一个（最多 50 个）')" />
      <div class="collect-row">
        <NRadioGroup v-model:value="mode">
          <NRadio value="products">{{ t('商品页') }}</NRadio>
          <NRadio value="store">{{ t('整个 Shopify 店铺') }}</NRadio>
        </NRadioGroup>
        <div class="cur">
          <span class="muted">{{ t('价格货币（识别不出时使用）') }}</span>
          <NSelect v-model:value="currency" :options="ctx.currencies.map((c) => ({ label: c, value: c }))" size="small" style="width: 100px" />
        </div>
        <NButton type="primary" :loading="collecting" :disabled="!urls.trim()" @click="collect">{{ t('开始采集') }}</NButton>
      </div>
      <p class="muted small">{{ t('只读取页面公开的商品数据。开启了访问防护或需要登录的网站（如 1688、速卖通）请用浏览器插件采集。采集在后台进行，需要运行队列。') }}</p>
    </NCard>

    <NCard size="small" class="list-card">
      <div class="tabs" role="tablist">
        <button v-for="tb in tabs" :key="tb.key" role="tab" class="tab" :class="{ on: status === tb.key }" :aria-selected="status === tb.key" @click="status = tb.key; page = 1">
          {{ tb.label }}<span v-if="tb.n" class="count">{{ tb.n }}</span>
        </button>
        <NInput v-model:value="q" size="small" clearable :placeholder="t('搜索标题或网址')" class="search" />
      </div>

      <div v-if="selected.length" class="toolbar">
        <span class="strong">{{ t('已选 {0} 个', [selected.length]) }}</span>
        <template v-if="status === 'ready'">
          <NButton size="small" type="primary" :disabled="working" @click="showPublish = true">{{ t('上架') }}</NButton>
          <NTooltip :disabled="ctx.ai_ready">
            <template #trigger>
              <NButton size="small" secondary :loading="working" :disabled="!ctx.ai_ready" @click="bulk('ai')">{{ t('AI 优化') }}</NButton>
            </template>
            {{ t('在「获客雷达 › 设置」填写 Claude API Key 后可用') }}
          </NTooltip>
          <NSelect v-model:value="repriceRule" size="small" clearable :placeholder="t('按匹配的规则')" :options="ctx.rules.map((r) => ({ label: r.name, value: r.id }))" style="width: 150px" />
          <NButton size="small" secondary :disabled="working" @click="bulk('reprice', { rule_id: repriceRule })">{{ t('重新定价') }}</NButton>
          <NButton size="small" quaternary :disabled="working" @click="bulk('reject')">{{ t('忽略') }}</NButton>
        </template>
        <NButton v-if="status === 'rejected'" size="small" secondary :disabled="working" @click="bulk('restore')">{{ t('恢复') }}</NButton>
        <NButton v-if="status !== 'published'" size="small" quaternary type="error" :disabled="working" @click="remove">{{ t('删除') }}</NButton>
      </div>

      <div v-if="loading" class="center"><NSpin /></div>
      <NEmpty v-else-if="!rows.length" :description="status === 'ready' ? t('没有待审核的商品。在上面粘贴网址开始采集。') : t('这里还没有商品')" style="padding: 40px 0" />
      <div v-else class="rows">
        <div class="row header">
          <NCheckbox :checked="allChecked" :aria-label="t('全选')" @update:checked="toggleAll" />
          <span>{{ t('商品') }}</span><span class="r">{{ t('成本') }}</span><span class="r">{{ t('售价（{0}）', [ctx.store_currency]) }}</span>
        </div>
        <div v-for="r in rows" :key="r.id" class="row">
          <NCheckbox :checked="selected.includes(r.id)" :aria-label="t('选择')" @update:checked="(v: boolean) => toggle(r.id, v)" />
          <div class="prod">
            <img v-if="r.image" :src="r.image" alt="" class="thumb" loading="lazy" referrerpolicy="no-referrer">
            <div v-else class="thumb empty" />
            <div class="info">
              <button v-if="r.status !== 'pending' && r.status !== 'failed'" type="button" class="title link" @click="open(r)">{{ r.title || t('（无标题）') }}</button>
              <div v-else class="title">{{ r.title || host(r.url) }}</div>
              <div class="sub">
                <a :href="r.url" target="_blank" rel="noopener noreferrer">{{ host(r.url) }}</a>
                <span v-if="r.platform"> · {{ platformLabel[r.platform] ?? r.platform }}</span>
                <span v-if="r.variants_count > 1"> · {{ t('{0} 个规格', [r.variants_count]) }}</span>
                <span v-if="r.rule"> · {{ t('规则：{0}', [r.rule]) }}</span>
                <span v-if="r.ai"> · {{ t('已 AI 优化') }}</span>
              </div>
              <div v-if="r.status === 'failed' || r.error" class="err">{{ r.error }}</div>
              <div v-if="r.duplicate || r.notes.length" class="notes">
                <NTag v-if="r.duplicate" size="small" type="error" :bordered="false">
                  {{ r.duplicate.reason === 'same_source' ? t('已采集上架过') : t('可能重复：{0}', [r.duplicate.title]) }}
                </NTag>
                <NTag v-for="(n, i) in r.notes" :key="i" size="small" :type="noteType(n)" :bordered="false">{{ n.message }}</NTag>
              </div>
              <RouterLink v-if="r.product_id" :to="{ name: 'product-edit', params: { id: r.product_id } }" class="small">{{ t('查看商品') }}</RouterLink>
            </div>
          </div>
          <span class="r num">{{ r.cost_min ? `${r.currency} ${r.cost_min}` : '—' }}</span>
          <span class="r num strong">{{ r.price_min ? (r.price_min === r.price_max ? r.price_min : `${r.price_min}–${r.price_max}`) : '—' }}</span>
        </div>
      </div>
      <div v-if="meta.last_page > 1" class="pager"><NPagination v-model:page="page" :page-count="meta.last_page" /></div>
    </NCard>

    <!-- Publish -->
    <NModal v-model:show="showPublish" preset="card" :title="t('上架 {0} 个商品', [selected.length])" style="max-width: 520px">
      <NForm label-placement="top">
        <NFormItem :label="t('状态')">
          <NRadioGroup v-model:value="pub.status">
            <NRadio value="draft">{{ t('草稿（先检查再发布）') }}</NRadio>
            <NRadio value="active">{{ t('直接在售') }}</NRadio>
          </NRadioGroup>
        </NFormItem>
        <NFormItem :label="t('加入分类')">
          <NSelect v-model:value="pub.collection_ids" multiple filterable clearable :options="ctx.collections.map((c) => ({ label: c.title, value: c.id }))" :placeholder="t('可选')" />
        </NFormItem>
        <NFormItem v-if="ctx.markets.length" :label="t('销售市场')">
          <div class="markets">
            <NCheckbox v-model:checked="pub.allMarkets">{{ t('所有市场') }}</NCheckbox>
            <template v-if="!pub.allMarkets">
              <NCheckbox v-for="m in ctx.markets" :key="m.id" :checked="pub.market_ids.includes(m.id)"
                @update:checked="(v: boolean) => pub.market_ids = v ? [...pub.market_ids, m.id] : pub.market_ids.filter((x) => x !== m.id)">{{ m.name }} · {{ m.currency }}</NCheckbox>
              <span class="muted small">{{ t('默认站点始终销售。') }}</span>
            </template>
          </div>
        </NFormItem>
        <NFormItem :label="t('默认库存（没有填写库存的规格）')">
          <NInputNumber v-model:value="pub.inventory" :min="0" :max="1000000" style="width: 160px" />
        </NFormItem>
      </NForm>
      <template #footer>
        <div class="foot">
          <NButton @click="showPublish = false">{{ t('取消') }}</NButton>
          <NButton type="primary" :loading="working" @click="publish">{{ t('上架') }}</NButton>
        </div>
      </template>
    </NModal>

    <!-- Review / edit -->
    <NDrawer :show="!!editing" width="min(720px, 100vw)" placement="right" @update:show="(v: boolean) => { if (!v) editing = null }">
      <NDrawerContent v-if="editing" :title="t('审核商品')" closable>
        <NAlert v-if="editing.duplicate" type="error" :bordered="false" style="margin-bottom: 12px">
          {{ t('可能和已有商品重复：') }}
          <RouterLink :to="{ name: 'product-edit', params: { id: editing.duplicate.product_id } }">{{ editing.duplicate.title }}</RouterLink>
        </NAlert>
        <NAlert v-if="editing.notes.length" type="warning" :bordered="false" style="margin-bottom: 12px">
          <ul class="note-list"><li v-for="(n, i) in editing.notes" :key="i">{{ n.message }}</li></ul>
        </NAlert>
        <NForm label-placement="top">
          <NFormItem :label="t('标题')"><NInput v-model:value="editing.data.title" maxlength="255" show-count /></NFormItem>
          <p v-if="editing.original?.title && editing.original.title !== editing.data.title" class="muted small orig">{{ t('原标题：{0}', [editing.original.title]) }}</p>
          <div class="two">
            <NFormItem :label="t('商品类型')"><NInput v-model:value="(editing.data.product_type as string)" /></NFormItem>
            <NFormItem :label="t('标签')"><NDynamicTags v-model:value="(editing.data.tags as string[])" /></NFormItem>
          </div>
          <NFormItem :label="t('图片（第一张为主图）')">
            <div class="imgs">
              <div v-for="(src, i) in editing.data.images" :key="src" class="img">
                <img :src="src" alt="" referrerpolicy="no-referrer">
                <div class="img-actions">
                  <button type="button" :aria-label="t('前移')" @click="moveImage(i, -1)">‹</button>
                  <button type="button" :aria-label="t('移除')" @click="editing.data.images.splice(i, 1)">×</button>
                  <button type="button" :aria-label="t('后移')" @click="moveImage(i, 1)">›</button>
                </div>
              </div>
              <span v-if="!editing.data.images.length" class="muted small">{{ t('没有图片') }}</span>
            </div>
          </NFormItem>
          <NFormItem :label="t('描述（HTML）')">
            <NInput v-model:value="editing.data.description" type="textarea" :autosize="{ minRows: 4, maxRows: 14 }" />
          </NFormItem>
          <NFormItem :label="t('规格与价格')">
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>{{ t('规格') }}</th><th>{{ t('成本（{0}）', [editing.data.currency]) }}</th><th>{{ t('售价（{0}）', [ctx.store_currency]) }}</th><th>{{ t('划线价') }}</th><th>SKU</th><th>{{ t('库存') }}</th></tr></thead>
                <tbody>
                  <tr v-for="(v, i) in editing.data.variants" :key="i">
                    <td class="vname">{{ optionLabel(v) }}</td>
                    <td><NInput v-model:value="v.cost" size="small" style="width: 86px" /></td>
                    <td><NInput v-model:value="(v.price as string)" size="small" style="width: 86px" /></td>
                    <td><NInput v-model:value="(v.compare_at_price as string)" size="small" clearable style="width: 86px" /></td>
                    <td><NInput v-model:value="(v.sku as string)" size="small" style="width: 100px" /></td>
                    <td><NInputNumber v-model:value="v.inventory" size="small" :min="0" :show-button="false" clearable style="width: 72px" /></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </NFormItem>
        </NForm>
        <template #footer>
          <div class="foot">
            <a :href="editing.url" target="_blank" rel="noopener noreferrer" class="small">{{ t('打开来源页面') }}</a>
            <NButton type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton>
          </div>
        </template>
      </NDrawerContent>
    </NDrawer>

    <!-- Pricing rules -->
    <NModal v-model:show="showRules" preset="card" :title="t('定价规则')" style="max-width: 860px">
      <p class="muted">{{ t('售价 = 成本（按汇率换算成 {0}）× 倍数 + 附加金额，再按价格尾数处理。从上往下，第一条匹配的规则生效；没有匹配时保留来源价格。', [ctx.store_currency]) }}</p>
      <div class="table-wrap">
        <table class="tbl rules">
          <thead><tr><th>{{ t('名称') }}</th><th>{{ t('适用') }}</th><th>{{ t('倍数') }}</th><th>{{ t('附加（{0}）', [ctx.store_currency]) }}</th><th>{{ t('划线价倍数') }}</th><th>{{ t('尾数') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="(r, i) in rules" :key="i">
              <td><NInput v-model:value="r.name" size="small" style="width: 110px" /></td>
              <td class="scope">
                <NSelect v-model:value="r.scope" size="small" :options="scopeOptions" style="width: 116px" />
                <NInput v-if="r.scope !== 'all'" v-model:value="(r.scope_value as string)" size="small" :placeholder="r.scope === 'tag' ? t('标签') : t('类型')" style="width: 100px" />
              </td>
              <td><NInputNumber v-model:value="r.multiplier" size="small" :min="0.1" :step="0.1" :precision="2" style="width: 86px" /></td>
              <td><NInput v-model:value="(r.add_amount as string)" size="small" style="width: 72px" /></td>
              <td><NInputNumber v-model:value="r.compare_multiplier" size="small" :min="1" :step="0.1" :precision="2" clearable style="width: 86px" /></td>
              <td><NSelect v-model:value="r.rounding" size="small" :options="roundingOptions" style="width: 90px" /></td>
              <td class="nowrap">
                <NButton size="tiny" quaternary :aria-label="t('上移')" @click="moveRule(i, -1)">↑</NButton>
                <NButton size="tiny" quaternary :aria-label="t('下移')" @click="moveRule(i, 1)">↓</NButton>
                <NButton size="tiny" quaternary type="error" @click="rules.splice(i, 1)">{{ t('删除') }}</NButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <NButton size="small" secondary style="margin-top: 10px" @click="addRule">{{ t('添加规则') }}</NButton>
      <template #footer>
        <div class="foot">
          <NButton @click="showRules = false">{{ t('取消') }}</NButton>
          <NButton type="primary" :loading="savingRules" @click="saveRules">{{ t('保存') }}</NButton>
        </div>
      </template>
    </NModal>

    <!-- Browser extension -->
    <NModal v-model:show="showExt" preset="card" :title="t('浏览器采集插件')" style="max-width: 600px">
      <p class="muted">{{ t('在 1688、速卖通、Temu 等需要登录或有访问防护的网站上，打开商品页后点插件里的「采集此页」，商品会直接进入这里的待审核列表。插件只读取当前打开的页面。') }}</p>
      <ol class="steps">
        <li>{{ t('Chrome 打开 chrome://extensions，开启右上角「开发者模式」。') }}</li>
        <li>{{ t('点「加载已解压的扩展程序」，选择项目里的 extension 文件夹。') }}</li>
        <li>{{ t('生成连接密钥，把后台地址和密钥填进插件的设置。') }}</li>
      </ol>
      <NAlert v-if="ctx.extension_connected && !ext" type="info" :bordered="false" style="margin-bottom: 12px">{{ t('已经生成过密钥。重新生成后，旧密钥立即失效。') }}</NAlert>
      <template v-if="ext">
        <div class="kv"><span>{{ t('后台地址') }}</span><code>{{ ext.api }}</code><NButton size="tiny" @click="copy(ext.api)">{{ t('复制') }}</NButton></div>
        <div class="kv"><span>{{ t('连接密钥') }}</span><code>{{ ext.key }}</code><NButton size="tiny" @click="copy(ext.key)">{{ t('复制') }}</NButton></div>
        <p class="muted small">{{ t('密钥只显示这一次。它只能把商品页发送到这个店铺的采集列表，不能登录后台。') }}</p>
      </template>
      <template #footer>
        <div class="foot"><NButton type="primary" @click="newKey">{{ ctx.extension_connected ? t('重新生成密钥') : t('生成连接密钥') }}</NButton></div>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; max-width: 1100px; }
.head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
.head-actions { display: flex; gap: 8px; }
.page-title { margin: 0 0 4px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0; }
.small { font-size: 12px; }
.strong { font-weight: 600; }
.collect-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin: 12px 0 8px; }
.cur { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 8px; align-items: center; flex-wrap: wrap; }
.tab { background: none; border: 0; padding: 10px 12px; font-size: 14px; color: var(--ink-muted); border-bottom: 2px solid transparent; margin-bottom: -1px; cursor: pointer; }
.tab.on { color: var(--brand-700); border-bottom-color: var(--brand-600); font-weight: 500; }
.count { margin-left: 6px; font-size: 12px; background: var(--surface-muted); border-radius: 999px; padding: 1px 7px; }
.search { margin-left: auto; width: 220px; }
.toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px 10px; background: var(--surface-muted); border-radius: 8px; margin-bottom: 8px; }
.center { display: flex; justify-content: center; padding: 40px; }
.rows { display: grid; }
.row { display: grid; grid-template-columns: 28px 1fr 120px 130px; gap: 12px; align-items: start; padding: 12px 4px; border-top: 1px solid var(--border); }
.row.header { border-top: 0; padding-top: 4px; font-size: 13px; color: var(--ink-muted); align-items: center; }
.r { text-align: right; }
.num { font-variant-numeric: tabular-nums; font-size: 14px; }
.prod { display: flex; gap: 12px; min-width: 0; }
.thumb { width: 56px; height: 56px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); flex-shrink: 0; background: var(--surface-muted); }
.info { min-width: 0; display: grid; gap: 4px; }
.title { font-weight: 500; font-size: 14px; text-align: left; overflow-wrap: anywhere; }
.title.link { background: none; border: 0; padding: 0; cursor: pointer; color: var(--ink); }
.title.link:hover { color: var(--brand-700); text-decoration: underline; }
.sub { font-size: 12px; color: var(--ink-muted); }
.sub a { color: inherit; }
.err { font-size: 12px; color: #b42318; }
.notes { display: flex; gap: 6px; flex-wrap: wrap; }
.pager { display: flex; justify-content: flex-end; padding-top: 12px; }
.markets { display: grid; gap: 6px; }
.foot { display: flex; justify-content: flex-end; align-items: center; gap: 8px; width: 100%; }
.foot a { margin-right: auto; color: var(--brand-700); }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
.orig { margin: -14px 0 12px; }
.imgs { display: flex; gap: 8px; flex-wrap: wrap; }
.img { position: relative; width: 96px; }
.img img { width: 96px; height: 96px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); display: block; }
.img:first-child img { outline: 2px solid var(--brand-600); outline-offset: 1px; }
.img-actions { display: flex; justify-content: space-between; margin-top: 4px; }
.img-actions button { border: 1px solid var(--border); background: var(--surface); border-radius: 6px; width: 28px; height: 22px; cursor: pointer; color: var(--ink); }
.table-wrap { overflow-x: auto; width: 100%; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { text-align: left; font-weight: 500; color: var(--ink-muted); padding: 6px; background: var(--surface-muted); white-space: nowrap; }
.tbl td { padding: 6px; border-top: 1px solid var(--border); vertical-align: middle; }
.tbl .scope { display: flex; gap: 6px; }
.vname, .nowrap { white-space: nowrap; }
.note-list { margin: 0; padding-left: 18px; }
.steps { margin: 12px 0; padding-left: 20px; line-height: 1.8; font-size: 14px; }
.kv { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 13px; }
.kv span { width: 72px; color: var(--ink-muted); flex-shrink: 0; }
.kv code { flex: 1; background: var(--surface-muted); padding: 6px 8px; border-radius: 6px; word-break: break-all; font-size: 12px; }
@media (max-width: 760px) {
  .row { grid-template-columns: 28px 1fr; }
  .row .r { grid-column: 2; text-align: left; }
  .row.header .r { display: none; }
  .search { width: 100%; margin-left: 0; }
  .cur { margin-left: 0; }
  .two { grid-template-columns: 1fr; }
}
</style>
