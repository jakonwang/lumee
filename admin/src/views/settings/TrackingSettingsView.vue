<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { NCard, NForm, NFormItem, NInput, NButton, NSpin, NCheckbox, NIcon, NTag, useMessage } from 'naive-ui'
import { CopyOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

interface Settings { ga4: string | null; meta: string | null; tiktok: string | null; consent: boolean }
interface Feed { locale: string; name: string; url: string }

const message = useMessage()
const loading = ref(true)
const saving = ref(false)
const form = reactive<Settings>({ ga4: null, meta: null, tiktok: null, consent: false })
const feeds = ref<Feed[]>([])

interface ServerEvents { meta_token_set: boolean; tiktok_token_set: boolean; test_code: string | null; status: Record<'meta' | 'tiktok', { ok: boolean; error: string | null; at: string } | null> }
const server = ref<ServerEvents | null>(null)
const tokens = reactive({ meta_token: '', tiktok_token: '', test_code: '' })

function apply(d: { settings: Settings; feeds: Feed[]; server_events?: ServerEvents }) {
  Object.assign(form, d.settings)
  feeds.value = d.feeds
  if (d.server_events) {
    server.value = d.server_events
    tokens.meta_token = ''
    tokens.tiktok_token = ''
    tokens.test_code = d.server_events.test_code ?? ''
  }
}

const savingServer = ref(false)
async function saveServer(remove: string[] = []) {
  savingServer.value = true
  try {
    apply((await http.put('/settings/tracking/server-events', { ...tokens, remove })).data)
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    savingServer.value = false
  }
}
const testing = ref(false)
async function testServer() {
  testing.value = true
  try {
    const { data } = await http.post('/settings/tracking/server-events/test')
    apply(data)
    const failed = Object.entries(data.results as Record<string, { ok: boolean; error: string | null }>).filter(([, r]) => !r.ok)
    if (failed.length) message.error(failed.map(([k, r]) => `${k}: ${r.error}`).join('；'))
    else message.success(t('测试事件已发送，请到 Events Manager 的「测试事件」里查看'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    testing.value = false
  }
}
const fmtAt = (s: string) => new Date(s).toLocaleString()

async function load() {
  try {
    apply((await http.get('/settings/tracking')).data)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    apply((await http.put('/settings/tracking', form)).data)
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    message.success(t('已复制'))
  } catch {
    message.error(text)
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Marketing</div>
      <h1 class="page-title">{{ t('追踪代码') }}</h1>
    </div>

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else>
      <NCard :title="t('追踪代码')" size="small">
        <p class="muted top">{{ t('填写 ID 后，店铺每个页面都会加载对应的统计代码，并自动上报浏览商品、加入购物车、开始结账、完成购买等事件，可以直接用于广告投放优化。') }}</p>
        <NForm label-placement="top" @submit.prevent="save">
          <NFormItem :label="t('Google Analytics 4 衡量 ID')">
            <NInput v-model:value="form.ga4" placeholder="G-AB12CD34EF" clearable maxlength="25" />
          </NFormItem>
          <NFormItem :label="t('Meta（Facebook / Instagram）Pixel ID')">
            <NInput v-model:value="form.meta" placeholder="123456789012345" clearable maxlength="20" />
          </NFormItem>
          <NFormItem :label="t('TikTok Pixel ID')">
            <NInput v-model:value="form.tiktok" placeholder="C1A2B3C4D5E6F7G8H9I0" clearable maxlength="30" />
          </NFormItem>
          <NCheckbox v-model:checked="form.consent">{{ t('显示 Cookie 同意提示，买家同意后才加载统计代码') }}</NCheckbox>
          <p class="muted">{{ t('面向欧盟、英国的店铺建议开启（GDPR 要求）。买家拒绝后不会加载任何统计代码。') }}</p>
          <div><NButton type="primary" attr-type="submit" :loading="saving">{{ t('保存') }}</NButton></div>
        </NForm>
      </NCard>

      <NCard v-if="server" :title="t('服务器端转化事件')" size="small">
        <p class="hint">{{ t('除了浏览器里的像素，付款成功后服务器也会把购买事件直接发给 Meta（Conversions API）和 TikTok（Events API）。被广告拦截或 iOS 隐私设置挡掉的转化也能统计到，广告投放更准确。两边使用相同的事件 ID，不会重复计算。邮箱、电话等信息会先做 SHA-256 加密。') }}</p>
        <NForm label-placement="top" @submit.prevent="saveServer()">
          <NFormItem :label="t('Meta Conversions API 访问令牌')">
            <NInput v-model:value="tokens.meta_token" type="password" show-password-on="click" :disabled="!form.meta"
              :placeholder="!form.meta ? t('请先填写上面的 Meta Pixel ID') : server.meta_token_set ? t('已保存（留空则不修改）') : t('在 Events Manager › 设置 › 转化 API 里生成')" />
            <NButton v-if="server.meta_token_set" text type="error" size="small" style="margin-left: 8px" @click="saveServer(['meta_token'])">{{ t('移除') }}</NButton>
          </NFormItem>
          <NFormItem :label="t('TikTok Events API 访问令牌')">
            <NInput v-model:value="tokens.tiktok_token" type="password" show-password-on="click" :disabled="!form.tiktok"
              :placeholder="!form.tiktok ? t('请先填写上面的 TikTok Pixel ID') : server.tiktok_token_set ? t('已保存（留空则不修改）') : t('在 TikTok 事件管理 › 设置里生成')" />
            <NButton v-if="server.tiktok_token_set" text type="error" size="small" style="margin-left: 8px" @click="saveServer(['tiktok_token'])">{{ t('移除') }}</NButton>
          </NFormItem>
          <NFormItem :label="t('测试事件代码（可选，调试完请清空）')">
            <NInput v-model:value="tokens.test_code" placeholder="TEST12345" />
          </NFormItem>
          <div class="status-rows">
            <div v-for="k in (['meta', 'tiktok'] as const)" :key="k" class="status-row">
              <span>{{ k === 'meta' ? 'Meta' : 'TikTok' }}</span>
              <NTag v-if="server.status[k]" size="small" :bordered="false" :type="server.status[k]!.ok ? 'success' : 'error'">
                {{ server.status[k]!.ok ? t('上次发送成功') : t('上次发送失败') }} · {{ fmtAt(server.status[k]!.at) }}
              </NTag>
              <span v-else class="hint">{{ t('还没有发送过') }}</span>
              <span v-if="server.status[k] && !server.status[k]!.ok" class="hint">{{ server.status[k]!.error }}</span>
            </div>
          </div>
          <div style="display: flex; gap: 8px; margin-top: 12px">
            <NButton type="primary" attr-type="submit" :loading="savingServer">{{ t('保存') }}</NButton>
            <NButton :loading="testing" :disabled="!server.meta_token_set && !server.tiktok_token_set" @click="testServer">{{ t('发送测试事件') }}</NButton>
          </div>
        </NForm>
      </NCard>

      <NCard :title="t('Google Merchant Center 商品数据源')" size="small">
        <p class="muted top">{{ t('在 Merchant Center 中添加「定期抓取」数据源并填写下面的网址，Google 购物广告和免费商品展示就会自动同步你的商品、价格和库存。每个款式是一条商品。') }}</p>
        <div v-for="f in feeds" :key="f.locale" class="feed">
          <span class="lang">{{ f.name }}</span>
          <code>{{ f.url }}</code>
          <button type="button" class="copy" :aria-label="t('复制')" @click="copy(f.url)"><NIcon size="14"><CopyOutline /></NIcon></button>
        </div>
      </NCard>
    </template>
  </div>
</template>

<style scoped>
.page { max-width: 760px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 8px 0 16px; }
.muted.top { margin-top: 0; }
.feed { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-top: 1px solid var(--border); font-size: 13px; }
.feed .lang { min-width: 80px; color: var(--ink-muted); }
.feed code { flex: 1; word-break: break-all; }
.copy { border: 0; background: none; cursor: pointer; color: var(--ink-muted); padding: 4px; }
.copy:hover { color: var(--brand-600, #0E8A5F); }
.hint { font-size: 12px; color: var(--ink-muted); margin: 0 0 12px; line-height: 1.6; }
.status-rows { display: grid; gap: 6px; }
.status-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px; }
</style>
