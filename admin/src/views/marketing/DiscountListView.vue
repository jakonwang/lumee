<script setup lang="ts">
import { computed, h, onMounted, reactive, ref } from 'vue'
import DiscountSwitch from '@/components/DiscountSwitch.vue'
import {
  NButton, NCard, NDataTable, NTag, NModal, NForm, NFormItem, NInput, NInputNumber, NRadioGroup, NRadioButton,
  NSwitch, NDatePicker, NEmpty, NSpace, useMessage, useDialog, type DataTableColumns,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Discount {
  id: number
  code: string
  type: 'percentage' | 'fixed' | 'free_shipping'
  value: number | string
  min_subtotal: string | null
  usage_limit: number | null
  usage_count: number
  once_per_customer: boolean
  active: boolean
  starts_at: string | null
  ends_at: string | null
  status: 'active' | 'scheduled' | 'expired' | 'used_up' | 'disabled'
  summary: string
}

const message = useMessage()
const dialog = useDialog()
const auth = useAuth()
const currency = computed(() => auth.currentStore?.currency ?? 'USD')

const rows = ref<Discount[]>([])
const loading = ref(true)
const show = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)

const blank = () => ({
  code: '',
  type: 'percentage' as Discount['type'],
  value: 10 as number | null,
  min_subtotal: null as number | null,
  usage_limit: null as number | null,
  once_per_customer: false,
  active: true,
  range: null as [number, number] | null,
  noEnd: true,
  starts_at: null as number | null,
})
const form = reactive(blank())

const statusMap = {
  active: { label: t('生效中'), type: 'success' },
  scheduled: { label: t('未开始'), type: 'info' },
  expired: { label: t('已过期'), type: 'default' },
  used_up: { label: t('已用完'), type: 'warning' },
  disabled: { label: t('已停用'), type: 'default' },
} as const

const columns: DataTableColumns<Discount> = [
  { title: t('折扣码'), key: 'code', render: (r) => h('span', { class: 'code' }, r.code) },
  {
    title: t('优惠'), key: 'summary', minWidth: 160,
    render: (r) => h('div', [
      h('div', describe(r)),
      r.min_subtotal ? h('div', { class: 'muted' }, t('满 {0} {1} 可用', [currency.value, r.min_subtotal])) : null,
    ]),
  },
  {
    title: t('使用次数'), key: 'usage_count', width: 110,
    render: (r) => (r.usage_limit ? `${r.usage_count} / ${r.usage_limit}` : String(r.usage_count)),
  },
  {
    title: t('有效期'), key: 'ends_at', width: 200,
    render: (r) => h('div', { class: 'muted' }, t('{0} 至 {1}', [r.starts_at ? formatDate(r.starts_at) : t('立即'), r.ends_at ? formatDate(r.ends_at) : t('长期')])),
  },
  {
    title: t('状态'), key: 'status', width: 90,
    render: (r) => h(NTag, { size: 'small', bordered: false, type: statusMap[r.status].type }, { default: () => statusMap[r.status].label }),
  },
  {
    title: '', key: 'actions', width: 130, align: 'right',
    render: (r) => h(NSpace, { size: 4, justify: 'end' }, () => [
      h(NButton, { size: 'tiny', quaternary: true, onClick: () => edit(r) }, { default: () => t('编辑') }),
      h(NButton, { size: 'tiny', quaternary: true, type: 'error', onClick: () => remove(r) }, { default: () => t('删除') }),
    ]),
  },
]

function describe(r: Discount) {
  if (r.type === 'percentage') return t('全场 {0}% off', [r.value])
  if (r.type === 'fixed') return t('立减 {0} {1}', [currency.value, r.value])
  return t('免运费')
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/discounts')
    rows.value = data.data
  } finally {
    loading.value = false
  }
}

function create() {
  editingId.value = null
  Object.assign(form, blank(), { code: randomCode() })
  show.value = true
}

function edit(r: Discount) {
  editingId.value = r.id
  Object.assign(form, {
    code: r.code,
    type: r.type,
    value: r.type === 'free_shipping' ? null : Number(r.value),
    min_subtotal: r.min_subtotal ? Number(r.min_subtotal) : null,
    usage_limit: r.usage_limit,
    once_per_customer: r.once_per_customer,
    active: r.active,
    starts_at: r.starts_at ? Date.parse(r.starts_at) : null,
    noEnd: !r.ends_at,
    range: r.starts_at && r.ends_at ? [Date.parse(r.starts_at), Date.parse(r.ends_at)] : null,
  })
  show.value = true
}

function randomCode() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
  return 'SAVE' + Array.from({ length: 6 }, () => chars[Math.floor(Math.random() * chars.length)]).join('')
}

async function save() {
  saving.value = true
  try {
    const iso = (it: number | null | undefined) => (it ? new Date(it).toISOString() : null)
    const body = {
      code: form.code,
      type: form.type,
      value: form.type === 'free_shipping' ? 0 : form.value,
      min_subtotal: form.min_subtotal,
      usage_limit: form.usage_limit,
      once_per_customer: form.once_per_customer,
      active: form.active,
      starts_at: form.noEnd ? iso(form.starts_at) : iso(form.range?.[0]),
      ends_at: form.noEnd ? null : iso(form.range?.[1]),
    }
    if (editingId.value) await http.put(`/discounts/${editingId.value}`, body)
    else await http.post('/discounts', body)
    message.success(t('已保存'))
    show.value = false
    load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

function remove(r: Discount) {
  dialog.warning({
    title: t('删除折扣码 {0}？', [r.code]),
    content: t('删除后买家无法再使用这个折扣码。已经用它下单的订单不受影响。'),
    positiveText: t('删除'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.delete(`/discounts/${r.id}`)
      message.success(t('已删除'))
      load()
    },
  })
}

onMounted(load)
</script>

<template>
  <div>
    <DiscountSwitch />
    <div class="head">
      <div>
        <div class="eyebrow">Marketing</div>
        <h1 class="page-title">{{ t('折扣码') }}</h1>
        <p class="page-sub">{{ t('创建折扣码，买家在购物车中输入即可享受优惠') }}</p>
      </div>
      <NButton type="primary" @click="create">{{ t('创建折扣码') }}</NButton>
    </div>

    <NCard content-style="padding: 0">
      <NEmpty v-if="!loading && !rows.length" :description="t('还没有折扣码')" style="padding: 56px 0">
        <template #extra><NButton size="small" @click="create">{{ t('创建第一个折扣码') }}</NButton></template>
      </NEmpty>
      <NDataTable v-else :columns="columns" :data="rows" :loading="loading" :bordered="false" :scroll-x="820" />
    </NCard>

    <NModal v-model:show="show" preset="card" :title="editingId ? t('编辑折扣码') : t('创建折扣码')" style="width: min(520px, 94vw)">
      <NForm label-placement="top" @submit.prevent="save">
        <NFormItem :label="t('折扣码')">
          <NInput v-model:value="form.code" :placeholder="t('例如 SUMMER20')" maxlength="40" style="text-transform: uppercase" />
          <NButton quaternary size="small" style="margin-left: 8px" @click="form.code = randomCode()">{{ t('随机生成') }}</NButton>
        </NFormItem>
        <NFormItem :label="t('优惠类型')">
          <NRadioGroup v-model:value="form.type">
            <NRadioButton value="percentage">{{ t('百分比折扣') }}</NRadioButton>
            <NRadioButton value="fixed">{{ t('固定金额') }}</NRadioButton>
            <NRadioButton value="free_shipping">{{ t('免运费') }}</NRadioButton>
          </NRadioGroup>
        </NFormItem>
        <NFormItem v-if="form.type === 'percentage'" :label="t('折扣比例')">
          <NInputNumber v-model:value="form.value" :min="1" :max="100" :precision="0" style="width: 160px">
            <template #suffix>% off</template>
          </NInputNumber>
        </NFormItem>
        <NFormItem v-else-if="form.type === 'fixed'" :label="t('减免金额')">
          <NInputNumber v-model:value="form.value" :min="0.01" :precision="2" style="width: 200px">
            <template #prefix>{{ currency }}</template>
          </NInputNumber>
        </NFormItem>
        <NFormItem :label="t('最低消费（可选）')">
          <NInputNumber v-model:value="form.min_subtotal" :min="0" :precision="2" clearable :placeholder="t('不限')" style="width: 200px">
            <template #prefix>{{ currency }}</template>
          </NInputNumber>
        </NFormItem>
        <div class="grid2">
          <NFormItem :label="t('总使用次数（可选）')">
            <NInputNumber v-model:value="form.usage_limit" :min="1" :precision="0" clearable :placeholder="t('不限')" style="width: 100%" />
          </NFormItem>
          <NFormItem :label="t('每位买家限用一次')">
            <NSwitch v-model:value="form.once_per_customer" />
          </NFormItem>
        </div>
        <NFormItem :label="t('有效期')">
          <div class="dates">
            <label class="check"><NSwitch v-model:value="form.noEnd" size="small" /> {{ t('长期有效') }}</label>
            <NDatePicker v-if="form.noEnd" v-model:value="form.starts_at" type="datetime" clearable :placeholder="t('开始时间（不填则立即生效）')" style="width: 100%" />
            <NDatePicker v-else v-model:value="form.range" type="datetimerange" clearable style="width: 100%" />
          </div>
        </NFormItem>
        <NFormItem :label="t('启用')">
          <NSwitch v-model:value="form.active" />
        </NFormItem>
        <NButton type="primary" block attr-type="submit" :loading="saving">{{ t('保存') }}</NButton>
      </NForm>
    </NModal>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
:deep(.code) { font-family: ui-monospace, monospace; font-weight: 600; letter-spacing: .04em; }
:deep(.muted) { color: var(--ink-muted); font-size: 12px; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
.dates { display: grid; gap: 8px; width: 100%; }
.check { display: flex; align-items: center; gap: 8px; font-size: 13px; }
</style>
