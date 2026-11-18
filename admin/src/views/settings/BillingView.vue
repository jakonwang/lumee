<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NCard, NButton, NSpin, NAlert, NTag, NRadioGroup, NRadioButton, useMessage, useDialog } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { useAuth, type StoreBilling } from '@/stores/auth'
import SettingsNav from '@/components/SettingsNav.vue'
import { t } from '@/i18n'

interface Plan {
  key: string; name: string; tagline: string; month: string; year: string; year_monthly: string
  fee_rate: number; staff: number; custom_domains: boolean; features: string[]
}
interface Invoice {
  id: number; number: string; kind: 'subscription' | 'fees'; description: string; amount: string; currency: string
  status: 'paid' | 'open' | 'void'; paid_at: string | null; created_at: string
}
interface Billing extends StoreBilling {
  mode: 'stripe' | 'test' | 'unavailable'
  is_owner: boolean
  suspended_reason: string | null
  subscription: {
    plan: string; plan_name: string; interval: 'month' | 'year'; status: string; provider: 'stripe' | 'test' | 'manual'
    current_period_end: string | null; cancel_at_period_end: boolean; note: string | null
  } | null
  limits: { plan: string | null; staff: number; custom_domains: boolean; fee_bp: number }
  usage: { staff: number }
  fees: { rate: number; pending_usd: string | null }
  plans: Plan[]
  invoices: Invoice[]
}

const route = useRoute()
const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const auth = useAuth()
const b = ref<Billing | null>(null)
const loading = ref(true)
const busy = ref<string | null>(null)
const interval = ref<'month' | 'year'>('year')

const live = computed(() => b.value?.subscription && b.value.subscription.status !== 'canceled' && ['active', 'past_due'].includes(b.value.state))
const currentKey = computed(() => (live.value ? b.value!.subscription!.plan : null))
const order = ['starter', 'basic', 'pro']

async function load() {
  try {
    b.value = (await http.get('/billing')).data
    if (live.value) interval.value = b.value!.subscription!.interval
  } finally {
    loading.value = false
  }
}

function apply(data: Billing) {
  b.value = data
  // Refresh the store list so banners / menu reflect the new state
  auth.fetchMe().catch(() => {})
}

function buttonFor(p: Plan) {
  if (currentKey.value === p.key && b.value!.subscription!.interval === interval.value) {
    return b.value!.subscription!.cancel_at_period_end ? { label: t('恢复订阅'), kind: 'resume' } : { label: t('当前套餐'), kind: 'current' }
  }
  if (!currentKey.value) return { label: b.value!.state === 'trialing' ? t('选择此套餐') : t('订阅'), kind: 'subscribe' }
  return { label: order.indexOf(p.key) > order.indexOf(currentKey.value) ? t('升级') : currentKey.value === p.key ? t('切换付款周期') : t('降级'), kind: 'subscribe' }
}

async function choose(p: Plan) {
  const action = buttonFor(p)
  if (action.kind === 'current') return
  if (action.kind === 'resume') return setCancel(false)
  const price = interval.value === 'year' ? t('${0}/年', [p.year]) : t('${0}/月', [p.month])
  const trialNote = b.value!.state === 'trialing' && b.value!.mode === 'stripe' ? t('剩余试用期结束后才开始扣费。') : ''
  dialog.info({
    title: t('{0}「{1}」？', [currentKey.value ? t('更换为') : t('订阅'), p.name]),
    content: t('{0}，交易佣金 {1}%。{2}{3}', [price, p.fee_rate, trialNote, b.value!.mode === 'test' ? t('（开发环境测试模式：立即生效，不会真实扣款）') : '']),
    positiveText: b.value!.mode === 'stripe' && !live.value ? t('前往付款') : t('确认'),
    negativeText: t('取消'),
    onPositiveClick: () => subscribe(p.key),
  })
}

async function subscribe(plan: string) {
  busy.value = plan
  try {
    const { data } = await http.post('/billing/subscribe', { plan, interval: interval.value })
    if (data.redirect_url) {
      window.location.href = data.redirect_url   // Stripe hosted checkout
      return
    }
    apply(data)
    message.success(t('套餐已更新'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    busy.value = null
  }
}

function confirmCancel() {
  const end = formatDate(b.value!.subscription!.current_period_end)
  dialog.warning({
    title: t('取消订阅？'),
    content: t('店铺会正常营业到 {0}，之后前台暂停营业、后台只能进入本页。商品和订单数据会保留，随时可以重新订阅。', [end]),
    positiveText: t('确认取消'),
    negativeText: t('再想想'),
    onPositiveClick: () => setCancel(true),
  })
}

async function setCancel(cancel: boolean) {
  busy.value = 'cancel'
  try {
    apply((await http.post(cancel ? '/billing/cancel' : '/billing/resume')).data)
    message.success(cancel ? t('已取消续订') : t('已恢复订阅'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    busy.value = null
  }
}

onMounted(async () => {
  await load()
  // Back from Stripe Checkout: the webhook may land a moment later
  if (route.query.checkout === 'success') {
    message.info(t('付款成功，正在确认订阅…'))
    setTimeout(async () => { await load(); auth.fetchMe().catch(() => {}) }, 2500)
    router.replace({ query: {} })
  } else if (route.query.checkout === 'cancel') {
    message.warning(t('已取消付款，套餐没有变化'))
    router.replace({ query: {} })
  }
})
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Settings</div>
      <h1 class="page-title">{{ t('设置') }}</h1>
    </div>
    <SettingsNav />

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else-if="b">
      <!-- Status -->
      <NCard size="small">
        <div class="status">
          <div>
            <template v-if="b.state === 'trialing'">
              <div class="status-title">{{ t('免费试用中') }} <NTag size="small" :bordered="false" type="success">{{ t('剩余 {0} 天', [b.trial_days_left]) }}</NTag></div>
              <div class="muted">{{ t('试用到 {0} 结束。试用期间可以使用全部功能，不收交易佣金。到期前选择套餐，店铺就不会中断。', [formatDate(b.trial_ends_at)]) }}</div>
            </template>
            <template v-else-if="live">
              <div class="status-title">
                {{ b.subscription!.plan_name }} · {{ b.subscription!.interval === 'year' ? t('年付') : t('月付') }}
                <NTag v-if="b.state === 'past_due'" size="small" :bordered="false" type="warning">{{ t('扣款失败') }}</NTag>
                <NTag v-else-if="b.subscription!.cancel_at_period_end" size="small" :bordered="false">{{ t('已取消续订') }}</NTag>
                <NTag v-else-if="b.subscription!.provider === 'manual'" size="small" :bordered="false" type="success">{{ t('平台赠送') }}</NTag>
              </div>
              <div class="muted">
                <template v-if="b.subscription!.provider === 'manual'">{{ b.subscription!.current_period_end ? t('有效期到 {0}', [formatDate(b.subscription!.current_period_end)]) : t('长期有效') }}</template>
                <template v-else-if="b.subscription!.cancel_at_period_end">{{ t('店铺营业到 {0}，之后暂停。', [formatDate(b.subscription!.current_period_end)]) }}</template>
                <template v-else>{{ t('下次续费：{0}', [formatDate(b.subscription!.current_period_end)]) }}</template>
              </div>
            </template>
            <template v-else-if="b.state === 'suspended'">
              <div class="status-title">{{ t('店铺已被平台暂停') }}</div>
              <div class="muted">{{ t('原因：{0}。请回复 Lumée 发给你的邮件或联系客服处理。', [b.suspended_reason || '—']) }}</div>
            </template>
            <template v-else>
              <div class="status-title">{{ t('店铺已暂停营业') }}</div>
              <div class="muted">{{ t('试用已结束或套餐已到期。前台暂停营业，买家仍可以查看已有订单。选择套餐后立即恢复，所有数据都还在。') }}</div>
            </template>
          </div>
          <NButton v-if="live && b.is_owner && b.subscription!.provider !== 'manual' && !b.subscription!.cancel_at_period_end"
            quaternary :loading="busy === 'cancel'" @click="confirmCancel">{{ t('取消订阅') }}</NButton>
        </div>
        <NAlert v-if="b.state === 'past_due'" type="warning" :bordered="false" style="margin-top: 12px">
          {{ t('续费扣款失败，系统会自动重试。请在 Stripe 付款邮件中更新银行卡，否则宽限期结束后店铺会暂停营业。') }}
        </NAlert>
      </NCard>

      <NAlert v-if="b.mode === 'unavailable'" type="info" :bordered="false">{{ t('平台暂未开通在线订阅（未配置 Stripe），如需开通套餐请联系 Lumée 客服。') }}</NAlert>
      <NAlert v-if="!b.is_owner" type="default" :bordered="false">{{ t('只有店铺所有者可以更改套餐。') }}</NAlert>

      <!-- Plans -->
      <div v-if="b.state !== 'suspended'" class="plans-head">
        <h2>{{ t('选择套餐') }}</h2>
        <NRadioGroup v-model:value="interval" size="small">
          <NRadioButton value="month">{{ t('月付') }}</NRadioButton>
          <NRadioButton value="year">{{ t('年付（约省 17%）') }}</NRadioButton>
        </NRadioGroup>
      </div>
      <div v-if="b.state !== 'suspended'" class="plans">
        <div v-for="p in b.plans" :key="p.key" class="plan" :class="{ current: currentKey === p.key, featured: p.key === 'basic' }">
          <div class="plan-top">
            <div class="plan-name">{{ p.name }}</div>
            <NTag v-if="p.key === 'basic'" size="tiny" :bordered="false" type="success">{{ t('最受欢迎') }}</NTag>
          </div>
          <div class="muted">{{ p.tagline }}</div>
          <div class="price">
            <span class="amount">${{ interval === 'year' ? p.year_monthly : p.month }}</span><span class="per"> {{ t('/ 月') }}</span>
          </div>
          <div class="muted small">{{ interval === 'year' ? t('按年付 ${0}', [p.year]) : t('按月付，随时取消') }}</div>
          <ul>
            <li v-for="f in p.features" :key="f">{{ f }}</li>
          </ul>
          <NButton block :type="buttonFor(p).kind === 'current' ? 'default' : 'primary'" :secondary="buttonFor(p).kind !== 'current' && p.key !== 'basic'"
            :disabled="!b.is_owner || buttonFor(p).kind === 'current' || b.mode === 'unavailable'" :loading="busy === p.key" @click="choose(p)">
            {{ buttonFor(p).label }}
          </NButton>
        </div>
      </div>

      <!-- Usage & fees -->
      <div class="grid2">
        <NCard :title="t('用量')" size="small">
          <div class="row"><span>{{ t('店铺成员') }}</span><span>{{ b.usage.staff }} / {{ b.limits.staff }}</span></div>
          <div class="row"><span>{{ t('自有域名') }}</span><span>{{ b.limits.custom_domains ? t('可以绑定') : t('不支持（升级到基础版）') }}</span></div>
        </NCard>
        <NCard :title="t('交易佣金')" size="small">
          <div class="row"><span>{{ t('当前费率') }}</span><span>{{ b.fees.rate }}%</span></div>
          <div class="row"><span>{{ t('本期待结算') }}</span><span>{{ b.fees.pending_usd !== null ? `$${b.fees.pending_usd}` : '—' }}</span></div>
          <p class="muted small" style="margin: 8px 0 0">{{ t('按订单实收金额（扣除退款后）计算，每月 1 日合并结算{0}。', [b.mode === 'stripe' ? t('，随下一期套餐费一起扣款') : '']) }}</p>
        </NCard>
      </div>

      <!-- Invoices -->
      <NCard :title="t('账单记录')" size="small" content-style="padding: 0">
        <table v-if="b.invoices.length" class="tbl">
          <thead><tr><th>{{ t('编号') }}</th><th>{{ t('内容') }}</th><th>{{ t('日期') }}</th><th class="r">{{ t('金额') }}</th><th>{{ t('状态') }}</th></tr></thead>
          <tbody>
            <tr v-for="i in b.invoices" :key="i.id">
              <td class="mono">{{ i.number }}</td>
              <td>{{ i.description }}</td>
              <td>{{ formatDate(i.created_at) }}</td>
              <td class="r">${{ i.amount }}</td>
              <td><NTag size="small" :bordered="false" :type="i.status === 'paid' ? 'success' : i.status === 'open' ? 'warning' : 'default'">
                {{ i.status === 'paid' ? t('已支付') : i.status === 'open' ? t('待支付') : t('已作废') }}</NTag></td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted empty">{{ t('还没有账单') }}</p>
      </NCard>
    </template>
  </div>
</template>

<style scoped>
.page { max-width: 960px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; }
.small { font-size: 12px; }
.status { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.status-title { font-weight: 600; font-size: 16px; margin-bottom: 4px; display: flex; gap: 8px; align-items: center; }
.plans-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 4px; }
.plans-head h2 { font-size: 16px; margin: 0; }
.plans { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.plan { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; display: flex; flex-direction: column; gap: 4px; }
.plan.featured { border-color: var(--brand-300); }
.plan.current { border-color: var(--brand-500); box-shadow: 0 0 0 1px var(--brand-500); }
.plan-top { display: flex; justify-content: space-between; align-items: center; }
.plan-name { font-weight: 600; font-size: 16px; }
.price { margin-top: 10px; }
.amount { font-size: 30px; font-weight: 700; font-variant-numeric: tabular-nums; }
.per { color: var(--ink-muted); font-size: 13px; }
.plan ul { margin: 12px 0 16px; padding-left: 18px; font-size: 13px; line-height: 1.9; flex: 1; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 14px; font-variant-numeric: tabular-nums; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { text-align: left; font-weight: 500; color: var(--ink-muted); padding: 10px 12px; background: var(--surface-muted); }
.tbl td { padding: 10px 12px; border-top: 1px solid var(--border); }
.r { text-align: right; font-variant-numeric: tabular-nums; }
.mono { font-family: ui-monospace, monospace; font-size: 12px; }
.empty { padding: 20px; margin: 0; }
@media (max-width: 860px) { .plans, .grid2 { grid-template-columns: 1fr; } }
</style>
