<script setup lang="ts">
/** Developers: marketplace earnings (apps + themes), payout account, and payout history. */
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NButton, NCard, NEmpty, NInput, NRadio, NRadioGroup, NSpin, NTag, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Payout { id: number; period: string; amount: string; status: 'pending' | 'paid'; method: string | null; reference: string | null; paid_at: string | null }

const router = useRouter()
const message = useMessage()
const loading = ref(true)
const saving = ref(false)
const balance = reactive({ pending: '0.00', available: '0.00', paid: '0.00' })
const minimum = ref('25.00')
const current = reactive<{ method: string | null; account: string | null }>({ method: null, account: null })
const form = reactive({ method: 'paypal' as 'paypal' | 'bank', account: '' })
const payouts = ref<Payout[]>([])

function apply(d: { balance: typeof balance; minimum: string; details: typeof current; payouts: Payout[] }) {
  Object.assign(balance, d.balance)
  minimum.value = d.minimum
  Object.assign(current, d.details)
  if (d.details.method) form.method = d.details.method as 'paypal' | 'bank'
  payouts.value = d.payouts
}

async function load() {
  try {
    apply((await http.get('/partner/payouts')).data)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    apply((await http.put('/partner/payouts/details', form)).data)
    form.account = ''
    message.success(t('收款账户已保存'))
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
    <div class="head">
      <div>
        <div class="eyebrow">Partners</div>
        <h1 class="page-title">{{ t('收入与结算') }}</h1>
        <p class="page-sub">{{ t('应用收费和付费主题的收入，你获得 80%。商家付清平台账单后，收入会在每月 2 日汇总结算；不足 US${0} 的部分顺延到下个月。', [minimum]) }}</p>
      </div>
      <div class="btns">
        <NButton quaternary @click="router.push({ name: 'partner-apps' })">{{ t('我的应用') }}</NButton>
        <NButton quaternary @click="router.push({ name: 'partner-themes' })">{{ t('我的主题') }}</NButton>
      </div>
    </div>

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else>
      <div class="stats">
        <NCard size="small"><div class="muted">{{ t('等待商家付款') }}</div><div class="big">US${{ balance.pending }}</div></NCard>
        <NCard size="small"><div class="muted">{{ t('待结算') }}</div><div class="big">US${{ balance.available }}</div></NCard>
        <NCard size="small"><div class="muted">{{ t('已到账') }}</div><div class="big">US${{ balance.paid }}</div></NCard>
      </div>

      <NCard size="small" :title="t('收款账户')">
        <p v-if="current.method" class="muted">{{ t('当前：{0} · {1}', [current.method === 'paypal' ? 'PayPal' : t('银行转账'), current.account]) }}</p>
        <p v-else class="muted">{{ t('还没有设置收款账户，结算会等你设置好后再支付。') }}</p>
        <NRadioGroup v-model:value="form.method"><NRadio value="paypal">PayPal</NRadio><NRadio value="bank">{{ t('银行转账') }}</NRadio></NRadioGroup>
        <NInput v-model:value="form.account" :type="form.method === 'bank' ? 'textarea' : 'text'" :autosize="{ minRows: 2, maxRows: 5 }" style="margin-top: 10px"
          :placeholder="form.method === 'paypal' ? t('PayPal 账户邮箱') : t('户名、银行名称、账号、SWIFT/BIC 等')" />
        <p class="muted">{{ t('账户信息加密保存，只有平台财务能看到完整内容。') }}</p>
        <div class="actions"><NButton type="primary" :loading="saving" :disabled="!form.account.trim()" @click="save">{{ t('保存') }}</NButton></div>
      </NCard>

      <NCard size="small" :title="t('结算记录')">
        <div v-for="p in payouts" :key="p.id" class="row">
          <span class="period">{{ p.period }}</span>
          <strong>US${{ p.amount }}</strong>
          <NTag size="small" :bordered="false" :type="p.status === 'paid' ? 'success' : 'warning'">{{ p.status === 'paid' ? t('已支付') : t('处理中') }}</NTag>
          <span class="muted grow">{{ p.paid_at ? `${formatDate(p.paid_at)} · ${p.reference}` : '' }}</span>
        </div>
        <NEmpty v-if="!payouts.length" :description="t('还没有结算记录')" style="padding: 16px 0" />
      </NCard>
    </template>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; max-width: 640px; }
.btns { display: flex; gap: 6px; }
.center { display: flex; justify-content: center; padding: 48px; }
.stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.big { font-size: 24px; font-weight: 600; margin-top: 4px; }
.muted { color: var(--ink-muted); font-size: 12px; line-height: 1.7; }
.actions { display: flex; justify-content: flex-end; }
.row { display: flex; align-items: center; gap: 12px; padding: 8px 0; border-top: 1px solid var(--border); }
.period { width: 70px; }
.grow { flex: 1; text-align: right; }
@media (max-width: 640px) { .stats { grid-template-columns: 1fr; } }
</style>
