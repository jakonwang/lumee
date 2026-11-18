<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NCard, NForm, NFormItem, NInput, NSwitch, NButton, NSpin, NTag, NAlert, NSelect, NCheckbox, NCheckboxGroup, useMessage, useDialog } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import SettingsNav from '@/components/SettingsNav.vue'
import { t } from '@/i18n'

const message = useMessage()
const dialog = useDialog()
const route = useRoute()
const router = useRouter()

interface Connect {
  enabled: boolean
  status: 'not_connected' | 'onboarding' | 'pending' | 'restricted' | 'active'
  account_id: string | null
  country: string | null
  email: string | null
  default_currency: string | null
  charges_enabled: boolean
  payouts_enabled: boolean
  currently_due: number
  countries: { code: string; name: string }[]
}
const connect = ref<Connect | null>(null)
const connectCountry = ref<string | null>(null)
const connectBusy = ref(false)
const countryOptions = computed(() => (connect.value?.countries ?? []).map((c) => ({ value: c.code, label: `${c.name} (${c.code})` })))
const statusTags = {
  not_connected: { label: t('未连接'), type: 'default' as const },
  onboarding: { label: t('资料未完成'), type: 'warning' as const },
  pending: { label: t('审核中'), type: 'info' as const },
  restricted: { label: t('需要补充资料'), type: 'warning' as const },
  active: { label: t('已开通'), type: 'success' as const },
}
const statusTag = computed(() => statusTags[connect.value?.status ?? 'not_connected'])
const loading = ref(true)
const saving = ref(false)
const available = reactive({ stripe: false, paypal: false, airwallex: false, razorpay: false, manual: true, test: false })
const stripeMethods = ref<{ key: string; name: string; currencies: string[]; supported: boolean }[]>([])
const paypalCurrencyOk = ref(true)
const razorpayCurrencyOk = ref(true)
const currency = ref('')
const form = reactive({
  stripe: { enabled: true, methods: [] as string[] },
  paypal: { enabled: true },
  airwallex: { enabled: true },
  razorpay: { enabled: true },
  manual: { enabled: true, label: 'Bank transfer', instructions: '' },
  test: { enabled: true },
})

interface Payload {
  settings: typeof form
  available: typeof available
  paypal_currency_ok: boolean
  razorpay_currency_ok?: boolean
  stripe_methods: { key: string; name: string; currencies: string[]; supported: boolean }[]
  currency: string
  stripe_connect: Connect
}

function apply(data: Payload) {
  Object.assign(available, data.available)
  form.stripe.enabled = data.settings.stripe.enabled
  form.stripe.methods = [...(data.settings.stripe.methods ?? [])]
  form.paypal.enabled = data.settings.paypal.enabled
  form.airwallex.enabled = data.settings.airwallex.enabled
  form.razorpay.enabled = data.settings.razorpay?.enabled ?? true
  razorpayCurrencyOk.value = data.razorpay_currency_ok ?? false
  stripeMethods.value = data.stripe_methods
  paypalCurrencyOk.value = data.paypal_currency_ok
  currency.value = data.currency
  connect.value = data.stripe_connect
  Object.assign(form.manual, data.settings.manual)
  form.test.enabled = data.settings.test.enabled
}

async function load() {
  loading.value = true
  try {
    apply((await http.get('/settings/payments')).data)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    apply((await http.put('/settings/payments', form)).data)
    message.success(t('收款设置已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

/** Send the merchant to Stripe's hosted onboarding (creates the account the first time). */
async function startConnect() {
  if (connect.value?.status === 'not_connected' && !connectCountry.value) {
    message.error(t('请选择公司或个人所在的国家/地区'))
    return
  }
  connectBusy.value = true
  try {
    const { data } = await http.post('/settings/payments/stripe/connect', { country: connectCountry.value })
    window.location.href = data.url
  } catch (e) {
    message.error(errorMessage(e))
    connectBusy.value = false
  }
}

async function refreshConnect(silent = false) {
  connectBusy.value = true
  try {
    apply((await http.post('/settings/payments/stripe/refresh')).data)
    if (!silent) message.success(t('已更新 Stripe 账户状态'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    connectBusy.value = false
  }
}

async function openDashboard() {
  const win = window.open('', '_blank')
  try {
    const { data } = await http.post('/settings/payments/stripe/dashboard')
    if (win) win.location.href = data.url
    else window.location.href = data.url
  } catch (e) {
    win?.close()
    message.error(errorMessage(e))
  }
}

function disconnect() {
  dialog.warning({
    title: t('断开 Stripe 账户？'),
    content: t('断开后买家不能再用信用卡付款，已有订单的退款仍会从原账户退回。Stripe 账户本身和里面的余额不受影响，可以随时重新连接。'),
    positiveText: t('断开'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        apply((await http.delete('/settings/payments/stripe')).data)
        message.success(t('已断开'))
      } catch (e) {
        message.error(errorMessage(e))
      }
    },
  })
}

onMounted(async () => {
  await load()
  // Back from Stripe onboarding (?stripe=return), or the onboarding link expired (?stripe=refresh)
  const back = route.query.stripe
  if (back) {
    router.replace({ query: {} })
    if (!connect.value?.enabled) return
    if (back === 'return') await refreshConnect(true)
    else if (back === 'refresh') await startConnect()
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
    <NForm v-else label-placement="top" class="cards" @submit.prevent="save">
      <NCard size="small">
        <div class="gw">
          <div>
            <div class="gw-name">{{ t('信用卡 / 借记卡') }} <NTag size="tiny" :bordered="false">Stripe</NTag></div>
            <div class="muted">{{ t('买家跳转到 Stripe 安全页面付款，支持 Visa、Mastercard、Apple Pay、Google Pay。付款成功后订单自动变为已付款，退款也会原路退回。') }}</div>
          </div>
          <NSwitch v-model:value="form.stripe.enabled" :disabled="!available.stripe" />
        </div>
        <NAlert v-if="!available.stripe" type="info" :bordered="false" style="margin-top: 12px">
          {{ t('平台还没有配置 Stripe 密钥（服务器 .env 中的 STRIPE_SECRET 和 STRIPE_WEBHOOK_SECRET），配置后即可开启。') }}
        </NAlert>

        <div v-if="available.stripe && form.stripe.enabled" class="methods">
          <div class="connect-title">{{ t('同时提供的付款方式') }}</div>
          <NCheckboxGroup v-model:value="form.stripe.methods">
            <div class="method-grid">
              <NCheckbox v-for="m in stripeMethods" :key="m.key" :value="m.key" :disabled="!m.supported">
                {{ m.name }}<span v-if="!m.supported" class="muted small"> · {{ t('不支持 {0}', [currency]) }}</span>
              </NCheckbox>
            </div>
          </NCheckboxGroup>
          <p class="muted small">{{ t('买家在 Stripe 付款页可以选择这些方式，也需要在 Stripe 后台「Payment methods」里开启。没有开启的方式会被自动跳过，不影响信用卡付款。') }}</p>
        </div>

        <div v-if="available.stripe && connect?.enabled" class="connect">
          <div class="connect-head">
            <span class="connect-title">{{ t('收款账户') }}</span>
            <NTag size="small" :bordered="false" :type="statusTag.type">{{ statusTag.label }}</NTag>
          </div>

          <template v-if="connect.status === 'not_connected'">
            <p class="muted">{{ t('连接你自己的 Stripe 账户后，买家的信用卡付款会直接进入你的账户，由 Stripe 定期打款到你的银行卡。平台的交易佣金会在每笔付款时自动扣除。') }}</p>
            <p class="muted">{{ t('中国大陆的公司或个人不能直接开通 Stripe，可以用香港、新加坡、美国、英国等地注册的公司开通。') }}</p>
            <div class="connect-row">
              <NSelect v-model:value="connectCountry" :options="countryOptions" filterable :placeholder="t('公司或个人所在的国家/地区')" style="max-width: 320px" />
              <NButton type="primary" :loading="connectBusy" @click="startConnect">{{ t('连接 Stripe 账户') }}</NButton>
            </div>
          </template>

          <template v-else>
            <div class="facts">
              <div><span>{{ t('账户') }}</span><code>{{ connect.account_id }}</code></div>
              <div v-if="connect.email"><span>{{ t('邮箱') }}</span>{{ connect.email }}</div>
              <div v-if="connect.country"><span>{{ t('国家/地区') }}</span>{{ connect.country }}<template v-if="connect.default_currency"> · {{ connect.default_currency }}</template></div>
              <div><span>{{ t('收款') }}</span>{{ connect.charges_enabled ? t('已开通') : t('未开通') }}</div>
              <div><span>{{ t('打款到银行') }}</span>{{ connect.payouts_enabled ? t('已开通') : t('未开通') }}</div>
            </div>
            <NAlert v-if="connect.status === 'onboarding'" type="warning" :bordered="false">
              {{ t('Stripe 账户资料还没有填完，填完之后买家才能用信用卡付款。') }}
            </NAlert>
            <NAlert v-else-if="connect.status === 'pending'" type="info" :bordered="false">
              {{ t('资料已提交，Stripe 正在审核，通常几分钟内完成。审核通过后结账页会自动显示信用卡付款。') }}
            </NAlert>
            <NAlert v-else-if="connect.status === 'restricted'" type="warning" :bordered="false">
              {{ connect.currently_due ? t('Stripe 需要你补充 {0} 项资料，否则可能会暂停收款或打款。', [connect.currently_due]) : t('Stripe 暂时限制了这个账户，请补充资料或在 Stripe 后台查看原因。') }}
            </NAlert>
            <div class="connect-row">
              <NButton v-if="connect.status === 'onboarding' || connect.status === 'restricted'" type="primary" :loading="connectBusy" @click="startConnect">
                {{ connect.status === 'onboarding' ? t('继续填写资料') : t('补充资料') }}
              </NButton>
              <NButton v-if="connect.status !== 'onboarding'" secondary @click="openDashboard">{{ t('打开 Stripe 后台') }}</NButton>
              <NButton quaternary :loading="connectBusy" @click="refreshConnect()">{{ t('刷新状态') }}</NButton>
              <NButton quaternary type="error" @click="disconnect">{{ t('断开') }}</NButton>
            </div>
          </template>
        </div>
      </NCard>

      <NCard size="small">
        <div class="gw">
          <div>
            <div class="gw-name">PayPal</div>
            <div class="muted">{{ t('买家跳转到 PayPal 登录并确认付款（也可以用银行卡付款）。付款成功后订单自动变为已付款，退款也会原路退回。') }}</div>
          </div>
          <NSwitch v-model:value="form.paypal.enabled" :disabled="!available.paypal" />
        </div>
        <NAlert v-if="!available.paypal" type="info" :bordered="false" style="margin-top: 12px">
          {{ t('平台还没有配置 PayPal 密钥（服务器 .env 中的 PAYPAL_CLIENT_ID、PAYPAL_SECRET 和 PAYPAL_WEBHOOK_ID），配置后即可开启。') }}
        </NAlert>
        <NAlert v-else-if="form.paypal.enabled && !paypalCurrencyOk" type="warning" :bordered="false" style="margin-top: 12px">
          {{ t('PayPal 不支持店铺货币 {0}，结账页不会显示 PayPal。', [currency]) }}
        </NAlert>
      </NCard>

      <NCard size="small">
        <div class="gw">
          <div>
            <div class="gw-name">Airwallex <NTag size="tiny" :bordered="false">{{ t('空中云汇') }}</NTag></div>
            <div class="muted">{{ t('买家跳转到 Airwallex 安全页面，用银行卡、Apple Pay、Google Pay 或当地支付方式付款。资金可以多币种结算，适合跨境卖家。退款原路退回。') }}</div>
          </div>
          <NSwitch v-model:value="form.airwallex.enabled" :disabled="!available.airwallex" />
        </div>
        <NAlert v-if="!available.airwallex" type="info" :bordered="false" style="margin-top: 12px">
          {{ t('平台还没有配置 Airwallex 密钥（服务器 .env 中的 AIRWALLEX_CLIENT_ID、AIRWALLEX_API_KEY 和 AIRWALLEX_WEBHOOK_SECRET），配置后即可开启。') }}
        </NAlert>
      </NCard>

      <NCard size="small">
        <div class="gw">
          <div>
            <div class="gw-name">Razorpay <NTag size="tiny" :bordered="false">UPI</NTag></div>
            <div class="muted">{{ t('面向印度买家：跳转到 Razorpay 页面，用 UPI、银行卡、网银或钱包付款。只支持卢比（INR）店铺。退款原路退回。') }}</div>
          </div>
          <NSwitch v-model:value="form.razorpay.enabled" :disabled="!available.razorpay" />
        </div>
        <NAlert v-if="!available.razorpay" type="info" :bordered="false" style="margin-top: 12px">
          {{ t('平台还没有配置 Razorpay 密钥（服务器 .env 中的 RAZORPAY_KEY_ID 和 RAZORPAY_KEY_SECRET），配置后即可开启。') }}
        </NAlert>
        <NAlert v-else-if="form.razorpay.enabled && !razorpayCurrencyOk" type="warning" :bordered="false" style="margin-top: 12px">
          {{ t('Razorpay 只支持卢比（INR），店铺货币是 {0}，结账页不会显示 Razorpay。', [currency]) }}
        </NAlert>
      </NCard>

      <NCard size="small">
        <div class="gw">
          <div>
            <div class="gw-name">{{ t('线下付款') }}</div>
            <div class="muted">{{ t('例如银行转账、Wise、货到付款。买家下单后按你的说明付款，你确认收到钱后在订单页点击「标记为已付款」。') }}</div>
          </div>
          <NSwitch v-model:value="form.manual.enabled" />
        </div>
        <template v-if="form.manual.enabled">
          <NFormItem :label="t('付款方式名称（买家看到的）')" style="margin-top: 14px">
            <NInput v-model:value="form.manual.label" placeholder="Bank transfer" maxlength="60" />
          </NFormItem>
          <NFormItem :label="t('付款说明')">
            <NInput v-model:value="form.manual.instructions" type="textarea" :autosize="{ minRows: 4, maxRows: 10 }" maxlength="2000" show-count
              :placeholder="t('例如：\nBank: HSBC Hong Kong\nAccount name: Lumee Ltd\nAccount number: 123-456789-001\nSWIFT: HSBCHKHHHKH\nPlease use your order number as the payment reference.')" />
          </NFormItem>
          <p class="muted hint">{{ t('付款说明会显示在结账页、订单状态页和订单确认邮件里。建议用英文填写。') }}</p>
        </template>
      </NCard>

      <NCard v-if="available.test" size="small">
        <div class="gw">
          <div>
            <div class="gw-name">{{ t('测试支付') }} <NTag size="tiny" :bordered="false">{{ t('仅开发环境') }}</NTag></div>
            <div class="muted">{{ t('下单后立即标记为已付款，用来测试完整流程。正式上线时平台会关闭它。') }}</div>
          </div>
          <NSwitch v-model:value="form.test.enabled" />
        </div>
      </NCard>

      <div><NButton type="primary" attr-type="submit" :loading="saving">{{ t('保存') }}</NButton></div>
    </NForm>
  </div>
</template>

<style scoped>
.page { max-width: 760px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.cards { display: grid; gap: 16px; }
.gw { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.gw-name { font-weight: 600; font-size: 15px; margin-bottom: 4px; display: flex; gap: 8px; align-items: center; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; }
.hint { margin: -8px 0 0; }
.methods { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); display: grid; gap: 10px; }
.method-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px 12px; }
.small { font-size: 12px; }
@media (max-width: 640px) { .method-grid { grid-template-columns: 1fr 1fr; } }
.connect { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); display: grid; gap: 12px; }
.connect .muted { margin: 0; }
.connect-head { display: flex; align-items: center; gap: 8px; }
.connect-title { font-weight: 600; font-size: 14px; }
.connect-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.facts { display: grid; gap: 6px; font-size: 13px; }
.facts span { display: inline-block; min-width: 96px; color: var(--ink-muted); }
.facts code { font-size: 12px; }
</style>
