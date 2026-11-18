<script setup lang="ts">
/** Settings › Notifications › SMS (merchant's own Twilio account): credentials, which events text buyers, recent messages. */
import { onMounted, reactive, ref } from 'vue'
import { NButton, NCard, NForm, NFormItem, NInput, NSwitch, NTag, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Msg { id: number; order_id: number | null; type: string; to: string; status: 'queued' | 'sent' | 'failed'; error: string | null; created_at: string }

const message = useMessage()
const form = reactive({ enabled: false, account_sid: '', auth_token: '', from: '', events: { order_confirmation: false, shipping_confirmation: true } as Record<string, boolean> })
const tokenSet = ref(false)
const recent = ref<Msg[]>([])
const saving = ref(false)
const testTo = ref('')
const testing = ref(false)

function apply(d: { settings: { enabled: boolean; account_sid: string | null; from: string | null; events: Record<string, boolean>; auth_token_set: boolean }; recent: Msg[] }) {
  Object.assign(form, { enabled: d.settings.enabled, account_sid: d.settings.account_sid ?? '', from: d.settings.from ?? '', events: { ...d.settings.events }, auth_token: '' })
  tokenSet.value = d.settings.auth_token_set
  recent.value = d.recent
}
onMounted(async () => apply((await http.get('/settings/sms')).data))

async function save() {
  saving.value = true
  try {
    apply((await http.put('/settings/sms', { ...form, account_sid: form.account_sid || null, from: form.from || null, auth_token: form.auth_token || undefined })).data)
    message.success(t('已保存'))
  } catch (e) { message.error(errorMessage(e)) } finally { saving.value = false }
}
async function test() {
  testing.value = true
  try {
    const { data } = await http.post('/settings/sms/test', { to: testTo.value })
    if (data.message.status === 'sent') message.success(t('测试短信已发送'))
    else message.error(data.message.error ?? t('发送失败'))
    apply((await http.get('/settings/sms')).data)
  } catch (e) { message.error(errorMessage(e)) } finally { testing.value = false }
}
const typeLabel: Record<string, string> = { order_confirmation: t('订单确认'), shipping_confirmation: t('发货通知'), test: t('测试') }
</script>

<template>
  <NCard :title="t('短信通知')" size="small">
    <template #header-extra><NSwitch v-model:value="form.enabled" :aria-label="t('开启短信通知')" /></template>
    <p class="hint">{{ t('用你自己的 Twilio 账户给买家发短信（按买家购物时的语言）。买家需要在结账时填写手机号；没写国家码时按收货国家补上。短信费用由 Twilio 直接向你收取。') }}</p>
    <NForm label-placement="top" @submit.prevent="save">
      <div class="grid">
        <NFormItem label="Account SID"><NInput v-model:value="form.account_sid" placeholder="AC…" /></NFormItem>
        <NFormItem label="Auth Token"><NInput v-model:value="form.auth_token" type="password" show-password-on="click" :placeholder="tokenSet ? t('已保存（留空则不修改）') : ''" /></NFormItem>
        <NFormItem :label="t('发送号码')"><NInput v-model:value="form.from" placeholder="+15550001111" /></NFormItem>
      </div>
      <div class="events">
        <label><NSwitch v-model:value="form.events.order_confirmation" size="small" /> {{ t('订单确认短信') }}</label>
        <label><NSwitch v-model:value="form.events.shipping_confirmation" size="small" /> {{ t('发货通知短信（带物流单号）') }}</label>
      </div>
      <div class="row">
        <NButton type="primary" attr-type="submit" :loading="saving">{{ t('保存短信设置') }}</NButton>
        <NInput v-model:value="testTo" size="small" :placeholder="t('你的手机号，如 +8613800000000')" style="width: 220px" />
        <NButton size="small" :loading="testing" :disabled="!tokenSet || !testTo" @click="test">{{ t('发测试短信') }}</NButton>
      </div>
    </NForm>
    <div v-if="recent.length" class="recent">
      <div class="sub">{{ t('最近的短信') }}</div>
      <div v-for="m in recent" :key="m.id" class="msg">
        <span>{{ typeLabel[m.type] ?? m.type }} · {{ m.to }}</span>
        <span class="muted">{{ formatDate(m.created_at) }}</span>
        <NTag size="small" :bordered="false" :type="m.status === 'sent' ? 'success' : m.status === 'failed' ? 'error' : 'default'">
          {{ m.status === 'sent' ? t('已发送') : m.status === 'failed' ? t('失败') : t('排队中') }}
        </NTag>
        <span v-if="m.error" class="muted err">{{ m.error }}</span>
      </div>
    </div>
  </NCard>
</template>

<style scoped>
.hint { font-size: 12px; color: var(--ink-muted); margin: 0 0 12px; line-height: 1.6; }
.grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.events { display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 12px; font-size: 14px; }
.events label { display: inline-flex; align-items: center; gap: 8px; }
.row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.recent { margin-top: 16px; border-top: 1px solid var(--border); padding-top: 12px; }
.sub { font-size: 12px; color: var(--ink-muted); margin-bottom: 6px; }
.msg { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 13px; padding: 4px 0; }
.muted { color: var(--ink-muted); font-size: 12px; }
.err { flex-basis: 100%; }
@media (max-width: 800px) { .grid { grid-template-columns: 1fr; } }
</style>
