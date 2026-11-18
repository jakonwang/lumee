<script setup lang="ts">
/** Settings › Account security: two-step sign-in for the signed-in staff account. */
import { onMounted, ref } from 'vue'
import { NAlert, NButton, NCard, NInput, NModal, NSpin, NTag, useMessage } from 'naive-ui'
import QRCode from 'qrcode'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import SettingsNav from '@/components/SettingsNav.vue'
import { t } from '@/i18n'

const message = useMessage()
const loading = ref(true)
const state = ref<{ enabled: boolean; confirmed_at: string | null; recovery_codes_left: number } | null>(null)
async function load() {
  state.value = (await http.get('/account/two-factor')).data
  loading.value = false
}
onMounted(load)

// Setup: password → QR + secret → code → recovery codes
const step = ref<'password' | 'scan' | 'codes' | null>(null)
const action = ref<'enable' | 'disable' | 'codes'>('enable')
const password = ref('')
const code = ref('')
const secret = ref('')
const qr = ref('')
const recovery = ref<string[]>([])
const busy = ref(false)

function open(a: 'enable' | 'disable' | 'codes') {
  action.value = a
  password.value = ''
  code.value = ''
  step.value = 'password'
}
async function withPassword() {
  busy.value = true
  try {
    if (action.value === 'enable') {
      const { data } = await http.post('/account/two-factor', { password: password.value })
      secret.value = data.secret
      qr.value = await QRCode.toDataURL(data.uri, { margin: 1, width: 200, color: { dark: '#1C2621', light: '#FFFFFF' } })
      step.value = 'scan'
    } else if (action.value === 'disable') {
      await http.delete('/account/two-factor', { data: { password: password.value } })
      message.success(t('两步验证已关闭'))
      step.value = null
      load()
    } else {
      recovery.value = (await http.post('/account/two-factor/recovery-codes', { password: password.value })).data.recovery_codes
      step.value = 'codes'
      load()
    }
  } catch (e) { message.error(errorMessage(e)) } finally { busy.value = false }
}
async function confirm() {
  busy.value = true
  try {
    recovery.value = (await http.post('/account/two-factor/confirm', { code: code.value })).data.recovery_codes
    step.value = 'codes'
    load()
  } catch (e) { message.error(errorMessage(e)) } finally { busy.value = false }
}
function copyCodes() {
  navigator.clipboard?.writeText(recovery.value.join('\n')).then(() => message.success(t('已复制')))
}
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Settings</div>
      <h1 class="page-title">{{ t('设置') }}</h1>
    </div>
    <SettingsNav />

    <div v-if="loading" class="center"><NSpin /></div>
    <NCard v-else-if="state" :title="t('两步验证')" size="small">
      <template #header-extra>
        <NTag :type="state.enabled ? 'success' : 'default'" :bordered="false" size="small">{{ state.enabled ? t('已开启') : t('未开启') }}</NTag>
      </template>
      <p class="lead">{{ t('登录时除了密码，还需要输入手机验证器应用（如 Google Authenticator、Microsoft Authenticator、1Password）生成的 6 位验证码。即使密码泄露，别人也无法登录你的后台。') }}</p>
      <template v-if="state.enabled">
        <p class="muted">{{ t('{0} 开启 · 剩余 {1} 个恢复码', [formatDate(state.confirmed_at), state.recovery_codes_left]) }}</p>
        <NAlert v-if="state.recovery_codes_left <= 2" type="warning" :bordered="false" class="gap">{{ t('恢复码快用完了，建议重新生成。') }}</NAlert>
        <div class="actions">
          <NButton @click="open('codes')">{{ t('重新生成恢复码') }}</NButton>
          <NButton type="error" secondary @click="open('disable')">{{ t('关闭两步验证') }}</NButton>
        </div>
      </template>
      <div v-else class="actions"><NButton type="primary" @click="open('enable')">{{ t('开启两步验证') }}</NButton></div>
    </NCard>
    <p class="muted">{{ t('这项设置只对你自己的账号生效。员工需要各自在这里开启。') }}</p>

    <NModal :show="step !== null" preset="card" style="width: 460px; max-width: 94vw" :mask-closable="step !== 'codes'"
      :title="action === 'disable' ? t('关闭两步验证') : action === 'codes' ? t('重新生成恢复码') : t('开启两步验证')" @update:show="(v: boolean) => !v && (step = null)">
      <form v-if="step === 'password'" class="form" @submit.prevent="withPassword">
        <p class="muted">{{ t('请先输入你的登录密码确认身份。') }}</p>
        <NInput v-model:value="password" type="password" show-password-on="click" :placeholder="t('登录密码')" :input-props="{ autocomplete: 'current-password' }" />
        <NButton type="primary" attr-type="submit" :loading="busy" :disabled="!password">{{ t('继续') }}</NButton>
      </form>

      <form v-else-if="step === 'scan'" class="form" @submit.prevent="confirm">
        <p class="muted">{{ t('1. 用验证器应用扫描二维码（或手动输入密钥）。') }}</p>
        <img :src="qr" alt="" class="qr" width="200" height="200">
        <code class="secret">{{ secret }}</code>
        <p class="muted">{{ t('2. 输入应用里显示的 6 位验证码。') }}</p>
        <NInput v-model:value="code" placeholder="123456" :input-props="{ inputmode: 'numeric', autocomplete: 'one-time-code' }" />
        <NButton type="primary" attr-type="submit" :loading="busy" :disabled="code.replace(/\D/g, '').length !== 6">{{ t('验证并开启') }}</NButton>
      </form>

      <div v-else-if="step === 'codes'" class="form">
        <NAlert type="success" :bordered="false">{{ t('请把这些恢复码保存在安全的地方。手机丢失时，每个恢复码可以代替验证码登录一次。它们只显示这一次。') }}</NAlert>
        <div class="codes"><code v-for="c in recovery" :key="c">{{ c }}</code></div>
        <div class="actions">
          <NButton @click="copyCodes">{{ t('复制') }}</NButton>
          <NButton type="primary" @click="step = null">{{ t('我已保存') }}</NButton>
        </div>
      </div>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.center { display: flex; justify-content: center; padding: 40px; }
.lead { margin: 0 0 12px; font-size: 14px; line-height: 1.7; }
.muted { color: var(--ink-muted); font-size: 13px; margin: 0; }
.gap { margin: 12px 0; }
.actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
.form { display: grid; gap: 12px; }
.qr { justify-self: center; border: 1px solid var(--border); border-radius: 8px; }
.secret { justify-self: center; font-size: 13px; letter-spacing: 1px; background: var(--surface-muted); padding: 4px 10px; border-radius: 6px; }
.codes { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.codes code { font-family: ui-monospace, Menlo, monospace; font-size: 14px; text-align: center; padding: 8px; background: var(--surface-muted); border-radius: 6px; }
</style>
