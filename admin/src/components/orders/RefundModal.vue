<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { NModal, NButton, NInputNumber, NInput, NCheckbox, NAlert, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import type { Order } from '@/api/orders'
import { t } from '@/i18n'

const show = defineModel<boolean>('show', { required: true })
const props = defineProps<{ order: Order }>()
const emit = defineEmits<{ done: [order: Order] }>()
const message = useMessage()

const cents = (v: string | number | null | undefined) => Math.round(Number(v ?? 0) * 100)
const fmt = (c: number) => (c / 100).toFixed(2)

const qty = reactive<Record<number, number>>({})
const shipping = ref<number | null>(0)
const amount = ref<number | null>(0)
const amountTouched = ref(false)
const reason = ref('')
const restock = ref(true)
const notify = ref(true)
const busy = ref(false)

const lines = computed(() => (props.order.items ?? []).map((i) => ({ ...i, left: i.quantity - i.refunded_quantity })))
const refundable = computed(() => cents(props.order.refundable))
const shippingLeft = computed(() => cents(props.order.refund_info?.shipping_refundable))
const online = computed(() => !!props.order.refund_info?.refunds_online)

// Order-level discount is spread over items proportionally, like Shopify's suggested refund
const suggested = computed(() => {
  const subtotal = cents(props.order.subtotal)
  const ratio = subtotal > 0 ? 1 - cents(props.order.discount_total) / subtotal : 1
  const items = lines.value.reduce((sum, l) => sum + cents(l.price) * (qty[l.id] ?? 0), 0)
  return Math.min(refundable.value, Math.round(items * ratio) + cents(shipping.value ?? 0))
})
const selectedQty = computed(() => Object.values(qty).reduce((a, b) => a + (b || 0), 0))

watch(suggested, (v) => { if (!amountTouched.value) amount.value = v / 100 })
watch(show, (v) => {
  if (!v) return
  for (const l of lines.value) qty[l.id] = 0
  shipping.value = 0
  amount.value = 0
  amountTouched.value = false
  reason.value = ''
  restock.value = true
  notify.value = true
})

const amountCents = computed(() => cents(amount.value ?? 0))
const invalid = computed(() => {
  if (amountCents.value > refundable.value) return t('最多可退 {0} {1}', [props.order.currency, fmt(refundable.value)])
  if (cents(shipping.value ?? 0) > amountCents.value) return t('退款金额不能小于运费退款')
  if (amountCents.value === 0 && selectedQty.value === 0) return t('请选择要退的商品或填写金额')
  return ''
})

function selectAll() {
  for (const l of lines.value) qty[l.id] = l.left
  shipping.value = shippingLeft.value / 100
}

async function submit() {
  busy.value = true
  try {
    const { data } = await http.post(`/orders/${props.order.id}/refunds`, {
      items: lines.value.filter((l) => qty[l.id] > 0).map((l) => ({ id: l.id, quantity: qty[l.id] })),
      amount: fmt(amountCents.value),
      shipping_amount: fmt(cents(shipping.value ?? 0)),
      reason: reason.value || null,
      restock: restock.value,
      notify: notify.value,
    })
    message.success(amountCents.value > 0 ? t('已退款 {0} {1}', [props.order.currency, fmt(amountCents.value)]) : t('已记录退货'))
    emit('done', data.data)
    show.value = false
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <NModal v-model:show="show" preset="card" :title="t('退款 · {0}', [order.name])" style="width: min(600px, 94vw)" :mask-closable="!busy">
    <div class="head-row">
      <span class="muted">{{ t('选择要退的商品，系统会按折扣比例计算建议金额，可手动修改。') }}</span>
      <NButton text type="primary" size="small" @click="selectAll">{{ t('全部退款') }}</NButton>
    </div>

    <div v-for="l in lines" :key="l.id" class="line" :class="{ done: l.left === 0 }">
      <div class="name">
        {{ l.title }}
        <small v-if="l.variant_title">{{ l.variant_title }}</small>
        <small>{{ order.currency }} {{ l.price }} × {{ l.quantity }}<template v-if="l.refunded_quantity"> {{ t('· 已退 {0}', [l.refunded_quantity]) }}</template></small>
      </div>
      <NInputNumber v-if="l.left > 0" v-model:value="qty[l.id]" :min="0" :max="l.left" size="small" style="width: 110px">
        <template #suffix>/ {{ l.left }}</template>
      </NInputNumber>
      <span v-else class="muted">{{ t('已全部退回') }}</span>
    </div>

    <div class="line">
      <div class="name">{{ t('运费') }}<small>{{ t('还可退 {0} {1}', [order.currency, fmt(shippingLeft)]) }}</small></div>
      <NInputNumber v-model:value="shipping" :min="0" :max="shippingLeft / 100" :precision="2" size="small" style="width: 140px" :disabled="shippingLeft === 0" />
    </div>

    <div class="amount">
      <div>
        <div class="strong">{{ t('退款金额') }}</div>
        <div class="muted">{{ t('建议 {0} {1} · 最多 {2}', [order.currency, fmt(suggested), fmt(refundable)]) }}</div>
      </div>
      <NInputNumber v-model:value="amount" :min="0" :precision="2" style="width: 170px" @update:value="amountTouched = true">
        <template #prefix>{{ order.currency }}</template>
      </NInputNumber>
    </div>

    <NInput v-model:value="reason" :placeholder="t('退款原因（选填，买家可在退款邮件中看到）')" maxlength="255" style="margin-top: 14px" />

    <div class="checks">
      <NCheckbox v-model:checked="restock" :disabled="selectedQty === 0">{{ t('把退回的商品加回库存') }}</NCheckbox>
      <NCheckbox v-model:checked="notify" :disabled="amountCents === 0">{{ t('给买家发送退款通知邮件') }}</NCheckbox>
    </div>

    <NAlert v-if="amountCents > 0" :type="online ? 'info' : 'warning'" :bordered="false" style="margin-top: 14px">
      <template v-if="online">{{ t('金额会通过 Stripe 原路退回买家的银行卡，一般 5–10 个工作日到账。退款不可撤销。') }}</template>
      <template v-else>{{ t('这笔订单不是在线支付的，系统只做记录，请你自行把钱退给买家（如银行转账）。') }}</template>
    </NAlert>

    <div class="foot">
      <span v-if="invalid" class="err">{{ invalid }}</span>
      <NButton @click="show = false">{{ t('取消') }}</NButton>
      <NButton type="primary" :disabled="!!invalid" :loading="busy" @click="submit">
        {{ amountCents > 0 ? t('退款 {0} {1}', [order.currency, fmt(amountCents)]) : t('仅退货') }}
      </NButton>
    </div>
  </NModal>
</template>

<style scoped>
.head-row { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 8px; }
.line { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 0; border-top: 1px solid var(--border); }
.line.done { opacity: .6; }
.name { font-size: 14px; }
.name small { display: block; color: var(--ink-muted); font-size: 12px; }
.amount { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 6px; padding: 14px; background: var(--surface-muted); border-radius: 10px; }
.strong { font-weight: 600; }
.muted { color: var(--ink-muted); font-size: 12px; }
.checks { display: grid; gap: 6px; margin-top: 14px; }
.foot { display: flex; justify-content: flex-end; align-items: center; gap: 8px; margin-top: 18px; }
.err { color: var(--danger); font-size: 13px; margin-right: auto; }
</style>
