<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  NCard, NButton, NInput, NInputNumber, NSelect, NSpin, NAlert, NModal, NForm, NFormItem, NRadioGroup, NRadioButton,
  NEmpty, useMessage, useDialog,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import SettingsNav from '@/components/SettingsNav.vue'
import PickupSettingsCard from '@/components/PickupSettingsCard.vue'
import { t } from '@/i18n'

interface Rate {
  id?: number
  name: string
  description: string | null
  price: string
  condition: 'none' | 'subtotal' | 'weight'
  min: string | number | null
  max: string | number | null
}
interface Zone { id?: number; name: string; countries: string[]; rates: Rate[] }

const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const saving = ref(false)
const dirty = ref(false)
const currency = ref('USD')
const countries = ref<{ code: string; name: string }[]>([])
const zones = ref<Zone[]>([])

const countryName = computed(() => Object.fromEntries(countries.value.map((c) => [c.code, c.name])))
const hasRestOfWorld = computed(() => zones.value.some((z) => !z.countries.length))

async function load() {
  loading.value = true
  try {
    apply((await http.get('/settings/shipping')).data)
  } finally {
    loading.value = false
  }
}

function apply(data: { currency: string; countries: { code: string; name: string }[]; zones: Zone[] }) {
  currency.value = data.currency
  countries.value = data.countries
  zones.value = data.zones
  dirty.value = false
}

async function save() {
  saving.value = true
  try {
    apply((await http.put('/settings/shipping', { zones: zones.value })).data)
    message.success(t('运费设置已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

// ── zone editor ──────────────────────────────────────────────────────────
const zoneModal = ref(false)
const zoneIndex = ref<number | null>(null)
const zoneDraft = ref<{ name: string; countries: string[] }>({ name: '', countries: [] })

// Countries used by other zones can't be picked again
const countryOptions = computed(() => {
  const taken = new Set(zones.value.filter((_, i) => i !== zoneIndex.value).flatMap((z) => z.countries))
  return countries.value.map((c) => ({ label: `${c.name} (${c.code})`, value: c.code, disabled: taken.has(c.code) }))
})

function editZone(i: number | null) {
  zoneIndex.value = i
  const z = i === null ? null : zones.value[i]
  zoneDraft.value = { name: z?.name ?? '', countries: [...(z?.countries ?? [])] }
  zoneModal.value = true
}

function saveZone() {
  const d = zoneDraft.value
  if (!d.name.trim()) return message.warning(t('请填写区域名称'))
  if (!d.countries.length && hasRestOfWorld.value && (zoneIndex.value === null || zones.value[zoneIndex.value].countries.length)) {
    return message.warning(t('已经有一个「其他国家」区域了，请选择国家'))
  }
  if (zoneIndex.value === null) zones.value.push({ name: d.name.trim(), countries: d.countries, rates: [] })
  else Object.assign(zones.value[zoneIndex.value], { name: d.name.trim(), countries: d.countries })
  dirty.value = true
  zoneModal.value = false
}

function removeZone(i: number) {
  dialog.warning({
    title: t('删除区域「{0}」？', [zones.value[i].name]),
    content: t('区域里的运费也会一起删除。保存后生效。'),
    positiveText: t('删除'),
    negativeText: t('取消'),
    onPositiveClick: () => { zones.value.splice(i, 1); dirty.value = true },
  })
}

// ── rate editor ──────────────────────────────────────────────────────────
const rateModal = ref(false)
const rateTarget = ref<[number, number | null]>([0, null])
const rateDraft = ref<{ name: string; description: string; price: number | null; condition: Rate['condition']; min: number | null; max: number | null }>({
  name: '', description: '', price: 0, condition: 'none', min: null, max: null,
})

function editRate(zi: number, ri: number | null) {
  rateTarget.value = [zi, ri]
  const r = ri === null ? null : zones.value[zi].rates[ri]
  rateDraft.value = {
    name: r?.name ?? '',
    description: r?.description ?? '',
    price: r ? Number(r.price) : 0,
    condition: r?.condition ?? 'none',
    min: r?.min !== null && r?.min !== undefined ? Number(r.min) : null,
    max: r?.max !== null && r?.max !== undefined ? Number(r.max) : null,
  }
  rateModal.value = true
}

function saveRate() {
  const d = rateDraft.value
  if (!d.name.trim()) return message.warning(t('请填写运费名称'))
  if (d.price === null) return message.warning(t('请填写运费'))
  if (d.condition !== 'none' && d.min !== null && d.max !== null && d.max <= d.min) return message.warning(t('上限必须大于下限'))
  const [zi, ri] = rateTarget.value
  const money = d.condition === 'subtotal'
  const rate: Rate = {
    ...(ri !== null ? { id: zones.value[zi].rates[ri].id } : {}),
    name: d.name.trim(),
    description: d.description.trim() || null,
    price: d.price.toFixed(2),
    condition: d.condition,
    min: d.condition === 'none' || d.min === null ? null : money ? d.min.toFixed(2) : d.min,
    max: d.condition === 'none' || d.max === null ? null : money ? d.max.toFixed(2) : d.max,
  }
  if (ri === null) zones.value[zi].rates.push(rate)
  else zones.value[zi].rates[ri] = rate
  dirty.value = true
  rateModal.value = false
}

function removeRate(zi: number, ri: number) {
  zones.value[zi].rates.splice(ri, 1)
  dirty.value = true
}

function conditionText(r: Rate): string {
  if (r.condition === 'none') return t('所有订单')
  const unit = r.condition === 'weight' ? (v: string | number) => `${Number(v) >= 1000 ? `${Number(v) / 1000} kg` : `${v} g`}` : (v: string | number) => `${currency.value} ${v}`
  const label = r.condition === 'weight' ? t('重量') : t('订单金额')
  if (r.min !== null && r.max !== null) return `${label} ${unit(r.min)} – ${unit(r.max)}`
  if (r.min !== null) return `${label} ≥ ${unit(r.min)}`
  if (r.max !== null) return `${label} < ${unit(r.max)}`
  return t('所有订单')
}

function countriesText(z: Zone): string {
  if (!z.countries.length) return t('其他所有国家和地区（没有被其他区域包含的）')
  const names = z.countries.map((c) => countryName.value[c] ?? c)
  return names.length > 6 ? t('{0} 等 {1} 个国家', [names.slice(0, 6).join(t('、')), names.length]) : names.join(t('、'))
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
      <p class="lead">
        {{ t('按国家分区设置运费。买家结账时，系统按收货国家找到对应区域，再按订单金额或重量筛选可用的运费。 没有被任何区域包含的国家使用「其他国家」区域；如果没有这个区域，这些国家的买家将无法下单。') }}
      </p>

      <NAlert v-if="!hasRestOfWorld" type="warning" :bordered="false">
        {{ t('目前只配送到下面列出的国家。要配送到全球，请添加一个不选国家的区域。') }}
      </NAlert>

      <NCard v-for="(z, zi) in zones" :key="z.id ?? `new-${zi}`" size="small">
        <template #header>
          <div class="zone-title">{{ z.name }}</div>
          <div class="muted">{{ countriesText(z) }}</div>
        </template>
        <template #header-extra>
          <NButton size="small" quaternary @click="editZone(zi)">{{ t('编辑区域') }}</NButton>
          <NButton size="small" quaternary @click="removeZone(zi)">{{ t('删除') }}</NButton>
        </template>

        <div v-if="!z.rates.length" class="muted empty-rates">{{ t('这个区域还没有运费，买家将无法选择配送方式。') }}</div>
        <div v-for="(r, ri) in z.rates" :key="r.id ?? `r-${ri}`" class="rate">
          <div class="rate-main">
            <div class="rate-name">{{ r.name }}<span v-if="r.description" class="muted"> · {{ r.description }}</span></div>
            <div class="muted">{{ conditionText(r) }}</div>
          </div>
          <div class="rate-price">{{ Number(r.price) === 0 ? t('免运费') : `${currency} ${r.price}` }}</div>
          <div class="rate-actions">
            <NButton size="tiny" quaternary @click="editRate(zi, ri)">{{ t('编辑') }}</NButton>
            <NButton size="tiny" quaternary @click="removeRate(zi, ri)">{{ t('删除') }}</NButton>
          </div>
        </div>
        <NButton size="small" dashed style="margin-top: 10px" @click="editRate(zi, null)">{{ t('+ 添加运费') }}</NButton>
      </NCard>

      <NEmpty v-if="!zones.length" :description="t('还没有配送区域')" />

      <div class="actions">
        <NButton @click="editZone(null)">{{ t('+ 添加区域') }}</NButton>
        <span v-if="dirty" class="muted">{{ t('有未保存的修改') }}</span>
        <NButton type="primary" :loading="saving" :disabled="!dirty" @click="save">{{ t('保存') }}</NButton>
      </div>
    </template>

    <PickupSettingsCard v-if="!loading" />

    <NModal v-model:show="zoneModal" preset="card" :title="zoneIndex === null ? t('添加区域') : t('编辑区域')" style="width: min(520px, 94vw)">
      <NForm label-placement="top" @submit.prevent="saveZone">
        <NFormItem :label="t('区域名称（只有你能看到）')"><NInput v-model:value="zoneDraft.name" :placeholder="t('例如：北美、欧盟、东南亚')" maxlength="100" /></NFormItem>
        <NFormItem :label="t('国家和地区')">
          <NSelect v-model:value="zoneDraft.countries" :options="countryOptions" multiple filterable clearable max-tag-count="responsive"
            :placeholder="t('不选 = 其他所有国家和地区')" />
        </NFormItem>
        <div class="modal-foot">
          <NButton @click="zoneModal = false">{{ t('取消') }}</NButton>
          <NButton type="primary" attr-type="submit">{{ t('确定') }}</NButton>
        </div>
      </NForm>
    </NModal>

    <NModal v-model:show="rateModal" preset="card" :title="t('运费')" style="width: min(520px, 94vw)">
      <NForm label-placement="top" @submit.prevent="saveRate">
        <NFormItem :label="t('名称（买家结账时看到）')"><NInput v-model:value="rateDraft.name" :placeholder="t('例如：Standard Shipping')" maxlength="100" /></NFormItem>
        <NFormItem :label="t('送达时间说明（选填）')"><NInput v-model:value="rateDraft.description" :placeholder="t('例如：7–12 business days')" maxlength="150" /></NFormItem>
        <NFormItem :label="t('运费')">
          <NInputNumber v-model:value="rateDraft.price" :min="0" :precision="2" style="width: 100%">
            <template #prefix>{{ currency }}</template>
          </NInputNumber>
        </NFormItem>
        <NFormItem :label="t('适用条件')">
          <NRadioGroup v-model:value="rateDraft.condition">
            <NRadioButton value="none">{{ t('所有订单') }}</NRadioButton>
            <NRadioButton value="subtotal">{{ t('按订单金额') }}</NRadioButton>
            <NRadioButton value="weight">{{ t('按重量') }}</NRadioButton>
          </NRadioGroup>
        </NFormItem>
        <div v-if="rateDraft.condition !== 'none'" class="grid">
          <NFormItem :label="rateDraft.condition === 'weight' ? t('最小重量（含）') : t('最低金额（含）')">
            <NInputNumber v-model:value="rateDraft.min" :min="0" :precision="rateDraft.condition === 'weight' ? 0 : 2" clearable :placeholder="t('不限')" style="width: 100%">
              <template #suffix>{{ rateDraft.condition === 'weight' ? 'g' : currency }}</template>
            </NInputNumber>
          </NFormItem>
          <NFormItem :label="rateDraft.condition === 'weight' ? t('最大重量（不含）') : t('最高金额（不含）')">
            <NInputNumber v-model:value="rateDraft.max" :min="0" :precision="rateDraft.condition === 'weight' ? 0 : 2" clearable :placeholder="t('不限')" style="width: 100%">
              <template #suffix>{{ rateDraft.condition === 'weight' ? 'g' : currency }}</template>
            </NInputNumber>
          </NFormItem>
        </div>
        <p v-if="rateDraft.condition === 'subtotal'" class="muted hint">{{ t('按折扣后的商品金额计算。例如「最低金额 99、运费 0」就是满 99 包邮。') }}</p>
        <p v-if="rateDraft.condition === 'weight'" class="muted hint">{{ t('按购物车里所有商品的总重量计算，商品重量在商品编辑页的每个 SKU 上填写。') }}</p>
        <div class="modal-foot">
          <NButton @click="rateModal = false">{{ t('取消') }}</NButton>
          <NButton type="primary" attr-type="submit">{{ t('确定') }}</NButton>
        </div>
      </NForm>
    </NModal>
  </div>
</template>

<style scoped>
.page { max-width: 760px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.lead { margin: 0; color: var(--ink-muted); font-size: 14px; line-height: 1.7; }
.zone-title { font-weight: 600; font-size: 15px; }
.muted { color: var(--ink-muted); font-size: 13px; font-weight: 400; }
.empty-rates { padding: 8px 0; }
.rate { display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center; padding: 10px 0; border-top: 1px solid var(--border); }
.rate:first-of-type { border-top: 0; }
.rate-name { font-size: 14px; }
.rate-price { font-variant-numeric: tabular-nums; font-size: 14px; white-space: nowrap; }
.rate-actions { display: flex; gap: 2px; }
.actions { display: flex; align-items: center; gap: 12px; justify-content: space-between; }
.actions .muted { margin-left: auto; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
.hint { margin: -8px 0 12px; }
.modal-foot { display: flex; justify-content: flex-end; gap: 8px; }
@media (max-width: 560px) { .rate { grid-template-columns: 1fr auto; } .rate-actions { grid-column: 1 / -1; } }
</style>
