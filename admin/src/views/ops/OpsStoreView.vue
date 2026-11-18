<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  NCard, NButton, NTag, NSpin, NIcon, NModal, NForm, NFormItem, NInput, NInputNumber, NSelect, NDatePicker, useMessage,
} from 'naive-ui'
import { ArrowBackOutline } from '@vicons/ionicons5'
import { ops, stateLabel } from '@/api/ops'
import { errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Detail {
  id: number; name: string; handle: string; owner: { name: string; email: string } | null; currency: string
  state: string; plan_name: string | null; interval: string | null; provider: string | null
  trial_ends_at: string | null; current_period_end: string | null; gmv_30d: string; storefront_url: string; created_at: string
  suspended_reason: string | null
  subscription: { plan: string; status: string; provider: string; note: string | null; stripe_subscription_id: string | null; cancel_at_period_end: boolean } | null
  limits: { staff: number; custom_domains: boolean; fee_bp: number }
  members: number; products: number; orders: number; gmv_total: string; fees_pending_usd: string | null
  domains: { host: string; type: string; is_primary: boolean; verified_at: string | null }[]
  invoices: { id: number; number: string; description: string; amount: string; status: string; created_at: string }[]
  plans: { key: string; name: string }[]
}

const route = useRoute()
const router = useRouter()
const message = useMessage()
const d = ref<Detail | null>(null)
const busy = ref(false)
const modal = ref<'trial' | 'grant' | 'suspend' | null>(null)
const form = reactive({ days: 7, plan: 'basic', until: null as number | null, note: '', reason: '' })

async function load() {
  d.value = (await ops.get(`/stores/${route.params.id}`)).data
}

async function act(path: string, body: object = {}, ok = t('已更新')) {
  busy.value = true
  try {
    d.value = (await ops.post(`/stores/${route.params.id}/${path}`, body)).data
    message.success(ok)
    modal.value = null
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

function submit() {
  if (modal.value === 'trial') return act('extend-trial', { days: form.days }, t('试用已延长 {0} 天', [form.days]))
  if (modal.value === 'grant') {
    return act('grant', { plan: form.plan, note: form.note, until: form.until ? new Date(form.until).toISOString().slice(0, 10) : null }, t('已赠送套餐'))
  }
  return act('suspend', { reason: form.reason }, t('店铺已暂停'))
}

onMounted(load)
</script>

<template>
  <div class="page">
    <NButton text class="back" @click="router.push({ name: 'ops-stores' })">
      <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
      {{ t('店铺') }}
    </NButton>
    <div v-if="!d" class="center"><NSpin /></div>
    <template v-else>
      <div class="head">
        <h1 class="page-title">{{ d.name }}</h1>
        <NTag :bordered="false" :type="stateLabel[d.state]?.type">{{ stateLabel[d.state]?.label ?? d.state }}</NTag>
        <div class="actions">
          <a :href="d.storefront_url" target="_blank" rel="noopener"><NButton>{{ t('打开店铺') }}</NButton></a>
          <NButton @click="modal = 'trial'">{{ t('延长试用') }}</NButton>
          <NButton @click="modal = 'grant'">{{ t('赠送套餐') }}</NButton>
          <NButton v-if="d.state !== 'suspended'" @click="modal = 'suspend'">{{ t('暂停店铺') }}</NButton>
          <NButton v-else type="primary" :loading="busy" @click="act('unsuspend', {}, t('已恢复店铺'))">{{ t('恢复店铺') }}</NButton>
        </div>
      </div>

      <div class="grid">
        <NCard :title="t('套餐')" size="small">
          <div class="row"><span>{{ t('状态') }}</span><span>{{ stateLabel[d.state]?.label }}</span></div>
          <div class="row"><span>{{ t('套餐') }}</span><span>{{ d.plan_name ?? '—' }}<template v-if="d.provider"> · {{ { stripe: 'Stripe', test: t('测试'), manual: t('平台赠送') }[d.provider] }}</template></span></div>
          <div class="row"><span>{{ t('试用到期') }}</span><span>{{ formatDate(d.trial_ends_at) }}</span></div>
          <div class="row"><span>{{ t('本期到期') }}</span><span>{{ formatDate(d.current_period_end) }}{{ d.subscription?.cancel_at_period_end ? t('（不续订）') : '' }}</span></div>
          <div v-if="d.subscription?.note" class="row"><span>{{ t('备注') }}</span><span>{{ d.subscription.note }}</span></div>
          <div v-if="d.suspended_reason" class="row"><span>{{ t('暂停原因') }}</span><span>{{ d.suspended_reason }}</span></div>
          <div v-if="d.subscription?.stripe_subscription_id" class="row"><span>Stripe</span><span class="mono">{{ d.subscription.stripe_subscription_id }}</span></div>
        </NCard>
        <NCard :title="t('店铺')" size="small">
          <div class="row"><span>{{ t('店主') }}</span><span>{{ d.owner?.name }} · {{ d.owner?.email }}</span></div>
          <div class="row"><span>{{ t('成员 / 上限') }}</span><span>{{ d.members }} / {{ d.limits.staff }}</span></div>
          <div class="row"><span>{{ t('商品 / 订单') }}</span><span>{{ d.products }} / {{ d.orders }}</span></div>
          <div class="row"><span>{{ t('累计成交（净）') }}</span><span>{{ d.currency }} {{ d.gmv_total }}</span></div>
          <div class="row"><span>{{ t('近 30 天成交') }}</span><span>{{ d.currency }} {{ d.gmv_30d }}</span></div>
          <div class="row"><span>{{ t('佣金费率 / 待结算') }}</span><span>{{ d.limits.fee_bp / 100 }}% · {{ d.fees_pending_usd !== null ? `$${d.fees_pending_usd}` : '—' }}</span></div>
          <div class="row"><span>{{ t('开店时间') }}</span><span>{{ formatDate(d.created_at) }}</span></div>
        </NCard>
      </div>

      <NCard :title="t('域名')" size="small">
        <div v-for="dm in d.domains" :key="dm.host" class="row">
          <span>{{ dm.host }}<NTag v-if="dm.is_primary" size="tiny" :bordered="false" style="margin-left: 6px">{{ t('主域名') }}</NTag></span>
          <span class="muted">{{ dm.type === 'platform' ? t('平台域名') : dm.verified_at ? t('已验证') : t('待验证') }}</span>
        </div>
      </NCard>

      <NCard :title="t('账单')" size="small">
        <p v-if="!d.invoices.length" class="muted" style="margin: 0">{{ t('没有账单') }}</p>
        <div v-for="i in d.invoices" :key="i.id" class="row">
          <span><span class="mono">{{ i.number }}</span> · {{ i.description }}</span>
          <span>${{ i.amount }} · {{ i.status === 'paid' ? t('已支付') : i.status === 'open' ? t('待支付') : t('作废') }} · {{ formatDate(i.created_at) }}</span>
        </div>
      </NCard>
    </template>

    <NModal :show="modal !== null" preset="card" style="width: min(460px, 94vw)"
      :title="modal === 'trial' ? t('延长试用') : modal === 'grant' ? t('赠送套餐') : t('暂停店铺')" @update:show="(v: boolean) => !v && (modal = null)">
      <NForm label-placement="top" @submit.prevent="submit">
        <template v-if="modal === 'trial'">
          <NFormItem :label="t('延长天数（从试用到期日起算；已到期则从今天起算）')"><NInputNumber v-model:value="form.days" :min="1" :max="90" style="width: 100%" /></NFormItem>
        </template>
        <template v-else-if="modal === 'grant'">
          <NFormItem :label="t('套餐')"><NSelect v-model:value="form.plan" :options="(d?.plans ?? []).map((p) => ({ value: p.key, label: p.name }))" /></NFormItem>
          <NFormItem :label="t('有效期至（不选 = 长期有效）')"><NDatePicker v-model:value="form.until" type="date" clearable style="width: 100%" /></NFormItem>
          <NFormItem :label="t('原因（必填，会记录操作人）')"><NInput v-model:value="form.note" :placeholder="t('例如：合作伙伴、补偿故障')" maxlength="200" /></NFormItem>
        </template>
        <template v-else>
          <NFormItem :label="t('暂停原因（商家在后台能看到）')"><NInput v-model:value="form.reason" type="textarea" maxlength="255" /></NFormItem>
          <p class="muted">{{ t('暂停后店铺前台关闭（买家仍可查看已有订单），商家后台只能进入账单页。') }}</p>
        </template>
        <div class="modal-foot">
          <NButton @click="modal = null">{{ t('取消') }}</NButton>
          <NButton :type="modal === 'suspend' ? 'error' : 'primary'" attr-type="submit" :loading="busy">{{ t('确认') }}</NButton>
        </div>
      </NForm>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; max-width: 1000px; }
.back { color: var(--ink-muted); justify-self: start; }
.center { display: flex; justify-content: center; padding: 48px; }
.head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.head .page-title { margin: 0; }
.actions { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
.actions a { text-decoration: none; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.row { display: flex; justify-content: space-between; gap: 16px; padding: 6px 0; font-size: 14px; border-top: 1px solid var(--border); }
.row:first-child { border-top: 0; }
.row > span:last-child { text-align: right; }
.muted { color: var(--ink-muted); font-size: 13px; }
.mono { font-family: ui-monospace, monospace; font-size: 12px; }
.modal-foot { display: flex; justify-content: flex-end; gap: 8px; }
@media (max-width: 860px) { .grid { grid-template-columns: 1fr; } }
</style>
