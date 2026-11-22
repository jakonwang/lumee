import { t, dateLocale } from '@/i18n'
export type FinancialStatus = 'pending' | 'paid' | 'partially_refunded' | 'refunded' | 'voided'
export type FulfillmentStatus = 'unfulfilled' | 'fulfilled'

export interface OrderItem {
  id: number
  title: string
  variant_title: string | null
  sku: string | null
  image: string | null
  price: string
  quantity: number
  refunded_quantity: number
  total: string
  preorder?: boolean
  gift?: { email: string; name?: string; message?: string } | null
}

export interface OrderEvent {
  id: number
  type: string
  message: string
  user: string | null
  created_at: string
}

export interface OrderRefund {
  id: number
  amount: string
  shipping_amount: string
  reason: string | null
  restock: boolean
  reference: string | null
  user: string | null
  created_at: string
}

export interface Order {
  id: number
  name: string
  number: number
  email: string
  customer_name: string
  currency: string
  subtotal: string
  balance_due?: string
  edits_count?: number
  edit_blocker?: string | null
  shipping_total: string
  discount_code: string | null
  discount_total: string
  discount_lines: { type: 'code' | 'promotion'; id: number | null; title: string; amount: string }[]
  tax_total: string
  gift_card_total?: string
  duty_total?: string
  payment_fee?: string
  payment_due_at?: string | null
  po_number?: string | null
  company?: { id: number; name: string } | null
  source?: string
  taxes_included: boolean
  tax_lines: { name: string; rate: number; amount: number }[]
  total: string
  refunded_total: string
  net_total: string
  refundable: string
  cancelled_at: string | null
  cancel_reason: string | null
  cancel_reason_label: string | null
  financial_status: FinancialStatus
  dispute: {
    id: string
    status: string | null
    reason: string | null
    reason_label?: string | null
    amount: number
    currency: string
    due_by: string | null
    closed_at?: string
    reversed_amount?: number
  } | null
  fulfillment_status: FulfillmentStatus
  risk_level?: 'low' | 'medium' | 'high' | null
  risk_score?: number | null
  risk_hold?: boolean
  risk_reasons?: { code: string; points: number; label: string }[]
  client_ip?: string | null
  client_country?: string | null
  payment_gateway: string
  payment_reference: string | null
  shipping_address: Record<string, string | null>
  shipping_method: { name: string; type?: 'shipping' | 'pickup' | 'digital'; pickup?: { name: string; address: string; hours?: string | null; instructions?: string | null } } | null
  downloads?: { filename: string; url: string; left: number; downloads: number; expires_at: string | null; usable: boolean }[]
  tracking_company: string | null
  tracking_number: string | null
  tracking_status: string | null
  tracking_carrier: string | null
  tracking_events: { time: string | null; description: string; location: string | null }[]
  tracking_synced_at: string | null
  tracking_enabled: boolean
  delivered_at: string | null
  note: string | null
  items_count?: number
  items?: OrderItem[]
  notifications?: OrderNotification[]
  events?: OrderEvent[]
  refunds?: OrderRefund[]
  refund_info?: { shipping_refundable: string; refunds_online: boolean }
  paid_at: string | null
  fulfilled_at: string | null
  created_at: string
}

export interface OrderNotification {
  id: number
  type: string
  recipient: string
  status: 'queued' | 'sent' | 'failed' | 'skipped'
  error: string | null
  sent_at: string | null
  created_at: string
}

export const notificationLabel: Record<string, string> = {
  order_confirmation: t('订单确认'),
  payment_received: t('收款确认'),
  shipping_confirmation: t('发货通知'),
  merchant_new_order: t('新订单提醒'),
  order_cancelled: t('订单取消'),
  refund_notification: t('退款通知'),
}

export const cancelReasons: { value: string; label: string }[] = [
  { value: 'customer', label: t('买家要求取消') },
  { value: 'inventory', label: t('库存不足') },
  { value: 'fraud', label: t('疑似欺诈订单') },
  { value: 'declined', label: t('付款失败') },
  { value: 'other', label: t('其他原因') },
]

type TagType = 'default' | 'success' | 'warning' | 'error' | 'info'

export const notificationStatus: Record<OrderNotification['status'], { label: string; type: TagType }> = {
  queued: { label: t('排队中'), type: 'default' },
  sent: { label: t('已发送'), type: 'success' },
  failed: { label: t('发送失败'), type: 'error' },
  skipped: { label: t('已跳过'), type: 'default' },
}

export const financialLabel: Record<FinancialStatus, { label: string; type: TagType }> = {
  pending: { label: t('待付款'), type: 'warning' },
  paid: { label: t('已付款'), type: 'success' },
  partially_refunded: { label: t('部分退款'), type: 'warning' },
  refunded: { label: t('已退款'), type: 'default' },
  voided: { label: t('已作废'), type: 'default' },
}

export const fulfillmentLabel: Record<FulfillmentStatus, { label: string; type: TagType }> = {
  unfulfilled: { label: t('未发货'), type: 'info' },
  fulfilled: { label: t('已发货'), type: 'success' },
}

/** 17TRACK status → label + tag colour */
export const trackingStatus: Record<string, { label: string; type: 'default' | 'info' | 'success' | 'warning' | 'error' }> = {
  NotFound: { label: t('等待承运商更新'), type: 'default' },
  InfoReceived: { label: t('已揽收信息'), type: 'info' },
  InTransit: { label: t('运输中'), type: 'info' },
  AvailableForPickup: { label: t('待自提'), type: 'warning' },
  OutForDelivery: { label: t('派送中'), type: 'info' },
  DeliveryFailure: { label: t('派送失败'), type: 'error' },
  Delivered: { label: t('已签收'), type: 'success' },
  Exception: { label: t('物流异常'), type: 'error' },
  Expired: { label: t('长时间无更新'), type: 'warning' },
}

export const gatewayLabel: Record<string, string> = {
  stripe: t('Stripe 信用卡'),
  paypal: 'PayPal',
  airwallex: 'Airwallex',
  manual: t('银行转账'),
  cod: t('货到付款'),
  test: t('测试支付'),
}

export function formatDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString(dateLocale, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
}
