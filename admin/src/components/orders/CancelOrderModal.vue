<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { NModal, NButton, NSelect, NCheckbox, NAlert, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { type Order, cancelReasons } from '@/api/orders'
import { t } from '@/i18n'

const show = defineModel<boolean>('show', { required: true })
const props = defineProps<{ order: Order }>()
const emit = defineEmits<{ done: [order: Order] }>()
const message = useMessage()

const reason = ref('customer')
const refund = ref(true)
const restock = ref(true)
const notify = ref(true)
const busy = ref(false)

const canRefund = computed(() => Number(props.order.refundable) > 0)
const online = computed(() => !!props.order.refund_info?.refunds_online)

watch(show, (v) => {
  if (!v) return
  reason.value = 'customer'
  refund.value = true
  restock.value = true
  notify.value = true
})

async function submit() {
  busy.value = true
  try {
    const { data } = await http.post(`/orders/${props.order.id}/cancel`, {
      reason: reason.value,
      refund: canRefund.value && refund.value,
      restock: restock.value,
      notify: notify.value,
    })
    message.success(t('订单已取消'))
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
  <NModal v-model:show="show" preset="card" :title="t('取消订单 {0}', [order.name])" style="width: min(460px, 94vw)" :mask-closable="!busy">
    <div class="field">
      <div class="label">{{ t('取消原因') }}</div>
      <NSelect v-model:value="reason" :options="cancelReasons" />
    </div>
    <div class="checks">
      <NCheckbox v-if="canRefund" v-model:checked="refund">
        {{ t('全额退款 {0} {1}{2}', [order.currency, order.refundable, online ? t('（通过 Stripe 原路退回）') : t('（需线下退给买家）')]) }}
      </NCheckbox>
      <NCheckbox v-model:checked="restock">{{ t('把商品加回库存') }}</NCheckbox>
      <NCheckbox v-model:checked="notify">{{ t('给买家发送订单取消邮件') }}</NCheckbox>
    </div>
    <NAlert v-if="canRefund && !refund" type="warning" :bordered="false" style="margin-top: 12px">
      {{ t('买家已经付款。不退款的话，之后可以在订单页单独退款。') }}
    </NAlert>
    <p class="muted">{{ t('取消后订单不能再发货，也不能恢复。') }}</p>
    <div class="foot">
      <NButton @click="show = false">{{ t('返回') }}</NButton>
      <NButton type="error" :loading="busy" @click="submit">{{ t('确认取消订单') }}</NButton>
    </div>
  </NModal>
</template>

<style scoped>
.field { display: grid; gap: 6px; }
.label { font-size: 13px; }
.checks { display: grid; gap: 8px; margin-top: 16px; }
.muted { color: var(--ink-muted); font-size: 12px; margin: 14px 0 0; }
.foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
</style>
