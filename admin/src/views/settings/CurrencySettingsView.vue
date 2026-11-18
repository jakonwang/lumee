<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { NCard, NSwitch, NSelect, NRadioGroup, NRadio, NButton, NSpin, NAlert, NInputNumber, NCheckbox, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import SettingsNav from '@/components/SettingsNav.vue'
import { t } from '@/i18n'

interface Payload {
  settings: { enabled: boolean; currencies: string[]; rounding: 'none' | '0.99' | '1.00'; rates: Record<string, number>; auto_detect: boolean }
  store_currency: string
  currencies: { code: string; market_rate: number | null }[]
  rates_updated_at: string | null
}

const message = useMessage()
const loading = ref(true)
const saving = ref(false)
const refreshing = ref(false)
const storeCurrency = ref('USD')
const all = ref<Payload['currencies']>([])
const updatedAt = ref<string | null>(null)
const form = reactive({ enabled: false, currencies: [] as string[], rounding: '0.99' as Payload['settings']['rounding'], auto_detect: true })
const overrides = reactive<Record<string, number | null>>({})

const options = computed(() => all.value.map((c) => ({
  value: c.code,
  label: c.market_rate === null ? t('{0}（暂无汇率）', [c.code]) : c.code,
})))
const rows = computed(() => form.currencies.map((code) => ({ code, market: all.value.find((c) => c.code === code)?.market_rate ?? null })))

function apply(d: Payload) {
  storeCurrency.value = d.store_currency
  all.value = d.currencies
  updatedAt.value = d.rates_updated_at
  Object.assign(form, { enabled: d.settings.enabled, currencies: [...d.settings.currencies], rounding: d.settings.rounding, auto_detect: d.settings.auto_detect })
  for (const k of Object.keys(overrides)) delete overrides[k]
  Object.assign(overrides, d.settings.rates)
}

async function load() {
  loading.value = true
  try {
    apply((await http.get('/settings/currencies')).data)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const rates = Object.fromEntries(form.currencies.filter((c) => overrides[c]).map((c) => [c, overrides[c]]))
    apply((await http.put('/settings/currencies', { ...form, rates })).data)
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

async function refresh() {
  refreshing.value = true
  try {
    const d: Payload = (await http.post('/settings/currencies/refresh')).data
    all.value = d.currencies
    updatedAt.value = d.rates_updated_at
    message.success(t('汇率已更新'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    refreshing.value = false
  }
}

// Example for the rounding explanation: 1 store-currency unit of 29.90 in the first chosen currency
function example(rate: number | null, rounding: string) {
  if (!rate) return '—'
  let c = Math.round(2990 * rate)
  if (rounding === '1.00') c = Math.ceil(c / 100) * 100
  if (rounding === '0.99') c = (Math.floor(c / 100) + 1) * 100 - 1
  return (c / 100).toFixed(2)
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Settings</div>
      <h1 class="page-title">{{ t('设置') }}</h1>
    </div>
    <SettingsNav />

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else>
      <NCard size="small">
        <div class="row">
          <div>
            <div class="title">{{ t('多币种显示') }}</div>
            <div class="muted">{{ t('买家可以在店铺页脚切换货币，商品价格按每日汇率换算显示。结账和付款始终使用店铺货币 {0}，结账页会明确告诉买家实际扣款金额。', [storeCurrency]) }}</div>
          </div>
          <NSwitch v-model:value="form.enabled" />
        </div>
      </NCard>

      <template v-if="form.enabled">
        <NCard :title="t('可选货币')" size="small">
          <NSelect v-model:value="form.currencies" :options="options" multiple filterable :placeholder="t('选择买家可以切换的货币')" />
          <NCheckbox v-model:checked="form.auto_detect" style="margin-top: 12px">
            {{ t('根据买家所在国家自动选择货币（需要店铺接入 Cloudflare 等 CDN 提供国家信息）') }}
          </NCheckbox>
        </NCard>

        <NCard :title="t('价格取整')" size="small">
          <NRadioGroup v-model:value="form.rounding">
            <div class="radios">
              <NRadio value="0.99">{{ t('向上取到 .99（推荐，例如 26.91 → 26.99）') }}</NRadio>
              <NRadio value="1.00">{{ t('向上取整（例如 26.91 → 27.00）') }}</NRadio>
              <NRadio value="none">{{ t('不取整（例如 26.91）') }}</NRadio>
            </div>
          </NRadioGroup>
          <p v-if="rows[0]" class="muted" style="margin: 10px 0 0">
            {{ t('示例：{0} 29.90 显示为 {1} {2}', [storeCurrency, rows[0].code, example(overrides[rows[0].code] ?? rows[0].market, form.rounding)]) }}
          </p>
        </NCard>

        <NCard v-if="rows.length" :title="t('汇率')" size="small">
          <template #header-extra>
            <NButton size="small" :loading="refreshing" @click="refresh">{{ t('立即更新汇率') }}</NButton>
          </template>
          <p class="muted" style="margin: 0 0 12px">
            {{ t('每天自动更新。{0} 需要固定价格时可以填写自定义汇率，留空则使用每日汇率。', [updatedAt ? t('上次更新：{0}', [formatDate(updatedAt)]) : t('还没有获取过汇率，请点击「立即更新汇率」。')]) }}
          </p>
          <table class="tbl">
            <thead><tr><th>{{ t('货币') }}</th><th>{{ t('每日汇率（1 {0} =）', [storeCurrency]) }}</th><th>{{ t('自定义汇率') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in rows" :key="r.code">
                <td class="strong">{{ r.code }}</td>
                <td>{{ r.market ?? t('暂无') }}</td>
                <td><NInputNumber v-model:value="overrides[r.code]" size="small" :min="0" :precision="6" clearable :placeholder="t('使用每日汇率')" style="width: 180px" /></td>
              </tr>
            </tbody>
          </table>
          <NAlert v-if="rows.some((r) => r.market === null && !overrides[r.code])" type="warning" :bordered="false" style="margin-top: 12px">
            {{ t('没有汇率的货币暂时不会在店铺里显示。') }}
          </NAlert>
        </NCard>
      </template>

      <div><NButton type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton></div>
    </template>
  </div>
</template>

<style scoped>
.page { max-width: 760px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.row { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.title { font-weight: 600; font-size: 15px; margin-bottom: 4px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; }
.radios { display: grid; gap: 8px; }
.tbl { width: 100%; border-collapse: collapse; font-size: 14px; }
.tbl th { text-align: left; font-weight: 500; color: var(--ink-muted); padding: 6px 8px; background: var(--surface-muted); font-size: 13px; }
.tbl td { padding: 8px; border-top: 1px solid var(--border); font-variant-numeric: tabular-nums; }
.strong { font-weight: 600; }
</style>
