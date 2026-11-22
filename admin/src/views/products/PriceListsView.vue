<script setup lang="ts">
/** Products › Wholesale: price lists for tagged customers (% off everything, fixed prices, minimum order). */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  NButton, NCard, NDrawer, NDrawerContent, NEmpty, NForm, NFormItem, NInput, NInputNumber, NSkeleton, NSwitch, NTag,
  useDialog, useMessage,
  NSelect,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

interface List { id: number; name: string; customer_tag: string; percent_off: number; min_subtotal: string; enabled: boolean; fixed_prices: number; customers?: number; market_id: number | null; currency: string }
interface Price { variant_id: number; title: string | null; variant_title: string | null; sku: string | null; regular: string; price: number | string | null; min_qty: number; step: number; tiers: { min: number; price: number }[] }
interface Variant { id: number; title: string; variant_title: string | null; sku: string | null; price: string }

const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const storeCurrency = useAuth().currentStore?.currency ?? ''
const lists = ref<List[] | null>(null)
const markets = ref<{ id: number; name: string; currency: string }[]>([])
async function load() { lists.value = (await http.get('/price-lists')).data.data }
onMounted(async () => {
  load()
  markets.value = (await http.get('/markets')).data.data
})

// ---- Editor ----
const open = ref(false)
const editingId = ref<number | null>(null)
const form = reactive({ name: '', customer_tag: '', percent_off: 20, min_subtotal: 0, enabled: true, market_id: null as number | null })
// A market's own list is priced in its currency; a general list in the store currency (converted inside markets)
const currency = computed(() => (form.market_id ? markets.value.find((m) => m.id === form.market_id)?.currency : null) ?? storeCurrency)
const marketName = (id: number | null) => (id ? markets.value.find((m) => m.id === id)?.name ?? '—' : null)
const prices = ref<Price[]>([])
const saving = ref(false)
async function edit(l?: List) {
  editingId.value = l?.id ?? null
  if (l) {
    const { data } = await http.get(`/price-lists/${l.id}`)
    Object.assign(form, { name: data.data.name, customer_tag: data.data.customer_tag, percent_off: data.data.percent_off, min_subtotal: Number(data.data.min_subtotal), enabled: data.data.enabled, market_id: data.data.market_id ?? null })
    prices.value = data.data.prices.map((p: Price) => ({ ...p, price: p.price === null ? null : Number(p.price), tiers: p.tiers.map((x) => ({ min: x.min, price: Number(x.price) })) }))
  } else {
    Object.assign(form, { name: t('批发客户'), customer_tag: 'wholesale', percent_off: 20, min_subtotal: 0, enabled: true, market_id: null })
    prices.value = []
  }
  q.value = ''
  results.value = []
  open.value = true
}
async function save() {
  saving.value = true
  try {
    const body = { ...form, prices: prices.value.map((p) => ({ variant_id: p.variant_id, price: p.price, min_qty: p.min_qty, step: p.step, tiers: p.tiers.filter((x) => x.min > 1) })) }
    if (editingId.value) await http.put(`/price-lists/${editingId.value}`, body)
    else await http.post('/price-lists', body)
    message.success(t('已保存'))
    open.value = false
    load()
  } catch (e) { message.error(errorMessage(e)) } finally { saving.value = false }
}
function remove(l: List) {
  dialog.warning({
    title: t('删除「{0}」？', [l.name]), content: t('带这个标签的客户会恢复原价。'),
    positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => { await http.delete(`/price-lists/${l.id}`); load() },
  })
}

// Fixed prices: search variants
const q = ref('')
const results = ref<Variant[]>([])
let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(async () => { results.value = q.value ? (await http.get('/price-lists/variants', { params: { q: q.value } })).data.data : [] }, 250)
})
function addPrice(v: Variant) {
  if (!prices.value.some((p) => p.variant_id === v.id)) {
    prices.value.push({ variant_id: v.id, title: v.title, variant_title: v.variant_title, sku: v.sku, regular: v.price, price: Math.round(Number(v.price) * (100 - form.percent_off)) / 100, min_qty: 1, step: 1, tiers: [] })
  }
  q.value = ''
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <div class="eyebrow">B2B wholesale</div>
        <h1 class="page-title">{{ t('批发价') }}</h1>
        <p class="page-sub">{{ t('给打了指定标签的客户（例如「wholesale」）显示专属价格。客户登录后，商品页、购物车和结账都会用批发价；也可以要求最低起订金额。') }}</p>
      </div>
      <NButton type="primary" @click="edit()">{{ t('新建价格表') }}</NButton>
    </div>

    <NSkeleton v-if="!lists" height="160px" />
    <NCard v-else-if="!lists.length" size="small"><NEmpty :description="t('还没有价格表')" style="padding: 24px 0" /></NCard>
    <div v-else class="lists">
      <NCard v-for="l in lists" :key="l.id" size="small" class="list">
        <div class="l-head">
          <div>
            <div class="l-name">{{ l.name }} <NTag v-if="!l.enabled" size="tiny" :bordered="false">{{ t('已停用') }}</NTag></div>
            <div class="muted">{{ t('标签「{0}」· {1} 位客户', [l.customer_tag, l.customers ?? 0]) }}<template v-if="l.market_id"> · {{ marketName(l.market_id) }} · {{ l.currency }}</template>
              <a class="link" @click="router.push({ name: 'customers' })">{{ t('去给客户打标签') }}</a></div>
          </div>
          <div class="acts">
            <NButton size="small" @click="edit(l)">{{ t('编辑') }}</NButton>
            <NButton size="small" quaternary type="error" @click="remove(l)">{{ t('删除') }}</NButton>
          </div>
        </div>
        <div class="facts">
          <span>{{ l.percent_off > 0 ? t('全部商品 {0}% off', [l.percent_off]) : t('不打折') }}</span>
          <span>{{ t('{0} 个商品单独定价', [l.fixed_prices]) }}</span>
          <span>{{ Number(l.min_subtotal) > 0 ? t('最低起订 {0} {1}', [l.currency, l.min_subtotal]) : t('无起订金额') }}</span>
        </div>
      </NCard>
    </div>

    <NDrawer v-model:show="open" :width="560">
      <NDrawerContent :title="editingId ? t('编辑价格表') : t('新建价格表')" closable>
        <NForm label-placement="top" @submit.prevent="save">
          <div class="two">
            <NFormItem :label="t('名称')"><NInput v-model:value="form.name" maxlength="100" /></NFormItem>
            <NFormItem :label="t('适用的客户标签')"><NInput v-model:value="form.customer_tag" maxlength="40" placeholder="wholesale" /></NFormItem>
          </div>
          <div class="two">
            <NFormItem :label="t('全部商品折扣')"><NInputNumber v-model:value="form.percent_off" :min="0" :max="90" style="width: 100%"><template #suffix>% off</template></NInputNumber></NFormItem>
            <NFormItem :label="t('最低起订金额（0 = 不限）')"><NInputNumber v-model:value="form.min_subtotal" :min="0" :precision="2" style="width: 100%"><template #prefix>{{ currency }}</template></NInputNumber></NFormItem>
          </div>
          <NFormItem v-if="markets.length" :label="t('适用市场')">
            <NSelect v-model:value="form.market_id" clearable :placeholder="t('所有市场（价格按汇率换算）')" :options="markets.map((m) => ({ label: `${m.name} · ${m.currency}`, value: m.id }))" />
          </NFormItem>
          <p v-if="form.market_id" class="muted small" style="margin: -8px 0 12px">{{ t('这个市场的批发客户只用这张价格表，价格直接按 {0} 填写，不换算。', [currency]) }}</p>
          <div class="switch-row"><span>{{ t('启用') }}</span><NSwitch v-model:value="form.enabled" /></div>

          <div class="sec">{{ t('单独定价、起订量和阶梯价') }}</div>
          <NInput v-model:value="q" :placeholder="t('搜索商品名称或 SKU')" clearable size="small" />
          <div v-if="results.length" class="results">
            <button v-for="v in results.slice(0, 8)" :key="v.id" type="button" class="result" @click="addPrice(v)">
              <span>{{ v.title }}<span class="muted">{{ v.variant_title ? ` · ${v.variant_title}` : '' }}</span></span><span class="muted">{{ storeCurrency }} {{ v.price }}</span>
            </button>
          </div>
          <div v-for="(p, i) in prices" :key="p.variant_id" class="price-block">
            <div class="price">
              <div class="p-main"><div>{{ p.title }}</div><div class="muted">{{ [p.variant_title, p.sku].filter(Boolean).join(' · ') }} · {{ t('原价 {0}', [p.regular]) }}</div></div>
              <NInputNumber v-model:value="p.price as number" :min="0" :precision="2" size="small" clearable :show-button="false" :placeholder="t('按折扣')" style="width: 110px"><template #prefix>{{ currency }}</template></NInputNumber>
              <NButton quaternary size="tiny" @click="prices.splice(i, 1)">×</NButton>
            </div>
            <div class="rules">
              <span>{{ t('起订') }}</span><NInputNumber v-model:value="p.min_qty" :min="1" size="tiny" :show-button="false" style="width: 64px" />
              <span>{{ t('倍数') }}</span><NInputNumber v-model:value="p.step" :min="1" size="tiny" :show-button="false" style="width: 64px" />
              <template v-for="(tier, n) in p.tiers" :key="n">
                <span class="tier">≥ <NInputNumber v-model:value="tier.min" :min="2" size="tiny" :show-button="false" style="width: 60px" /> {{ currency }}
                  <NInputNumber v-model:value="tier.price" :min="0" :precision="2" size="tiny" :show-button="false" style="width: 76px" />
                  <a class="x" @click="p.tiers.splice(n, 1)">×</a></span>
              </template>
              <NButton size="tiny" quaternary @click="p.tiers.push({ min: (p.tiers.at(-1)?.min ?? Math.max(1, p.min_qty)) * 2 || 10, price: Number(p.price ?? p.regular) })">+ {{ t('阶梯价') }}</NButton>
            </div>
          </div>
          <div class="actions"><NButton @click="open = false">{{ t('取消') }}</NButton><NButton type="primary" attr-type="submit" :loading="saving">{{ t('保存') }}</NButton></div>
        </NForm>
      </NDrawerContent>
    </NDrawer>
  </div>
</template>

<style scoped>
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.lists { display: grid; gap: 12px; }
.l-head { display: flex; justify-content: space-between; gap: 12px; }
.l-name { font-weight: 600; font-size: 15px; }
.acts { display: flex; gap: 6px; }
.facts { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 10px; font-size: 13px; }
.muted { color: var(--ink-muted); font-size: 12px; }
.link { color: var(--brand-700); cursor: pointer; margin-left: 8px; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.switch-row { display: flex; justify-content: space-between; align-items: center; font-size: 14px; margin-bottom: 8px; }
.sec { font-size: 12px; font-weight: 600; color: var(--ink-muted); text-transform: uppercase; letter-spacing: .04em; margin: 16px 0 8px; }
.results { border: 1px solid var(--border); border-radius: 8px; margin-top: 6px; }
.result { display: flex; justify-content: space-between; width: 100%; padding: 8px 10px; border: 0; border-bottom: 1px solid var(--border); background: none; cursor: pointer; font: inherit; font-size: 13px; text-align: left; color: var(--ink); }
.result:last-child { border-bottom: 0; }
.result:hover { background: var(--brand-50); }
.price-block { padding: 8px 0; border-bottom: 1px solid var(--border); }
.price { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.rules { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 6px; font-size: 12px; color: var(--ink-muted); }
.tier { display: inline-flex; align-items: center; gap: 4px; padding: 2px 6px; border: 1px solid var(--border); border-radius: 6px; }
.x { cursor: pointer; color: var(--ink-muted); }
.p-main { flex: 1; min-width: 0; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
</style>
