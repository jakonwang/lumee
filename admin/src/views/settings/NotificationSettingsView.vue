<script setup lang="ts">
import SmsSettingsCard from '@/components/SmsSettingsCard.vue'
import { onMounted, reactive, ref } from 'vue'
import {
  NCard, NForm, NFormItem, NInput, NSwitch, NButton, NModal, NSpin, NTag, NAlert, useMessage,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import SettingsNav from '@/components/SettingsNav.vue'
import { t } from '@/i18n'

interface NotificationType { key: string; audience: 'customer' | 'merchant'; label: string; description: string }
interface Settings { sender_name: string; reply_to: string; merchant_email: string; enabled: Record<string, boolean> }

const message = useMessage()
const loading = ref(true)
const saving = ref(false)
const types = ref<NotificationType[]>([])
const fromAddress = ref('')
const mailer = ref('')
const form = reactive<Settings>({ sender_name: '', reply_to: '', merchant_email: '', enabled: {} })

const preview = reactive({ show: false, loading: false, html: '', title: '', key: '' })
const testing = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/settings/notifications')
    apply(data)
  } finally {
    loading.value = false
  }
}

function apply(data: { settings: Settings; types: NotificationType[]; from_address: string; mailer: string }) {
  Object.assign(form, data.settings)
  types.value = data.types
  fromAddress.value = data.from_address
  mailer.value = data.mailer
}

async function save() {
  saving.value = true
  try {
    const { data } = await http.put('/settings/notifications', form)
    apply(data)
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

async function openPreview(it: NotificationType) {
  Object.assign(preview, { show: true, loading: true, html: '', title: it.label, key: it.key })
  try {
    const { data } = await http.get(`/notifications/${it.key}/preview`, { responseType: 'text' })
    preview.html = data
  } catch (e) {
    message.error(errorMessage(e))
    preview.show = false
  } finally {
    preview.loading = false
  }
}

async function sendTest(key: string) {
  testing.value = key
  try {
    const { data } = await http.post(`/notifications/${key}/test`)
    message.success(data.message)
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    testing.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Settings</div>
      <h1 class="page-title" style="margin: 0">{{ t('设置') }}</h1>
    </div>
    <SettingsNav />
    <p class="page-sub">{{ t('订单状态变化时自动发给买家和你的邮件') }}</p>

    <NAlert v-if="mailer === 'log'" type="info" :bordered="false" class="note">
      {{ t('当前为开发模式：邮件不会真正发出，而是写入') }} <code>backend/storage/logs/laravel.log</code>{{ t('。 上线前在') }} <code>.env</code> {{ t('中配置 SMTP（或 Resend / Amazon SES 等）即可真实发送。') }}
    </NAlert>

    <div v-if="loading" class="center"><NSpin /></div>

    <template v-else>
      <NCard :title="t('发件信息')" size="small">
        <NForm label-placement="top" class="grid">
          <NFormItem :label="t('发件人名称')" :show-feedback="false">
            <NInput v-model:value="form.sender_name" maxlength="80" />
          </NFormItem>
          <NFormItem :label="t('买家回复到')" :show-feedback="false">
            <NInput v-model:value="form.reply_to" placeholder="support@yourbrand.com" />
          </NFormItem>
          <NFormItem :label="t('新订单提醒发送到')" :show-feedback="false">
            <NInput v-model:value="form.merchant_email" />
          </NFormItem>
        </NForm>
        <p class="hint">
          {{ t('邮件从平台地址') }} <code>{{ fromAddress }}</code> {{ t('发出（保证不进垃圾箱），买家看到的发件人是「{0}」， 直接回复会发到你的邮箱。', [form.sender_name || t('店铺名称')]) }}
        </p>
      </NCard>

      <NCard :title="t('通知类型')" size="small" content-style="padding: 0">
        <div v-for="it in types" :key="it.key" class="row">
          <div class="row-main">
            <div class="row-title">
              {{ it.label }}
              <NTag size="small" :bordered="false" :type="it.audience === 'customer' ? 'info' : 'warning'">
                {{ it.audience === 'customer' ? t('发给买家') : t('发给你') }}
              </NTag>
            </div>
            <div class="row-desc">{{ it.description }}</div>
          </div>
          <div class="row-actions">
            <NButton size="small" quaternary @click="openPreview(it)">{{ t('预览') }}</NButton>
            <NButton size="small" quaternary :loading="testing === it.key" @click="sendTest(it.key)">{{ t('发测试邮件') }}</NButton>
            <NSwitch v-model:value="form.enabled[it.key]" :aria-label="t('开启{0}', [it.label])" />
          </div>
        </div>
      </NCard>

      <div class="actions">
        <NButton type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton>
      </div>

      <SmsSettingsCard />
    </template>

    <NModal v-model:show="preview.show" preset="card" :title="t('预览：{0}', [preview.title])" style="width: min(720px, 94vw)">
      <div v-if="preview.loading" class="center"><NSpin /></div>
      <!-- sandbox: no scripts, isolated from the admin -->
      <iframe v-else class="mail-frame" :srcdoc="preview.html" sandbox="allow-popups" :title="t('邮件预览')" />
      <template #footer>
        <div class="modal-foot">
          <span class="hint">{{ t('使用最近一笔订单的数据生成；没有订单时使用示例数据。') }}</span>
          <NButton size="small" :loading="testing === preview.key" @click="sendTest(preview.key)">{{ t('发测试邮件给我') }}</NButton>
        </div>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.page { max-width: 860px; display: grid; gap: 16px; }
.page > .eyebrow, .page > .page-title, .page > .page-sub { margin-bottom: 0; }
.page-sub { margin: 0 !important; }
.note code, .hint code { font-size: 12px; background: var(--brand-50); padding: 1px 6px; border-radius: 4px; }
.center { display: flex; justify-content: center; padding: 48px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px 16px; }
.hint { font-size: 12px; color: var(--ink-muted); margin: 12px 0 0; line-height: 1.6; }
.row { display: flex; align-items: center; gap: 16px; padding: 14px 16px; flex-wrap: wrap; }
.row + .row { border-top: 1px solid var(--border); }
.row-main { flex: 1; min-width: 220px; }
.row-title { display: flex; align-items: center; gap: 8px; font-weight: 500; }
.row-desc { font-size: 13px; color: var(--ink-muted); margin-top: 2px; }
.row-actions { display: flex; align-items: center; gap: 4px; }
.actions { display: flex; justify-content: flex-end; }
.mail-frame { width: 100%; height: 70vh; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
.modal-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
</style>
