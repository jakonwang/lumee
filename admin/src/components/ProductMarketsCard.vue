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
interface MarketRow { id: number; name: string; currency: string; enabled: boolean; has_rate: boolean; sold: boolean; variants: VariantRow[] }

const markets = ref<MarketRow[]>([])
const loaded = ref(false)
const saving = ref(false)

async function load() {
  markets.value = (await http.get(`/products/${props.productId}/markets`)).data.data
  loaded.value = true
}
onMounted(load)

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
    <p class="hint">
      {{ t('不填价格时，按店铺价格（{0}）和市场的汇率、加价比例、价格尾数自动换算，灰色数字就是买家看到的价格。', [storeCurrency]) }}
      <RouterLink :to="{ name: 'settings-markets' }">{{ t('管理市场') }}</RouterLink>
    </p>
    <div v-for="m in markets" :key="m.id" class="market">
      <div class="head">
        <NCheckbox v-model:checked="m.sold">{{ t('在「{0}」销售', [m.name]) }}</NCheckbox>
        <NTag size="small" :bordered="false">{{ m.currency }}</NTag>
        <NTag v-if="!m.enabled" size="small" :bordered="false" type="warning">{{ t('未启用') }}</NTag>
        <NTag v-else-if="!m.has_rate" size="small" :bordered="false" type="error">{{ t('缺少汇率') }}</NTag>
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
</style>
