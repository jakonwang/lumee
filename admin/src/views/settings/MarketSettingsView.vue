<script setup lang="ts">
/**
 * Settings › Markets: country sites. Each market has its own URL (/{language}-{suffix}/), currency
 * (charged at checkout), languages and prices; the store itself stays the default site.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import {
  NButton, NCard, NEmpty, NForm, NFormItem, NInput, NInputNumber, NModal, NSelect, NSpin, NSwitch, NTag,
  useDialog, useMessage,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import SettingsNav from '@/components/SettingsNav.vue'
import MarketLocalizationDrawer from '@/components/MarketLocalizationDrawer.vue'
import { t } from '@/i18n'

interface Market {
  id: number; name: string; handle: string; countries: string[]; currency: string; default_locale: string; locales: string[]
  price_adjustment: number; rounding: string; exchange_rate: number | null; enabled: boolean
  daily_rate: number | null; rate: number | null; url: string; excluded_products: number; fixed_prices: number
  settings: { duties: { mode: string; rate: number; threshold: string }; preset: string | null }
  payments: { key: string; name: string; enabled: boolean }[]
}
interface Payload {
  data: Market[]; store_currency: string; store_url: string; currencies: string[]
  languages: { code: string; name: string }[]; countries: { code: string; name: string }[]; rounding: string[]
  presets: { key: string; name: string; rounding: string; duty_mode: string; payments: string[]; tips: string[] }[]
  live_theme_id: number
}
const localizing = ref<number | null>(null)   // market id whose localization drawer is open

const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const p = ref<Payload | null>(null)

async function load() {
  loading.value = true
  try {
    p.value = (await http.get('/markets')).data
  } finally {
    loading.value = false
  }
}
onMounted(load)

const roundingLabels: Record<string, string> = {
  none: t('不处理（例如 26.91）'), '0.99': t('尾数 .99（例如 26.99）'), '0.95': t('尾数 .95（例如 26.95）'), '0.90': t('尾数 .90（例如 26,90，巴西常用）'),
  '1': t('取整到 1（例如 27）'), '10': t('取整到 10（例如 270）'), '100': t('取整到 100（例如 2,700）'), '1000': t('取整到 1,000（例如 89,000，印尼盾常用）'),
}
const countryName = (c: string) => p.value?.countries.find((x) => x.code === c)?.name ?? c
const languageName = (c: string) => p.value?.languages.find((x) => x.code === c)?.name ?? c

// Editor
const show = ref(false)
const saving = ref(false)
const editing = ref<Market | null>(null)
const blank = () => ({ name: '', handle: '', countries: [] as string[], currency: 'EUR', default_locale: 'en', locales: [] as string[], price_adjustment: 0, rounding: '0.99', exchange_rate: null as number | null, enabled: true })
const form = reactive(blank())

function open(m?: Market) {
  editing.value = m ?? null
  Object.assign(form, m ? { name: m.name, handle: m.handle, countries: [...m.countries], currency: m.currency, default_locale: m.default_locale, locales: m.locales.filter((l) => l !== m.default_locale), price_adjustment: m.price_adjustment, rounding: m.rounding, exchange_rate: m.exchange_rate, enabled: m.enabled } : blank())
  show.value = true
}

const takenCountries = computed(() => new Set((p.value?.data ?? []).filter((m) => m.id !== editing.value?.id).flatMap((m) => m.countries)))
const countryOptions = computed(() => (p.value?.countries ?? []).map((c) => ({ label: `${c.name} (${c.code})`, value: c.code, disabled: takenCountries.value.has(c.code) })))
const languageOptions = computed(() => (p.value?.languages ?? []).map((l) => ({ label: l.name, value: l.code })))
const currencyOptions = computed(() => (p.value?.currencies ?? []).map((c) => ({ label: c, value: c })))
const roundingOptions = computed(() => (p.value?.rounding ?? []).map((r) => ({ label: roundingLabels[r] ?? r, value: r })))
const dailyRate = computed(() => (editing.value && editing.value.currency === form.currency ? editing.value.daily_rate : null))
const urlPreview = computed(() => `${p.value?.store_url ?? ''}/${form.default_locale}-${form.handle || '…'}/`)

// Same math as the server (MarketContext::convertPrice + Market::round) for the preview line
function round(cents: number, r: string): number {
  if (cents <= 0) return 0
  if (['0.99', '0.95', '0.90'].includes(r)) {
    const p2 = Math.floor(cents / 100) * 100 + Math.round(Number(r) * 100)
    return p2 >= cents ? p2 : p2 + 100
  }
  if (['1', '10', '100', '1000'].includes(r)) return Math.ceil(cents / (Number(r) * 100)) * Number(r) * 100
  return cents
}
const example = computed(() => {
  const rate = form.exchange_rate || dailyRate.value
  if (!rate) return null
  const cents = round(Math.round(2990 * rate * (1 + (form.price_adjustment || 0) / 100)), form.rounding)
  return (cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
})

async function save() {
  saving.value = true
  try {
    const body = { ...form, handle: form.handle.trim().toLowerCase() }
    if (editing.value) await http.put(`/markets/${editing.value.id}`, body)
    else await http.post('/markets', body)
    message.success(t('已保存'))
    show.value = false
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

function remove(m: Market) {
  dialog.warning({
    title: t('删除市场「{0}」？', [m.name]),
    content: t('这个市场的网址会失效，它的固定价格和商品范围设置会一起删除。已有订单不受影响。'),
    positiveText: t('删除'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        await http.delete(`/markets/${m.id}`)
        message.success(t('已删除'))
        await load()
      } catch (e) {
        message.error(errorMessage(e))
      }
    },
  })
}

async function toggle(m: Market, enabled: boolean) {
  try {
    await http.put(`/markets/${m.id}`, { ...m, locales: m.locales, enabled })
    m.enabled = enabled
  } catch (e) {
    message.error(errorMessage(e))
  }
}
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Settings</div>
      <h1 class="page-title">{{ t('设置') }}</h1>
    </div>
    <SettingsNav />

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else-if="p">
      <NCard size="small">
        <div class="row">
          <div>
            <div class="title">{{ t('市场') }}</div>
            <div class="muted">
              {{ t('为不同国家开设独立站点：每个市场有自己的网址、语言和价格，买家用当地货币下单和付款。没有单独设置市场的国家，继续访问默认站点（{0}）。', [p.store_currency]) }}
            </div>
          </div>
          <NButton type="primary" @click="open()">{{ t('添加市场') }}</NButton>
        </div>
      </NCard>

      <NCard size="small" class="list">
        <div class="market default">
          <div class="info">
            <div class="name">{{ t('默认站点') }} <NTag size="small" :bordered="false">{{ p.store_currency }}</NTag></div>
            <div class="muted">{{ t('其他所有国家 · {0}', [p.store_url + '/']) }}</div>
          </div>
        </div>
        <NEmpty v-if="!p.data.length" :description="t('还没有市场。比如添加「美国」「墨西哥」「欧洲」，分别用美元、比索、欧元销售。')" style="padding: 24px 0" />
        <div v-for="m in p.data" :key="m.id" class="market">
          <div class="info">
            <div class="name">
              {{ m.name }}
              <NTag size="small" :bordered="false">{{ m.currency }}</NTag>
              <NTag v-if="m.rate === null" size="small" :bordered="false" type="error">{{ t('缺少汇率') }}</NTag>
            </div>
            <div class="muted">{{ m.countries.map(countryName).join('、') }}</div>
            <div class="muted">
              <a :href="m.url" target="_blank" rel="noopener">{{ m.url }}</a>
              · {{ m.locales.map(languageName).join(' / ') }}
              <template v-if="m.rate"> · 1 {{ p.store_currency }} = {{ m.rate }} {{ m.currency }}{{ m.exchange_rate ? t('（自定义）') : '' }}</template>
            </div>
            <div v-if="m.excluded_products || m.fixed_prices" class="muted">
              {{ t('{0} 个商品不在此销售 · {1} 个规格设置了固定价格', [m.excluded_products, m.fixed_prices]) }}
            </div>
          </div>
          <div class="actions">
            <NSwitch :value="m.enabled" :aria-label="t('启用')" @update:value="(v: boolean) => toggle(m, v)" />
            <NButton size="small" type="primary" secondary @click="localizing = m.id">{{ t('本地化') }}</NButton>
            <NButton size="small" secondary @click="open(m)">{{ t('编辑') }}</NButton>
            <NButton size="small" quaternary type="error" @click="remove(m)">{{ t('删除') }}</NButton>
          </div>
        </div>
      </NCard>
    </template>

    <NModal v-model:show="show" preset="card" :title="editing ? t('编辑市场') : t('添加市场')" style="max-width: 600px" :mask-closable="false">
      <NForm label-placement="top">
        <div class="two">
          <NFormItem :label="t('市场名称')"><NInput v-model:value="form.name" :placeholder="t('例如：墨西哥')" maxlength="80" /></NFormItem>
          <NFormItem :label="t('网址后缀')">
            <NInput v-model:value="form.handle" placeholder="mx" maxlength="12" />
          </NFormItem>
        </div>
        <p class="muted preview">{{ t('网址：{0}', [urlPreview]) }}</p>
        <NFormItem :label="t('国家/地区')">
          <NSelect v-model:value="form.countries" :options="countryOptions" multiple filterable :placeholder="t('选择这个市场覆盖的国家')" />
        </NFormItem>
        <div class="two">
          <NFormItem :label="t('货币')"><NSelect v-model:value="form.currency" :options="currencyOptions" filterable /></NFormItem>
          <NFormItem :label="t('自定义汇率（1 {0} =）', [p?.store_currency ?? ''])">
            <NInputNumber v-model:value="form.exchange_rate" :min="0" :precision="6" clearable :placeholder="dailyRate ? t('每日汇率 {0}', [dailyRate]) : t('使用每日汇率')" style="width: 100%" />
          </NFormItem>
        </div>
        <div class="two">
          <NFormItem :label="t('默认语言')"><NSelect v-model:value="form.default_locale" :options="languageOptions" /></NFormItem>
          <NFormItem :label="t('其他语言')">
            <NSelect v-model:value="form.locales" :options="languageOptions.filter((o) => o.value !== form.default_locale)" multiple :placeholder="t('可选')" />
          </NFormItem>
        </div>
        <div class="two">
          <NFormItem :label="t('价格调整（%）')">
            <NInputNumber v-model:value="form.price_adjustment" :min="-90" :max="500" :precision="2" style="width: 100%" />
          </NFormItem>
          <NFormItem :label="t('价格尾数')"><NSelect v-model:value="form.rounding" :options="roundingOptions" /></NFormItem>
        </div>
        <p class="muted">
          {{ t('价格调整用来覆盖运费、关税和当地定价策略，例如 +15 表示在换算后的价格上加价 15%。单个商品也可以在商品页设置固定价格。') }}
        </p>
        <p v-if="example" class="muted">{{ t('示例：店铺价 {0} 29.90 → {1} {2}', [p?.store_currency ?? '', form.currency, example]) }}</p>
        <NFormItem :label="t('启用')" label-placement="left" :show-feedback="false">
          <NSwitch v-model:value="form.enabled" />
        </NFormItem>
      </NForm>
      <template #footer>
        <div class="foot">
          <NButton @click="show = false">{{ t('取消') }}</NButton>
          <NButton type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton>
        </div>
      </template>
    </NModal>

    <MarketLocalizationDrawer :market="(p?.data.find((m) => m.id === localizing) as any) ?? null" :presets="p?.presets ?? []" :theme-id="p?.live_theme_id ?? null" @close="localizing = null" @saved="load" />
  </div>
</template>

<style scoped>
.page { max-width: 860px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.row { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.title { font-weight: 600; font-size: 15px; margin-bottom: 4px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0; }
.muted a { color: var(--brand-700); word-break: break-all; }
.market { display: flex; justify-content: space-between; gap: 16px; align-items: center; padding: 14px 0; border-top: 1px solid var(--border); }
.market.default { border-top: 0; padding-top: 0; }
.name { font-weight: 600; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 2px; }
.actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
.preview { margin: -12px 0 12px; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
@media (max-width: 640px) {
  .row, .market { flex-direction: column; align-items: stretch; }
  .two { grid-template-columns: 1fr; }
}
</style>
