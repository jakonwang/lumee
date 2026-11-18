<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { NCard, NButton, NSpin, NRadioGroup, NRadio, NCheckbox, NSelect, NInput, NInputNumber, NEmpty, NDropdown, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'
import SettingsNav from '@/components/SettingsNav.vue'

interface Rate { country: string; region: string | null; name: string; rate: number | null }
interface Preset { key: string; name: string; rates: { country: string; rate: number }[]; countries: number }

const message = useMessage()
const loading = ref(true)
const saving = ref(false)
const dirty = ref(false)
const settings = reactive({ included: false, shipping: false, stripe_tax: false })
const stripeTaxAvailable = ref(false)
const rates = ref<Rate[]>([])
const presets = ref<Preset[]>([])
const countries = ref<{ code: string; name: string }[]>([])

const countryOptions = computed(() => countries.value.map((c) => ({ value: c.code, label: `${c.name} (${c.code})` })))
const presetLabels: Record<string, string> = {
  eu_vat: t('欧盟 27 国增值税（标准税率）'), uk_vat: t('英国 VAT 20%'), au_gst: t('澳大利亚 GST 10%'), nz_gst: t('新西兰 GST 15%'),
  ca_gst: t('加拿大 GST 5%（各省 PST/HST 需另外添加）'), ch_vat: t('瑞士 VAT 8.1%'), no_vat: t('挪威 VAT 25%'), jp_ct: t('日本消费税 10%'), sg_gst: t('新加坡 GST 9%'),
}
const presetOptions = computed(() => presets.value.map((p) => ({ key: p.key, label: presetLabels[p.key] ?? p.key })))

function apply(d: { settings: { included: boolean; shipping: boolean; stripe_tax?: boolean }; stripe_tax_available?: boolean; rates: Rate[]; presets: Preset[]; countries: { code: string; name: string }[] }) {
  Object.assign(settings, d.settings)
  stripeTaxAvailable.value = !!d.stripe_tax_available
  rates.value = d.rates.map((r) => ({ ...r }))
  presets.value = d.presets
  countries.value = d.countries
  dirty.value = false
}

async function load() {
  try {
    apply((await http.get('/settings/taxes')).data)
  } finally {
    loading.value = false
  }
}

function addRate() {
  rates.value.push({ country: '', region: null, name: 'VAT', rate: null })
  dirty.value = true
}

function addPreset(key: string) {
  const p = presets.value.find((x) => x.key === key)
  if (!p) return
  let added = 0
  for (const r of p.rates) {
    if (rates.value.some((x) => x.country === r.country && !x.region)) continue   // keep what the merchant set
    rates.value.push({ country: r.country, region: null, name: p.name, rate: r.rate })
    added++
  }
  rates.value.sort((a, b) => a.country.localeCompare(b.country))
  dirty.value = true
  message.success(t('已添加 {0} 个国家的税率，保存后生效', [added]))
}

function remove(i: number) {
  rates.value.splice(i, 1)
  dirty.value = true
}

async function save() {
  const bad = rates.value.find((r) => !r.country || r.rate === null || !r.name.trim())
  if (bad) {
    message.error(t('请完整填写每一行的国家、税名和税率'))
    return
  }
  saving.value = true
  try {
    apply((await http.put('/settings/taxes', { ...settings, rates: rates.value })).data)
    message.success(t('税费设置已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
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
      <NCard :title="t('计税方式')" size="small">
        <NRadioGroup v-model:value="settings.included" @update:value="dirty = true">
          <div class="radios">
            <NRadio :value="true">{{ t('商品价格已含税（欧盟、英国、澳洲等常见做法）') }}</NRadio>
            <NRadio :value="false">{{ t('结账时另外加税（美国、加拿大常见做法）') }}</NRadio>
          </div>
        </NRadioGroup>
        <NCheckbox v-model:checked="settings.shipping" style="margin-top: 12px" @update:checked="dirty = true">{{ t('运费也收税') }}</NCheckbox>
        <p class="muted">{{ t('含税时，订单总价不变，系统会算出其中的税额并显示在结账页和订单里；不含税时，税额加在总价上。商品可以在编辑页单独设为「不收税」。') }}</p>
        <NCheckbox v-model:checked="settings.stripe_tax" :disabled="settings.included || !stripeTaxAvailable" style="margin-top: 12px" @update:checked="dirty = true">{{ t('美国订单使用 Stripe Tax 按详细地址自动计算销售税') }}</NCheckbox>
        <p class="muted">{{ stripeTaxAvailable ? t('按邮编、城市、街道精确计算州 + 县 + 市的销售税。需要先在 Stripe 后台开通 Stripe Tax 并登记你有纳税义务的州；计算失败时自动改用下面的税率。') : t('平台还没有配置 Stripe，暂时不能使用 Stripe Tax。') }}</p>
      </NCard>

      <NCard :title="t('税率')" size="small">
        <template #header-extra>
          <NDropdown trigger="click" :options="presetOptions" @select="addPreset">
            <NButton size="small">{{ t('添加常用税率') }}</NButton>
          </NDropdown>
        </template>
        <p class="muted" style="margin-top: 0">{{ t('按收货国家匹配税率；美国等需要按州收税时，可以填写州/省代码（如 CA、NY），优先于整个国家的税率。没有设置税率的国家不收税。常用税率仅供参考，请以当地税务规定为准。') }}</p>
        <NEmpty v-if="!rates.length" :description="t('还没有税率，所有订单都不收税')" style="padding: 16px 0" />
        <div v-else class="table">
          <div class="head-row"><span>{{ t('国家/地区') }}</span><span>{{ t('州/省代码（选填）') }}</span><span>{{ t('税名') }}</span><span>{{ t('税率 %') }}</span><span /></div>
          <div v-for="(r, i) in rates" :key="i" class="rate-row">
            <NSelect v-model:value="r.country" :options="countryOptions" filterable size="small" :placeholder="t('选择国家')" @update:value="dirty = true" />
            <NInput v-model:value="r.region" size="small" :placeholder="t('整个国家')" maxlength="50" @update:value="dirty = true" />
            <NInput v-model:value="r.name" size="small" maxlength="50" @update:value="dirty = true" />
            <NInputNumber v-model:value="r.rate" size="small" :min="0" :max="100" :precision="3" :show-button="false" @update:value="dirty = true" />
            <NButton size="small" quaternary @click="remove(i)">{{ t('删除') }}</NButton>
          </div>
        </div>
        <NButton size="small" dashed style="margin-top: 12px" @click="addRate">+ {{ t('添加税率') }}</NButton>
      </NCard>

      <div class="actions">
        <span v-if="dirty" class="muted">{{ t('有未保存的修改') }}</span>
        <NButton type="primary" :loading="saving" :disabled="!dirty" @click="save">{{ t('保存') }}</NButton>
      </div>
    </template>
  </div>
</template>

<style scoped>
.page { max-width: 860px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.radios { display: grid; gap: 8px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 12px 0 0; }
.table { display: grid; gap: 6px; margin-top: 12px; }
.head-row, .rate-row { display: grid; grid-template-columns: 1.6fr 1fr 1fr 0.8fr auto; gap: 8px; align-items: center; }
.head-row { font-size: 12px; color: var(--ink-muted); }
.actions { display: flex; justify-content: flex-end; align-items: center; gap: 12px; }
@media (max-width: 640px) { .head-row { display: none; } .rate-row { grid-template-columns: 1fr 1fr; } }
</style>
