<script setup lang="ts">
import { computed, h, onMounted, reactive, ref } from 'vue'
import {
  NCard, NButton, NSpin, NTag, NModal, NInput, NCheckbox, NCheckboxGroup, NSwitch, NEmpty, NDataTable, NAlert,
  useMessage, useDialog, type DataTableColumns,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import SettingsNav from '@/components/SettingsNav.vue'
import { t } from '@/i18n'

interface Token { id: number; name: string; prefix: string; scopes: string[]; last_used_at: string | null; created_at: string }
interface Hook { id: number; url: string; topics: string[]; enabled: boolean; failure_count: number; disabled_reason: string | null; last_success_at: string | null; last_failure_at: string | null }
interface Delivery { id: number; event_id: string; topic: string; status: 'pending' | 'success' | 'failed'; attempts: number; response_code: number | null; response_body: string | null; created_at: string }

const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const tokens = ref<Token[]>([])
const hooks = ref<Hook[]>([])
const scopes = ref<{ key: string; label: string; description: string }[]>([])
const topics = ref<{ key: string; label: string }[]>([])
const apiBase = ref('')
const scopeLabel = computed(() => Object.fromEntries(scopes.value.map((s) => [s.key, s.label])))
const topicLabel = computed(() => Object.fromEntries(topics.value.map((s) => [s.key, s.label])))

async function load() {
  try {
    const { data } = await http.get('/developers')
    tokens.value = data.tokens
    hooks.value = data.webhooks
    scopes.value = data.scopes
    topics.value = data.topics
    apiBase.value = data.api_base
  } finally {
    loading.value = false
  }
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    message.success(t('已复制'))
  } catch {
    message.info(text)
  }
}

// ── Tokens ──
const tokenOpen = ref(false)
const tokenForm = reactive({ name: '', scopes: [] as string[] })
const newToken = ref('')
const creating = ref(false)
async function createToken() {
  creating.value = true
  try {
    const { data } = await http.post('/developers/tokens', tokenForm)
    newToken.value = data.token
    tokenForm.name = ''
    tokenForm.scopes = []
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    creating.value = false
  }
}
function closeToken() {
  tokenOpen.value = false
  newToken.value = ''
}
function revoke(tk: Token) {
  dialog.warning({
    title: t('撤销令牌「{0}」？', [tk.name]), content: t('撤销后，使用这个令牌的程序会立即无法访问店铺。'),
    positiveText: t('撤销'), negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.delete(`/developers/tokens/${tk.id}`)
      await load()
    },
  })
}

// ── Webhooks ──
const hookOpen = ref(false)
const hookForm = reactive({ id: null as number | null, url: '', topics: [] as string[], enabled: true })
const newSecret = ref('')
const savingHook = ref(false)
function editHook(hk?: Hook) {
  Object.assign(hookForm, hk ? { id: hk.id, url: hk.url, topics: [...hk.topics], enabled: hk.enabled } : { id: null, url: '', topics: [], enabled: true })
  newSecret.value = ''
  hookOpen.value = true
}
async function saveHook() {
  savingHook.value = true
  try {
    if (hookForm.id) {
      await http.put(`/developers/webhooks/${hookForm.id}`, hookForm)
      hookOpen.value = false
    } else {
      newSecret.value = (await http.post('/developers/webhooks', hookForm)).data.secret
    }
    message.success(t('已保存'))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    savingHook.value = false
  }
}
function removeHook(hk: Hook) {
  dialog.warning({
    title: t('删除这个 Webhook？'), content: hk.url, positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.delete(`/developers/webhooks/${hk.id}`)
      await load()
    },
  })
}
async function showSecret(hk: Hook) {
  const { data } = await http.get(`/developers/webhooks/${hk.id}/secret`)
  dialog.info({ title: t('签名密钥'), content: () => h('code', { style: 'word-break:break-all' }, data.secret), positiveText: t('复制'), onPositiveClick: () => copy(data.secret) })
}
async function testHook(hk: Hook) {
  try {
    const d: Delivery = (await http.post(`/developers/webhooks/${hk.id}/test`)).data.data
    d.status === 'success' ? message.success(t('测试成功：对方返回 {0}', [d.response_code ?? ''])) : message.error(t('测试失败：{0}', [d.response_code ?? d.response_body ?? '']))
  } catch (e) {
    message.error(errorMessage(e))
  }
}

// ── Deliveries ──
const logFor = ref<Hook | null>(null)
const deliveries = ref<Delivery[]>([])
async function openLog(hk: Hook) {
  logFor.value = hk
  deliveries.value = (await http.get(`/developers/webhooks/${hk.id}/deliveries`)).data.data
}
async function redeliver(d: Delivery) {
  const res: Delivery = (await http.post(`/developers/deliveries/${d.id}/redeliver`)).data.data
  res.status === 'success' ? message.success(t('已重新发送')) : message.error(t('重新发送失败：{0}', [res.response_code ?? res.response_body ?? '']))
  if (logFor.value) await openLog(logFor.value)
}
const statusTag = { success: { label: t('成功'), type: 'success' }, failed: { label: t('失败'), type: 'error' }, pending: { label: t('重试中'), type: 'warning' } } as const
const deliveryColumns: DataTableColumns<Delivery> = [
  { title: t('时间'), key: 'created_at', width: 150, render: (r) => formatDate(r.created_at) },
  { title: t('事件'), key: 'topic', width: 140 },
  { title: t('结果'), key: 'status', width: 100, render: (r) => h(NTag, { size: 'small', bordered: false, type: statusTag[r.status].type }, () => statusTag[r.status].label) },
  { title: t('响应'), key: 'response_code', render: (r) => h('span', { class: 'mono small' }, `${r.response_code ?? '—'} · ${t('{0} 次', [r.attempts])}${r.response_body ? ' · ' + r.response_body.slice(0, 80) : ''}`) },
  { title: '', key: 'a', width: 90, render: (r) => h(NButton, { size: 'tiny', quaternary: true, onClick: () => redeliver(r) }, () => t('重新发送')) },
]

onMounted(load)
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Settings</div>
      <h1 class="page-title">{{ t('设置') }}</h1>
    </div>
    <SettingsNav />

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else>
      <NCard :title="t('API 访问令牌')" size="small">
        <template #header-extra><NButton size="small" type="primary" @click="tokenOpen = true">{{ t('创建令牌') }}</NButton></template>
        <p class="muted">{{ t('给 ERP、仓库系统或自己写的程序使用，按权限读取或修改店铺数据。接口地址：') }}<code>{{ apiBase }}</code></p>
        <div v-if="tokens.length" class="list">
          <div v-for="tk in tokens" :key="tk.id" class="item">
            <div class="grow">
              <strong>{{ tk.name }}</strong> <code class="muted small">{{ tk.prefix }}…</code>
              <div class="tags"><NTag v-for="s in tk.scopes" :key="s" size="tiny" :bordered="false">{{ scopeLabel[s] ?? s }}</NTag></div>
              <div class="muted small">{{ tk.last_used_at ? t('最近使用 {0}', [formatDate(tk.last_used_at)]) : t('还没有使用过') }}</div>
            </div>
            <NButton size="small" quaternary type="error" @click="revoke(tk)">{{ t('撤销') }}</NButton>
          </div>
        </div>
        <NEmpty v-else :description="t('还没有令牌')" style="padding: 20px 0" />
      </NCard>

      <NCard title="Webhooks" size="small">
        <template #header-extra><NButton size="small" type="primary" @click="editHook()">{{ t('添加 Webhook') }}</NButton></template>
        <p class="muted">{{ t('店铺发生事件时（新订单、付款、发货、商品变动等），系统会把数据 POST 到你的地址，并用签名密钥签名。失败会自动重试。') }}</p>
        <div v-if="hooks.length" class="list">
          <div v-for="hk in hooks" :key="hk.id" class="item">
            <div class="grow">
              <code class="url">{{ hk.url }}</code>
              <div class="tags"><NTag v-for="tp in hk.topics" :key="tp" size="tiny" :bordered="false">{{ topicLabel[tp] ?? tp }}</NTag></div>
              <div class="muted small">
                <NTag size="tiny" :bordered="false" :type="hk.enabled ? 'success' : 'warning'">{{ hk.enabled ? t('启用') : t('已停用') }}</NTag>
                <template v-if="hk.disabled_reason"> · {{ hk.disabled_reason }}</template>
                <template v-else-if="hk.last_success_at"> · {{ t('最近成功 {0}', [formatDate(hk.last_success_at)]) }}</template>
                <template v-if="hk.failure_count"> · {{ t('连续失败 {0} 次', [hk.failure_count]) }}</template>
              </div>
            </div>
            <div class="btns">
              <NButton size="tiny" @click="testHook(hk)">{{ t('发送测试') }}</NButton>
              <NButton size="tiny" quaternary @click="openLog(hk)">{{ t('发送记录') }}</NButton>
              <NButton size="tiny" quaternary @click="showSecret(hk)">{{ t('签名密钥') }}</NButton>
              <NButton size="tiny" quaternary @click="editHook(hk)">{{ t('编辑') }}</NButton>
              <NButton size="tiny" quaternary type="error" @click="removeHook(hk)">{{ t('删除') }}</NButton>
            </div>
          </div>
        </div>
        <NEmpty v-else :description="t('还没有 Webhook')" style="padding: 20px 0" />
      </NCard>
    </template>

    <NModal :show="tokenOpen" preset="card" :title="t('创建令牌')" style="max-width: 560px" @update:show="(v: boolean) => { if (!v) closeToken() }">
      <template v-if="newToken">
        <NAlert type="warning" :bordered="false">{{ t('请立即复制并妥善保存。令牌只显示这一次，关闭后无法再查看。') }}</NAlert>
        <div class="secret"><code>{{ newToken }}</code><NButton size="small" @click="copy(newToken)">{{ t('复制') }}</NButton></div>
        <p class="muted small">{{ t('调用示例：') }}<code>curl -H "Authorization: Bearer {{ newToken.slice(0, 10) }}…" {{ apiBase }}/products</code></p>
      </template>
      <template v-else>
        <div class="label">{{ t('名称') }}</div>
        <NInput v-model:value="tokenForm.name" maxlength="80" :placeholder="t('例如：ERP 同步、仓库系统')" />
        <div class="label" style="margin-top: 14px">{{ t('权限') }}</div>
        <NCheckboxGroup v-model:value="tokenForm.scopes">
          <div class="checks">
            <NCheckbox v-for="s in scopes" :key="s.key" :value="s.key"><b>{{ s.label }}</b> <span class="muted small">{{ s.description }}</span></NCheckbox>
          </div>
        </NCheckboxGroup>
      </template>
      <template #footer>
        <div class="foot">
          <NButton v-if="newToken" type="primary" @click="closeToken">{{ t('我已保存') }}</NButton>
          <NButton v-else type="primary" :loading="creating" @click="createToken">{{ t('创建') }}</NButton>
        </div>
      </template>
    </NModal>

    <NModal v-model:show="hookOpen" preset="card" :title="hookForm.id ? t('编辑 Webhook') : t('添加 Webhook')" style="max-width: 560px">
      <template v-if="newSecret">
        <NAlert type="success" :bordered="false">{{ t('已添加。用下面的签名密钥校验请求头 X-Lumee-Hmac-Sha256（以后也可以在列表里查看）。') }}</NAlert>
        <div class="secret"><code>{{ newSecret }}</code><NButton size="small" @click="copy(newSecret)">{{ t('复制') }}</NButton></div>
      </template>
      <template v-else>
        <div class="label">{{ t('接收地址') }}</div>
        <NInput v-model:value="hookForm.url" placeholder="https://example.com/webhooks/lumee" maxlength="500" />
        <div class="label" style="margin-top: 14px">{{ t('事件') }}</div>
        <NCheckboxGroup v-model:value="hookForm.topics">
          <div class="checks two">
            <NCheckbox v-for="tp in topics" :key="tp.key" :value="tp.key">{{ tp.label }} <code class="muted small">{{ tp.key }}</code></NCheckbox>
          </div>
        </NCheckboxGroup>
        <div v-if="hookForm.id" class="row"><NSwitch v-model:value="hookForm.enabled" /> {{ t('启用') }}</div>
      </template>
      <template #footer>
        <div class="foot">
          <NButton v-if="newSecret" type="primary" @click="hookOpen = false">{{ t('完成') }}</NButton>
          <NButton v-else type="primary" :loading="savingHook" @click="saveHook">{{ t('保存') }}</NButton>
        </div>
      </template>
    </NModal>

    <NModal :show="!!logFor" preset="card" :title="t('发送记录')" style="max-width: 860px" @update:show="(v: boolean) => { if (!v) logFor = null }">
      <NDataTable :columns="deliveryColumns" :data="deliveries" :bordered="false" size="small" :max-height="460" />
    </NModal>
  </div>
</template>

<style scoped>
.page { max-width: 860px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0 0 12px; }
.small { font-size: 12px; margin: 0; }
.muted code { word-break: break-all; }
.list { display: grid; border: 1px solid var(--border); border-radius: 10px; }
.item { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; }
.item + .item { border-top: 1px solid var(--border); }
.grow { flex: 1; min-width: 0; display: grid; gap: 4px; }
.tags { display: flex; flex-wrap: wrap; gap: 4px; }
.btns { display: flex; flex-wrap: wrap; gap: 4px; justify-content: flex-end; max-width: 300px; }
.url { word-break: break-all; font-size: 13px; }
.label { font-size: 13px; margin-bottom: 6px; }
.checks { display: grid; gap: 8px; }
.checks.two { grid-template-columns: 1fr 1fr; }
.secret { display: flex; gap: 8px; align-items: center; margin: 14px 0; padding: 10px 12px; background: var(--surface-muted); border-radius: 8px; }
.secret code { flex: 1; word-break: break-all; font-size: 13px; }
.row { display: flex; gap: 8px; align-items: center; margin-top: 14px; font-size: 14px; }
.foot { display: flex; justify-content: flex-end; }
:deep(.mono) { font-family: ui-monospace, Menlo, Consolas, monospace; }
@media (max-width: 640px) { .checks.two { grid-template-columns: 1fr; } .item { flex-direction: column; } .btns { max-width: none; justify-content: flex-start; } }
</style>
