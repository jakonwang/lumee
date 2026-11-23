<script setup lang="ts">
/**
 * Product page › markets: sell this product in each market or not, and optionally fix its price
 * there (in the market currency). Empty price = the store price converted with the market's
 * exchange rate, adjustment and price ending (shown as the placeholder). Saved on its own.
 */
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { NButton, NCard, NCheckbox, NInput, NTag, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

const props = defineProps<{ productId: number; storeCurrency: string }>()
const message = useMessage()

interface VariantRow { id: number; title: string; sku: string | null; base_price: string; auto_price: string | null; auto_compare_at_price: string | null; price: string | null; compare_at_price: string | null }
interface FitInfo { score: number | null; parts: Record<string, number>; reasons: string[]; tags: string[]; ai_at: string | null }
interface MarketRow { id: number; name: string; currency: string; enabled: boolean; has_rate: boolean; sold: boolean; fit: FitInfo | null; variants: VariantRow[] }

const markets = ref<MarketRow[]>([])
const loaded = ref(false)
const saving = ref(false)

async function load() {
  markets.value = (await http.get(`/products/${props.productId}/markets`)).data.data
  loaded.value = true
}
onMounted(load)

// Market fit: which markets this product suits (rules, local trends, sales; AI on request)
const scoring = ref<'' | 'rules' | 'ai'>('')
async function rescore(ai: boolean) {
  scoring.value = ai ? 'ai' : 'rules'
  try {
    const fresh: MarketRow[] = (await http.post(`/products/${props.productId}/market-fit`, { ai })).data.data
    markets.value = markets.value.map((m) => ({ ...m, fit: fresh.find((f) => f.id === m.id)?.fit ?? null }))   // keep unsaved price edits
    message.success(t('已更新市场适合度'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    scoring.value = ''
  }
}
const fitLevel = (f: FitInfo | null) => (!f || f.score === null ? '' : f.score >= 70 ? 'good' : f.score <= 15 ? 'bad' : f.score < 40 ? 'low' : '')
const partNames: Record<string, string> = { ai: 'AI', trend: t('当地趋势'), sales: t('本市场表现'), rules: t('规则') }

const label = (v: VariantRow) => (!v.title || v.title === 'Default' ? t('默认') : v.title)

async function save() {
  saving.value = true
  try {
    markets.value = (await http.put(`/products/${props.productId}/markets`, {
      markets: markets.value.map((m) => ({
        id: m.id,
        sold: m.sold,
        prices: m.variants.map((v) => ({ variant_id: v.id, price: v.price?.trim() || null, compare_at_price: v.compare_at_price?.trim() || null })),
      })),
    })).data.data
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <NCard v-if="loaded && markets.length" :title="t('市场与价格')" size="small">
    <template #header-extra>
      <NButton size="small" type="primary" secondary :loading="saving" @click="save">{{ t('保存市场设置') }}</NButton>
    </template>
    <div class="fit-bar">
      <span class="hint">{{ t('市场适合度：按当地趋势、本市场的浏览和订单、季节和插头等规则打分（每天自动更新），各国店铺首页优先展示适合的商品。') }}</span>
      <NButton size="tiny" quaternary :loading="scoring === 'rules'" :disabled="!!scoring" @click="rescore(false)">{{ t('重新评估') }}</NButton>
      <NButton size="tiny" secondary :loading="scoring === 'ai'" :disabled="!!scoring" @click="rescore(true)">{{ t('AI 判断') }}</NButton>
    </div>
    <p class="hint">
      {{ t('不填价格时，按店铺价格（{0}）和市场的汇率、加价比例、价格尾数自动换算，灰色数字就是买家看到的价格。', [storeCurrency]) }}
      <RouterLink :to="{ name: 'settings-markets' }">{{ t('管理市场') }}</RouterLink>
    </p>
    <div v-for="m in markets" :key="m.id" class="market">
      <div class="head">
        <NCheckbox v-model:checked="m.sold">{{ t('在「{0}」销售', [m.name]) }}</NCheckbox>
        <NTag size="small" :bordered="false">{{ m.currency }}</NTag>
        <span v-if="m.fit && m.fit.score !== null" class="fit" :class="fitLevel(m.fit)">{{ t('适合度 {0}', [m.fit.score]) }}</span>
        <span v-else class="fit">{{ t('适合度未知') }}</span>
        <NTag v-if="!m.enabled" size="small" :bordered="false" type="warning">{{ t('未启用') }}</NTag>
        <NTag v-else-if="!m.has_rate" size="small" :bordered="false" type="error">{{ t('缺少汇率') }}</NTag>
      </div>
      <div v-if="m.fit && (m.fit.reasons.length || m.fit.tags.length)" class="fit-detail">
        <span v-for="(v, k) in m.fit.parts" :key="k" class="part">{{ partNames[k] ?? k }} {{ v }}</span>
        <ul v-if="m.fit.reasons.length"><li v-for="(r, i) in m.fit.reasons" :key="i">{{ r }}</li></ul>
        <div v-if="m.fit.tags.length" class="part-tags">{{ t('当地标签：') }}{{ m.fit.tags.join(' · ') }}</div>
      </div>
      <div v-if="m.sold" class="table-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>{{ t('规格') }}</th>
              <th>{{ t('店铺价格（{0}）', [storeCurrency]) }}</th>
              <th>{{ t('市场价格（{0}）', [m.currency]) }}</th>
              <th>{{ t('划线价（{0}）', [m.currency]) }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="v in m.variants" :key="v.id">
              <td class="vname">{{ label(v) }}<span v-if="v.sku" class="sku">{{ v.sku }}</span></td>
              <td class="num">{{ v.base_price }}</td>
              <td><NInput v-model:value="v.price" size="small" :placeholder="v.auto_price ?? '—'" :aria-label="t('市场价格')" style="width: 130px" /></td>
              <td><NInput v-model:value="v.compare_at_price" size="small" :placeholder="v.auto_compare_at_price ?? '—'" :aria-label="t('划线价')" style="width: 130px" /></td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="hint off">{{ t('这个市场的店铺里看不到这个商品，也无法下单。') }}</p>
    </div>
  </NCard>
</template>

<style scoped>
.hint { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0 0 12px; }
.hint.off { margin: 6px 0 0 26px; }
.market { border-top: 1px solid var(--border); padding: 12px 0 4px; }
.market:first-of-type { border-top: 0; padding-top: 0; }
.head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.table-wrap { overflow-x: auto; margin-top: 8px; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { text-align: left; font-weight: 500; color: var(--ink-muted); padding: 6px 8px; background: var(--surface-muted); white-space: nowrap; }
.tbl td { padding: 6px 8px; border-top: 1px solid var(--border); }
.num { font-variant-numeric: tabular-nums; color: var(--ink-muted); }
.vname { white-space: nowrap; }
.sku { margin-left: 8px; color: var(--ink-muted); font-size: 12px; }
.fit-bar { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
.fit-bar .hint { margin: 0; flex: 1 1 260px; }
.fit { font-size: 12px; line-height: 20px; padding: 0 7px; border-radius: 10px; border: 1px solid var(--border); color: var(--ink-muted); font-variant-numeric: tabular-nums; }
.fit.good { border-color: var(--brand-500); color: var(--brand-700); }
.fit.low { opacity: 0.75; }
.fit.bad { border-color: var(--danger); color: var(--danger); }
.fit-detail { margin: 6px 0 0 26px; font-size: 12px; color: var(--ink-muted); line-height: 1.6; }
.fit-detail ul { margin: 2px 0 0; padding-left: 16px; }
.part { margin-right: 10px; }
.part-tags { margin-top: 2px; }
</style>
