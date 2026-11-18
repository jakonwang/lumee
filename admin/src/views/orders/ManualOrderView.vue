<script setup lang="ts">
/**
 * Create an order in the admin (phone / chat / wholesale / replacement orders).
 * Products at their price or a custom one, optional discount and shipping, paid now or pending.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  NAlert, NButton, NCard, NCheckbox, NEmpty, NIcon, NInput, NInputNumber, NRadioGroup, NRadio, NSelect, NSwitch, useMessage,
} from 'naive-ui'
import { ArrowBackOutline, CloseOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

interface Variant { id: number; product_id: number; title: string; variant_title: string | null; sku: string | null; price: string; inventory: number; image: string | null; status: string }
interface Line { variant: Variant; quantity: number; price: number }

const route = useRoute()
const router = useRouter()
const message = useMessage()
const auth = useAuth()
const currency = computed(() => auth.currentStore?.currency ?? '')

// ---- Products ----
const q = ref('')
const results = ref<Variant[]>([])
const countries = ref<{ label: string; value: string }[]>([])
const lines = ref<Line[]>([])
let timer: ReturnType<typeof setTimeout> | undefined
async function search(withCountries = false) {
  const { data } = await http.get('/orders/manual/variants', { params: { q: q.value || undefined, countries: withCountries ? 1 : undefined } })
  results.value = data.data
  if (data.countries) countries.value = data.countries.map((c: { code: string; name: string }) => ({ label: c.name, value: c.code }))
}
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => search(), 250) })
onMounted(() => search(true))

function add(v: Variant) {
  const existing = lines.value.find((l) => l.variant.id === v.id)
  if (existing) existing.quantity++
  else lines.value.push({ variant: v, quantity: 1, price: Number(v.price) })
}

// ---- Customer & delivery ----
const c = reactive({
  email: String(route.query.email ?? ''), first_name: '', last_name: '', phone: '',
  address1: '', address2: '', city: '', province: '', zip: '', country: null as string | null,
})

// ---- Money ----
const shipping = reactive({ name: '', price: 0 })
const discount = reactive({ type: 'amount' as 'amount' | 'percent', value: 0, reason: '' })
const chargeTax = ref(false)
const payment = ref<'paid' | 'pending'>('paid')
const notify = ref(false)
const allowOversell = ref(false)
const note = ref('')

const subtotal = computed(() => lines.value.reduce((s, l) => s + l.price * l.quantity, 0))
const discountAmount = computed(() => {
  if (!discount.value) return 0
  return discount.type === 'percent' ? Math.round(subtotal.value * Math.min(100, discount.value)) / 100 : Math.min(subtotal.value, discount.value)
})
const total = computed(() => Math.max(0, subtotal.value - discountAmount.value + (shipping.price || 0)))
const fmt = (n: number) => `${currency.value} ${n.toFixed(2)}`
const short = (l: Line) => l.quantity > l.variant.inventory

const saving = ref(false)
const canSave = computed(() => lines.value.length > 0 && /.+@.+\..+/.test(c.email))
async function create() {
  saving.value = true
  try {
    const { data } = await http.post('/orders/manual', {
      ...c,
      country: c.country || undefined,
      items: lines.value.map((l) => ({ variant_id: l.variant.id, quantity: l.quantity, price: l.price })),
      shipping_name: shipping.name || undefined, shipping_price: shipping.price || 0,
      discount_type: discount.type, discount_value: discount.value || 0, discount_reason: discount.reason || undefined,
      charge_tax: chargeTax.value, payment: payment.value, notify: notify.value, note: note.value || undefined,
      allow_oversell: allowOversell.value,
    })
    message.success(t('订单 {0} 已创建', [data.data.name]))
    router.push({ name: 'order', params: { id: data.data.id } })
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <NButton text class="back" @click="router.push({ name: 'orders' })">
      <template #icon><NIcon><ArrowBackOutline /></NIcon></template>{{ t('订单') }}
    </NButton>
    <h1 class="page-title">{{ t('创建订单') }}</h1>
    <p class="page-sub">{{ t('为电话、聊天、批发或补发的订单手动下单。库存会像普通订单一样扣减。') }}</p>

    <div class="grid">
      <div class="main">
        <NCard :title="t('商品')" size="small">
          <NInput v-model:value="q" :placeholder="t('搜索商品名称或 SKU')" clearable />
          <div class="results">
            <button v-for="v in results.slice(0, 8)" :key="v.id" type="button" class="result" @click="add(v)">
              <img v-if="v.image" :src="v.image" alt="">
              <span v-else class="ph" />
              <span class="r-main"><span class="r-title">{{ v.title }}</span><span class="muted">{{ [v.variant_title, v.sku].filter(Boolean).join(' · ') }}</span></span>
              <span class="muted">{{ t('库存 {0}', [v.inventory]) }}</span>
              <span class="num">{{ currency }} {{ v.price }}</span>
            </button>
            <div v-if="!results.length" class="muted pad">{{ t('没有找到商品') }}</div>
          </div>

          <NEmpty v-if="!lines.length" :description="t('点击上面的商品加入订单')" size="small" class="empty" />
          <table v-else class="lines">
            <thead><tr><th>{{ t('商品') }}</th><th class="num">{{ t('单价') }}</th><th class="num">{{ t('数量') }}</th><th class="num">{{ t('金额') }}</th><th /></tr></thead>
            <tbody>
              <tr v-for="(l, i) in lines" :key="l.variant.id">
                <td>
                  <div class="r-title">{{ l.variant.title }}</div>
                  <div class="muted">{{ l.variant.variant_title }}</div>
                  <div v-if="short(l)" class="warn">{{ t('库存只有 {0} 件', [l.variant.inventory]) }}</div>
                </td>
                <td class="num"><NInputNumber v-model:value="l.price" :min="0" :precision="2" size="small" :show-button="false" style="width: 100px" /></td>
                <td class="num"><NInputNumber v-model:value="l.quantity" :min="1" :max="10000" size="small" style="width: 96px" /></td>
                <td class="num">{{ fmt(l.price * l.quantity) }}</td>
                <td><NButton quaternary circle size="tiny" :aria-label="t('移除')" @click="lines.splice(i, 1)"><template #icon><NIcon><CloseOutline /></NIcon></template></NButton></td>
              </tr>
            </tbody>
          </table>
          <NCheckbox v-if="lines.some(short)" v-model:checked="allowOversell" class="oversell">{{ t('库存不足也允许下单（库存会变成负数）') }}</NCheckbox>
        </NCard>

        <NCard :title="t('付款')" size="small">
          <div class="money">
            <div class="m-row">
              <span>{{ t('折扣') }}</span>
              <div class="m-inputs">
                <NSelect v-model:value="discount.type" size="small" style="width: 90px" :options="[{ label: currency, value: 'amount' }, { label: '%', value: 'percent' }]" />
                <NInputNumber v-model:value="discount.value" :min="0" size="small" :show-button="false" style="width: 100px" />
                <NInput v-model:value="discount.reason" size="small" :placeholder="t('原因（可选）')" style="width: 160px" />
              </div>
              <span class="num">−{{ fmt(discountAmount) }}</span>
            </div>
            <div class="m-row">
              <span>{{ t('运费') }}</span>
              <div class="m-inputs">
                <NInput v-model:value="shipping.name" size="small" :placeholder="t('配送方式名称')" style="width: 196px" />
                <NInputNumber v-model:value="shipping.price" :min="0" :precision="2" size="small" :show-button="false" style="width: 100px" />
              </div>
              <span class="num">{{ fmt(shipping.price || 0) }}</span>
            </div>
            <div class="m-row">
              <span>{{ t('税费') }}</span>
              <div class="m-inputs"><NSwitch v-model:value="chargeTax" size="small" /><span class="muted">{{ t('按店铺税费设置计算（创建时算出）') }}</span></div>
              <span />
            </div>
            <div class="m-row total"><span>{{ t('合计') }}</span><span /><span class="num">{{ fmt(total) }}<span v-if="chargeTax" class="muted"> + {{ t('税费') }}</span></span></div>
          </div>
          <div class="pay">
            <NRadioGroup v-model:value="payment">
              <NRadio value="paid">{{ t('已收款（现金、转账等）') }}</NRadio>
              <NRadio value="pending">{{ t('待付款（货到付款、稍后转账）') }}</NRadio>
            </NRadioGroup>
          </div>
        </NCard>
      </div>

      <div class="side">
        <NCard :title="t('客户')" size="small">
          <div class="form">
            <NInput v-model:value="c.email" :placeholder="t('邮箱（必填）')" />
            <div class="two"><NInput v-model:value="c.first_name" :placeholder="t('名')" /><NInput v-model:value="c.last_name" :placeholder="t('姓')" /></div>
            <NInput v-model:value="c.phone" :placeholder="t('电话')" />
          </div>
        </NCard>
        <NCard :title="t('收货地址')" size="small">
          <div class="form">
            <NSelect v-model:value="c.country" :options="countries" filterable clearable :placeholder="t('国家/地区')" />
            <NInput v-model:value="c.address1" :placeholder="t('地址')" />
            <NInput v-model:value="c.address2" :placeholder="t('公寓、楼层等（可选）')" />
            <div class="two"><NInput v-model:value="c.city" :placeholder="t('城市')" /><NInput v-model:value="c.province" :placeholder="t('州/省')" /></div>
            <NInput v-model:value="c.zip" :placeholder="t('邮编')" />
          </div>
        </NCard>
        <NCard :title="t('备注')" size="small">
          <NInput v-model:value="note" type="textarea" :autosize="{ minRows: 2 }" maxlength="2000" :placeholder="t('会显示在订单详情里')" />
        </NCard>
        <NCard size="small">
          <NCheckbox v-model:checked="notify">{{ t('给客户发送订单确认邮件') }}</NCheckbox>
          <NAlert v-if="!canSave && lines.length" type="warning" :bordered="false" class="note">{{ t('请填写客户邮箱') }}</NAlert>
          <NButton type="primary" block size="large" class="create" :disabled="!canSave || (lines.some(short) && !allowOversell)" :loading="saving" @click="create">
            {{ t('创建订单') }}
          </NButton>
        </NCard>
      </div>
    </div>
  </div>
</template>

<style scoped>
.back { margin-bottom: 12px; }
.grid { display: grid; grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr); gap: 16px; align-items: start; }
.main, .side { display: grid; gap: 16px; }
.results { margin-top: 8px; border: 1px solid var(--border); border-radius: 10px; max-height: 280px; overflow-y: auto; }
.result { display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 12px; border: 0; border-bottom: 1px solid var(--border); background: none; cursor: pointer; font: inherit; text-align: left; color: var(--ink); }
.result:last-child { border-bottom: 0; }
.result:hover { background: var(--brand-50); }
.result img, .ph { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; background: var(--surface-muted); flex: none; }
.r-main { flex: 1; min-width: 0; display: grid; }
.r-title { font-weight: 500; font-size: 13px; }
.muted { color: var(--ink-muted); font-size: 12px; }
.pad { padding: 12px; }
.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.empty { padding: 20px 0 4px; }
.lines { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 13px; }
.lines th { text-align: left; font-size: 12px; font-weight: 500; color: var(--ink-muted); padding: 6px 4px; border-bottom: 1px solid var(--border); }
.lines th.num { text-align: right; }
.lines td { padding: 8px 4px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.warn { color: var(--danger); font-size: 12px; }
.oversell { margin-top: 10px; }
.money { display: grid; gap: 10px; }
.m-row { display: grid; grid-template-columns: 70px 1fr 120px; align-items: center; gap: 10px; font-size: 13px; }
.m-inputs { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.m-row.total { border-top: 1px solid var(--border); padding-top: 10px; font-weight: 600; font-size: 15px; }
.pay { margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--border); }
.form { display: grid; gap: 8px; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.note { margin-top: 10px; }
.create { margin-top: 12px; }
@media (max-width: 960px) { .grid { grid-template-columns: 1fr; } }
</style>
