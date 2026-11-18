<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  NCard, NTag, NButton, NSpace, NModal, NForm, NFormItem, NInput, NSkeleton, NIcon, NDropdown, NCheckbox, NAlert,
  useMessage, useDialog,
} from 'naive-ui'
import RefundModal from '@/components/orders/RefundModal.vue'
import CancelOrderModal from '@/components/orders/CancelOrderModal.vue'
import EditOrderModal from '@/components/orders/EditOrderModal.vue'
import { ArrowBackOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import {
  type Order, financialLabel, fulfillmentLabel, trackingStatus, gatewayLabel, formatDate, notificationLabel, notificationStatus,
} from '@/api/orders'
import { t } from '@/i18n'
import { printOrder } from '@/utils/printOrder'
import { useAuth } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const message = useMessage()
const auth = useAuth()
const dialog = useDialog()

const order = ref<Order | null>(null)
const busy = ref(false)
const showFulfill = ref(false)
const editingTracking = ref(false)
const showRefund = ref(false)
const showCancel = ref(false)
const tracking = reactive({ tracking_company: '', tracking_number: '', notify: true })
const comment = ref('')
const commenting = ref(false)

const cancelled = computed(() => !!order.value?.cancelled_at)
const canRefund = computed(() => Number(order.value?.refundable ?? 0) > 0)
const canCancel = computed(() => !!order.value && !cancelled.value && order.value.fulfillment_status === 'unfulfilled')
const showEdit = ref(false)
const moreOptions = computed(() => [
  ...(order.value && !order.value.edit_blocker ? [{ key: 'edit', label: t('编辑订单') }] : []),
  { key: 'invoice', label: t('打印发票') },
  { key: 'packing', label: t('打印装箱单') },
  ...(canCancel.value ? [{ type: 'divider', key: 'd' }, { key: 'cancel', label: t('取消订单') }] : []),
])
function onMore(key: string) {
  if (key === 'cancel') showCancel.value = true
  if (key === 'edit') showEdit.value = true
  if ((key === 'invoice' || key === 'packing') && order.value) {
    if (!printOrder(order.value, key, { name: auth.currentStore?.name ?? '' })) message.warning(t('浏览器拦截了打印窗口，请允许弹出窗口后重试'))
  }
}

const addr = computed(() => order.value?.shipping_address ?? {})

async function load() {
  const { data } = await http.get(`/orders/${route.params.id}`)
  order.value = data.data
}

async function releaseRisk() {
  busy.value = true
  try {
    await http.post(`/orders/${order.value!.id}/risk/release`)
    message.success(t('已解除发货暂停'))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

async function blockBuyer(type: 'email' | 'ip', value: string) {
  try {
    await http.post('/settings/risk/blocklist', { type, value, note: t('来自订单 {0}', [order.value!.name]) })
    message.success(t('已加入黑名单，之后不能再下单'))
  } catch (e) {
    message.error(errorMessage(e))
  }
}

const riskMeta = { low: { label: t('低风险'), type: 'success' }, medium: { label: t('中风险'), type: 'warning' }, high: { label: t('高风险'), type: 'error' } } as const

async function act(path: string, body: object = {}, ok = t('已更新')) {
  busy.value = true
  try {
    const { data } = await http.post(`/orders/${order.value!.id}/${path}`, body)
    order.value = data.data
    message.success(ok)
    return true
  } catch (e) {
    message.error(errorMessage(e))
    return false
  } finally {
    busy.value = false
  }
}

function confirmPaid() {
  dialog.warning({
    title: t('确认已收到付款？'),
    content: t('订单 {0} 金额 {1} {2}，请确认已到账。', [order.value!.name, order.value!.currency, order.value!.total]),
    positiveText: t('确认已付款'),
    negativeText: t('取消'),
    onPositiveClick: () => act('mark-paid', {}, t('已标记为已付款')),
  })
}

// Resend menu: only emails that make sense for the order's current state
const resendOptions = computed(() => {
  const o = order.value
  if (!o) return []
  return [
    { key: 'order_confirmation', label: t('订单确认（发给买家）') },
    { key: 'payment_received', label: t('收款确认（发给买家）'), disabled: o.financial_status !== 'paid' || o.payment_gateway !== 'manual' },
    { key: 'shipping_confirmation', label: t('发货通知（发给买家）'), disabled: o.fulfillment_status !== 'fulfilled' },
    { key: 'order_cancelled', label: t('订单取消（发给买家）'), disabled: !o.cancelled_at },
    { key: 'refund_notification', label: t('退款通知（发给买家）'), disabled: o.refunded_total === '0.00' },
    { key: 'merchant_new_order', label: t('新订单提醒（发给店铺）') },
  ]
})

function resend(type: string) {
  dialog.info({
    title: t('重新发送「{0}」？', [notificationLabel[type]]),
    content: type === 'merchant_new_order' ? t('将发送到店铺的新订单提醒邮箱。') : t('将发送到 {0}。', [order.value!.email]),
    positiveText: t('发送'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        const { data } = await http.post(`/orders/${order.value!.id}/notifications/${type}`)
        message.success(data.message)
        setTimeout(load, 1500)
      } catch (e) {
        message.error(errorMessage(e))
      }
    },
  })
}

function openFulfill(edit = false) {
  editingTracking.value = edit
  tracking.tracking_company = edit ? (order.value?.tracking_company ?? '') : ''
  tracking.tracking_number = edit ? (order.value?.tracking_number ?? '') : ''
  tracking.notify = !edit
  showFulfill.value = true
}

async function fulfill() {
  if (!editingTracking.value) {
    if (await act('fulfill', tracking, t('已标记发货'))) showFulfill.value = false
    return
  }
  busy.value = true
  try {
    const { data } = await http.put(`/orders/${order.value!.id}/tracking`, tracking)
    order.value = data.data
    message.success(t('物流信息已更新'))
    showFulfill.value = false
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

async function addComment() {
  if (!comment.value.trim()) return
  commenting.value = true
  try {
    const { data } = await http.post(`/orders/${order.value!.id}/comments`, { message: comment.value })
    order.value = data.data
    comment.value = ''
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    commenting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="order-page">
    <NButton text @click="router.push({ name: 'orders' })" class="back">
      <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
      {{ t('订单') }}
    </NButton>

    <NSkeleton v-if="!order" text :repeat="6" />

    <template v-else>
      <div class="head">
        <h1 class="page-title">{{ order.name }}</h1>
        <NTag :type="financialLabel[order.financial_status].type" :bordered="false">{{ financialLabel[order.financial_status].label }}</NTag>
        <NTag v-if="cancelled" :bordered="false">{{ t('已取消') }}</NTag>
        <NTag v-else :type="fulfillmentLabel[order.fulfillment_status].type" :bordered="false">{{ fulfillmentLabel[order.fulfillment_status].label }}</NTag>
        <span class="date">{{ formatDate(order.created_at) }}</span>
        <NSpace style="margin-left: auto">
          <NButton v-if="canRefund" :disabled="busy" @click="showRefund = true">{{ t('退款') }}</NButton>
          <NButton v-if="order.financial_status === 'pending' && !cancelled" :loading="busy" @click="confirmPaid">{{ t('标记为已付款') }}</NButton>
          <NButton v-if="order.fulfillment_status === 'unfulfilled' && !cancelled" type="primary"
            :disabled="!['paid', 'partially_refunded'].includes(order.financial_status)" @click="openFulfill()">{{ t('发货') }}</NButton>
          <NDropdown trigger="click" :options="moreOptions" @select="onMore">
            <NButton>{{ t('更多') }}</NButton>
          </NDropdown>
        </NSpace>
      </div>

      <NAlert v-if="order.payment_gateway === 'terms' && order.financial_status === 'pending' && !cancelled" :type="order.payment_due_at && new Date(order.payment_due_at) < new Date() ? 'error' : 'info'" :bordered="false" style="margin-bottom: 16px"
        :title="t('账期付款 · 到期 {0}', [formatDate(order.payment_due_at ?? null)])">
        {{ order.company?.name }}{{ order.po_number ? ` · PO ${order.po_number}` : '' }} · {{ t('收到货款后点击「标记为已付款」。') }}
      </NAlert>
      <NAlert v-else-if="order.po_number || order.company" type="default" :bordered="false" style="margin-bottom: 16px">
        {{ order.company?.name }}{{ order.po_number ? ` · PO ${order.po_number}` : '' }}
      </NAlert>
      <NAlert v-if="cancelled" type="default" :bordered="false" style="margin-bottom: 16px" :title="t('订单已于 {0} 取消', [formatDate(order.cancelled_at)])">
        {{ t('原因：{0}', [order.cancel_reason_label]) }}<template v-if="canRefund">{{ t('。买家已付款但还没有全额退款，可以点击右上角「退款」。') }}</template>
      </NAlert>

      <NAlert v-if="Number(order.balance_due ?? 0) > 0 && !cancelled" type="warning" :bordered="false" style="margin-bottom: 16px"
        :title="t('订单修改后，买家还需要补付 {0} {1}', [order.currency, order.balance_due])">
        {{ t('请联系买家补付差价（例如银行转账），收到后点下面的按钮。') }}
        <div style="margin-top: 8px"><NButton size="small" :loading="busy" @click="act('balance-received', {}, t('已记录补付'))">{{ t('已收到补付') }}</NButton></div>
      </NAlert>

      <NAlert v-if="order.risk_hold && !cancelled" type="error" :bordered="false" style="margin-bottom: 16px" :title="t('高风险订单，已暂停发货')">
        {{ t('请核实买家信息（例如联系买家确认、查看付款详情）。确认没有问题后点击「确认无风险」再发货；如果是欺诈订单，建议取消并退款。') }}
        <div style="margin-top: 8px"><NButton size="small" :loading="busy" @click="releaseRisk">{{ t('确认无风险') }}</NButton></div>
      </NAlert>

      <NAlert v-if="order.dispute && !order.dispute.closed_at" type="warning" :bordered="false" style="margin-bottom: 16px"
        :title="t('买家对这笔付款提出了争议（拒付），金额 {0}', [`${(order.dispute.amount / 100).toFixed(2)} ${order.dispute.currency}`])">
        <template v-if="order.dispute.reason_label">{{ t('原因：{0}', [order.dispute.reason_label]) }}。</template>
        {{ t('请尽快整理发货凭证、物流单号和与买家的沟通记录，回复平台发给你的邮件，平台会代你向银行提交证据。') }}
        <template v-if="order.dispute.due_by">{{ t('提交截止时间：{0}', [formatDate(order.dispute.due_by)]) }}</template>
      </NAlert>
      <NAlert v-else-if="order.dispute" :type="order.dispute.status === 'won' ? 'success' : 'default'" :bordered="false" style="margin-bottom: 16px"
        :title="order.dispute.status === 'won' ? t('争议已结束：商家胜诉，款项已退回') : t('争议已结束：买家胜诉，款项已退给买家')" />

      <div class="grid">
        <div class="col">
          <NCard :title="t('订单商品')" size="small">
            <div v-for="i in order.items" :key="i.id" class="line">
              <div class="thumb"><img :src="i.image || '/placeholder.svg'" :class="{ ph: !i.image }" alt="" /></div>
              <div class="line-name">
                {{ i.title }}
                <small v-if="i.variant_title">{{ i.variant_title }}</small>
                <small v-if="i.sku">SKU: {{ i.sku }}</small>
                <small v-if="i.refunded_quantity" class="refunded">{{ t('已退 {0} 件', [i.refunded_quantity]) }}</small>
                <small v-if="i.preorder" class="pre">{{ t('预售') }}</small>
              </div>
              <div class="muted">{{ order.currency }} {{ i.price }} × {{ i.quantity }}</div>
              <div class="amt">{{ order.currency }} {{ i.total }}</div>
            </div>
          </NCard>

          <NCard :title="t('付款')" size="small">
            <div class="row"><span>{{ t('小计') }}</span><span>{{ order.currency }} {{ order.subtotal }}</span></div>
            <template v-if="order.discount_lines?.length">
              <div v-for="(d, i) in order.discount_lines" :key="i" class="row discount">
                <span>{{ d.type === 'code' ? t('折扣 · {0}', [d.title]) : t('活动 · {0}', [d.title]) }}</span><span>−{{ order.currency }} {{ d.amount }}</span>
              </div>
            </template>
            <div v-else-if="order.discount_code" class="row discount"><span>{{ t('折扣 · {0}', [order.discount_code]) }}</span><span>−{{ order.currency }} {{ order.discount_total }}</span></div>
            <div class="row"><span>{{ t('运费 · {0}', [order.shipping_method?.name]) }}</span><span>{{ order.currency }} {{ order.shipping_total }}</span></div>
            <div v-if="order.tax_total !== '0.00' && !order.taxes_included" class="row">
              <span>{{ order.tax_lines?.[0] ? `${order.tax_lines[0].name} ${order.tax_lines[0].rate}%` : t('税费') }}</span><span>{{ order.currency }} {{ order.tax_total }}</span>
            </div>
            <div v-if="order.gift_card_total && order.gift_card_total !== '0.00'" class="row discount"><span>{{ t('礼品卡') }}</span><span>−{{ order.currency }} {{ order.gift_card_total }}</span></div>
            <div class="row total"><span>{{ t('合计') }}</span><span>{{ order.currency }} {{ order.total }}</span></div>
            <div v-if="order.tax_total !== '0.00' && order.taxes_included" class="row muted">
              <span>{{ t('其中含税 {0}', [order.tax_lines?.[0] ? `${order.tax_lines[0].name} ${order.tax_lines[0].rate}%` : '']) }}</span><span>{{ order.currency }} {{ order.tax_total }}</span>
            </div>
            <template v-if="order.refunded_total !== '0.00'">
              <div class="row"><span>{{ t('已退款') }}</span><span>−{{ order.currency }} {{ order.refunded_total }}</span></div>
              <div class="row strong"><span>{{ t('净收入') }}</span><span>{{ order.currency }} {{ order.net_total }}</span></div>
            </template>
            <div class="row muted"><span>{{ t('支付方式') }}</span><span>{{ gatewayLabel[order.payment_gateway] ?? order.payment_gateway }}</span></div>
            <div v-if="order.paid_at" class="row muted"><span>{{ t('付款时间') }}</span><span>{{ formatDate(order.paid_at) }}</span></div>
            <div v-if="order.payment_reference" class="row muted"><span>{{ t('交易号') }}</span><span class="mono">{{ order.payment_reference }}</span></div>
          </NCard>

          <NCard v-if="order.refunds?.length" :title="t('退款记录')" size="small">
            <div v-for="r in order.refunds" :key="r.id" class="refund">
              <div class="row"><span class="strong">{{ order.currency }} {{ r.amount }}</span><span class="muted">{{ formatDate(r.created_at) }}</span></div>
              <div class="muted">
                {{ r.user ?? t('系统') }}<template v-if="r.reason"> · {{ r.reason }}</template><template v-if="r.restock"> {{ t('· 已回补库存') }}</template>
                <template v-if="r.reference"> · <span class="mono">{{ r.reference }}</span></template>
              </div>
            </div>
          </NCard>

          <NCard v-if="order.fulfillment_status === 'fulfilled'" :title="t('物流')" size="small">
            <template #header-extra><NButton size="tiny" quaternary @click="openFulfill(true)">{{ t('修改') }}</NButton></template>
            <div class="row"><span>{{ t('物流公司') }}</span><span>{{ order.tracking_company || '—' }}</span></div>
            <div class="row"><span>{{ t('运单号') }}</span><span class="mono">{{ order.tracking_number || '—' }}</span></div>
            <div class="row muted"><span>{{ t('发货时间') }}</span><span>{{ formatDate(order.fulfilled_at) }}</span></div>
            <template v-if="order.tracking_number && order.tracking_enabled">
              <div class="row">
                <span>{{ t('物流状态') }}</span>
                <span class="trk-status">
                  <NTag v-if="order.tracking_status" size="small" :bordered="false" :type="trackingStatus[order.tracking_status]?.type ?? 'default'">
                    {{ trackingStatus[order.tracking_status]?.label ?? order.tracking_status }}
                  </NTag>
                  <span v-else class="muted">{{ t('还没有查询结果') }}</span>
                  <NButton size="tiny" quaternary :loading="busy" @click="act('tracking/refresh', {}, t('物流信息已刷新'))">{{ t('刷新') }}</NButton>
                </span>
              </div>
              <div v-if="order.tracking_carrier" class="row muted"><span>{{ t('承运商') }}</span><span>{{ order.tracking_carrier }}</span></div>
              <div v-if="order.delivered_at" class="row muted"><span>{{ t('签收时间') }}</span><span>{{ formatDate(order.delivered_at) }}</span></div>
              <ul v-if="order.tracking_events.length" class="trk-events">
                <li v-for="(e, i) in order.tracking_events.slice(0, 6)" :key="i">
                  <span class="trk-time">{{ e.time ? formatDate(e.time) : '' }}</span>
                  <span>{{ e.description }}<span v-if="e.location" class="muted"> · {{ e.location }}</span></span>
                </li>
              </ul>
            </template>
            <a v-if="order.tracking_number" class="trk-link" :href="`https://t.17track.net/zh-cn#nums=${encodeURIComponent(order.tracking_number)}`" target="_blank" rel="noopener">{{ t('在 17TRACK 查看完整轨迹') }} →</a>
          </NCard>

          <NCard :title="t('订单动态')" size="small">
            <form class="comment" @submit.prevent="addComment">
              <NInput v-model:value="comment" :placeholder="t('添加内部备注（只有店铺员工能看到）')" maxlength="500" />
              <NButton attr-type="submit" :loading="commenting" :disabled="!comment.trim()">{{ t('添加备注') }}</NButton>
            </form>
            <div v-for="e in order.events" :key="e.id" class="event" :class="e.type">
              <span class="dot" />
              <div>
                <div class="event-msg">{{ e.message }}</div>
                <div class="muted">{{ formatDate(e.created_at) }}<template v-if="e.user"> · {{ e.user }}</template></div>
              </div>
            </div>
          </NCard>
        </div>

        <div class="col">
          <NCard v-if="order.risk_level" :title="t('风险分析')" size="small">
            <div class="risk-head">
              <NTag :type="riskMeta[order.risk_level].type" :bordered="false">{{ riskMeta[order.risk_level].label }}</NTag>
              <span class="muted">{{ t('风险分 {0} / 100', [order.risk_score ?? 0]) }}</span>
            </div>
            <ul v-if="order.risk_reasons?.length" class="risk-list">
              <li v-for="r in order.risk_reasons" :key="r.code">{{ r.label }} <span class="muted">+{{ r.points }}</span></li>
            </ul>
            <div v-else class="muted">{{ t('没有发现异常') }}</div>
            <div v-if="order.client_ip" class="muted" style="margin-top: 6px">IP {{ order.client_ip }}{{ order.client_country ? ` · ${order.client_country}` : '' }}</div>
            <div class="risk-actions">
              <NButton size="tiny" quaternary @click="blockBuyer('email', order.email)">{{ t('拉黑邮箱') }}</NButton>
              <NButton v-if="order.client_ip" size="tiny" quaternary @click="blockBuyer('ip', order.client_ip)">{{ t('拉黑 IP') }}</NButton>
            </div>
          </NCard>
          <NCard v-if="order.shipping_method?.pickup" :title="t('到店自提')" size="small">
            <div class="strong">{{ order.shipping_method.pickup.name }}</div>
            <div class="muted">{{ order.shipping_method.pickup.address }}</div>
            <div v-if="order.shipping_method.pickup.hours" class="muted">{{ order.shipping_method.pickup.hours }}</div>
            <p class="muted" style="margin: 6px 0 0">{{ t('买家来取货后，点「发货」标记为已完成（不需要填物流单号）。') }}</p>
          </NCard>
          <NCard v-if="order.downloads?.length" :title="t('数字商品下载')" size="small">
            <div v-for="d in order.downloads" :key="d.url" class="dl">
              <span class="dl-name">{{ d.filename }}</span>
              <span class="muted">{{ d.usable ? t('已下载 {0} 次，剩 {1} 次', [d.downloads, d.left]) : t('已失效') }}</span>
            </div>
            <NButton size="small" style="margin-top: 8px" :loading="busy" @click="act('downloads/reset', {}, t('下载链接已重置'))">{{ t('重置下载次数') }}</NButton>
          </NCard>
          <NCard :title="t('买家信息')" size="small">
            <div class="strong">{{ order.customer_name }}</div>
            <div class="muted">{{ order.email }}</div>
            <div v-if="addr.phone" class="muted">{{ addr.phone }}</div>
          </NCard>
          <NCard :title="t('收货地址')" size="small">
            <div class="addr">
              {{ addr.first_name }} {{ addr.last_name }}<br />
              {{ addr.address1 }}<template v-if="addr.address2">, {{ addr.address2 }}</template><br />
              {{ addr.city }}<template v-if="addr.province">, {{ addr.province }}</template> {{ addr.zip }}<br />
              {{ addr.country }}
            </div>
          </NCard>
          <NCard v-if="order.note" :title="t('买家备注')" size="small">{{ order.note }}</NCard>
          <NCard :title="t('邮件记录')" size="small">
            <template #header-extra>
              <NSpace :size="4">
                <NButton size="tiny" quaternary @click="load">{{ t('刷新') }}</NButton>
                <NDropdown trigger="click" :options="resendOptions" @select="resend">
                  <NButton size="tiny" secondary>{{ t('重新发送') }}</NButton>
                </NDropdown>
              </NSpace>
            </template>
            <p v-if="!order.notifications?.length" class="muted" style="margin: 0">{{ t('还没有发送过邮件') }}</p>
            <div v-for="n in order.notifications" :key="n.id" class="mail">
              <div class="mail-top">
                <span>{{ notificationLabel[n.type] ?? n.type }}</span>
                <NTag size="tiny" :bordered="false" :type="notificationStatus[n.status].type">{{ notificationStatus[n.status].label }}</NTag>
              </div>
              <div class="muted">{{ n.recipient }} · {{ formatDate(n.sent_at ?? n.created_at) }}</div>
              <div v-if="n.error" class="mail-error">{{ n.error }}</div>
            </div>
          </NCard>
        </div>
      </div>
    </template>

    <NModal v-model:show="showFulfill" preset="card" :title="editingTracking ? t('修改物流信息') : t('发货')" style="max-width: 440px">
      <NForm label-placement="top" @submit.prevent="fulfill">
        <NFormItem :label="t('物流公司')"><NInput v-model:value="tracking.tracking_company" :placeholder="t('例如：DHL / YunExpress / 4PX')" /></NFormItem>
        <NFormItem :label="t('运单号')"><NInput v-model:value="tracking.tracking_number" /></NFormItem>
        <NCheckbox v-model:checked="tracking.notify" style="margin-bottom: 16px">
          {{ editingTracking ? t('重新发送发货通知邮件给买家') : t('发送发货通知邮件给买家') }}
        </NCheckbox>
        <NButton type="primary" block attr-type="submit" :loading="busy">{{ editingTracking ? t('保存') : t('确认发货') }}</NButton>
      </NForm>
    </NModal>
    <RefundModal v-if="order" v-model:show="showRefund" :order="order" @done="(o) => (order = o)" />
    <CancelOrderModal v-if="order" v-model:show="showCancel" :order="order" @done="(o) => (order = o)" />
    <EditOrderModal v-if="order" v-model:show="showEdit" :order="order" @saved="(o) => (order = o)" />
  </div>
</template>

<style scoped>
.back { margin-bottom: 12px; color: var(--ink-muted); }
.head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; }
.head .page-title { margin: 0 6px 0 0; }
.date { color: var(--ink-muted); font-size: 13px; }
.grid { display: grid; grid-template-columns: 1fr 320px; gap: 16px; align-items: start; }
.col { display: grid; gap: 16px; }
.line { display: grid; grid-template-columns: 48px 1fr auto 110px; gap: 14px; align-items: center; padding: 10px 0; }
.line + .line { border-top: 1px solid var(--border); }
.thumb { width: 48px; height: 48px; border-radius: 8px; background: var(--surface-muted); display: flex; align-items: center; justify-content: center; overflow: hidden; }
.thumb img { width: 100%; height: 100%; object-fit: cover; }
.thumb img.ph { width: 60%; height: auto; opacity: .5; }
.line-name small { display: block; color: var(--ink-muted); font-size: 12px; }
.amt { text-align: right; font-variant-numeric: tabular-nums; }
.row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 14px; font-variant-numeric: tabular-nums; }
.row.discount { color: var(--brand-700); }
.trk-status { display: inline-flex; align-items: center; gap: 6px; }
.trk-events { list-style: none; padding: 10px 0 0; margin: 8px 0 0; border-top: 1px solid var(--border); display: grid; gap: 8px; font-size: 13px; }
.trk-events li { display: grid; grid-template-columns: 120px 1fr; gap: 8px; line-height: 1.5; }
.trk-time { color: var(--ink-muted); font-size: 12px; }
.trk-link { display: inline-block; margin-top: 10px; font-size: 13px; color: var(--brand-700); text-decoration: none; }
.row.total { font-weight: 600; font-size: 15px; border-top: 1px solid var(--border); margin-top: 6px; padding-top: 10px; }
.muted { color: var(--ink-muted); font-size: 13px; }
.strong { font-weight: 600; }
.addr { line-height: 1.7; font-size: 14px; }
.mono { font-family: ui-monospace, monospace; font-size: 12px; }
.mail { padding: 8px 0; }
.refunded { color: var(--brand-700) !important; }
.refund { padding: 6px 0; }
.refund + .refund { border-top: 1px solid var(--border); }
.comment { display: flex; gap: 8px; margin-bottom: 8px; }
.event { display: grid; grid-template-columns: 14px 1fr; gap: 10px; padding: 8px 0; font-size: 14px; }
.event .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--brand-500); margin: 7px 0 0 3px; }
.event.comment .dot { background: transparent; border: 1.5px solid var(--ink-muted); }
.event.cancelled .dot { background: var(--ink-muted); }
.event-msg { line-height: 1.5; word-break: break-word; }
.mail + .mail { border-top: 1px solid var(--border); }
.mail-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 14px; }
.mail-error { font-size: 12px; color: var(--danger); margin-top: 2px; word-break: break-word; }
.order-page { container-type: inline-size; }
.col { container-type: inline-size; }
@container (max-width: 820px) { .grid { grid-template-columns: 1fr; } }
@container (max-width: 520px) { .line { grid-template-columns: 48px 1fr auto; } .line .muted { display: none; } }
.risk-head { display: flex; align-items: center; gap: 8px; }
.risk-list { margin: 8px 0 0; padding-left: 18px; font-size: 13px; line-height: 1.8; }
.risk-actions { display: flex; gap: 4px; margin-top: 6px; }
.pre { color: var(--brand-700, #0A7350); font-weight: 600; }
.dl { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; padding: 4px 0; }
.dl-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
