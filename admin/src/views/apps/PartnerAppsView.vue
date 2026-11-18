<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NCard, NButton, NSpin, NTag, NEmpty, NModal, NInput, NCheckbox, NCheckboxGroup, NAlert, NRate, useMessage, useDialog } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

interface PartnerApp {
  id: number; name: string; slug: string; tagline: string | null; description: string | null; icon_url: string | null; app_url: string
  redirect_uris: string[]; scopes: string[]; uninstall_url: string | null; client_id: string; client_secret_prefix: string
  signing_secret: string; status: 'draft' | 'review' | 'listed' | 'rejected'; review_note: string | null; installs: number
  rating: number; rating_count: number
  embedded: boolean; pricing: string | null; earnings: { last_30_days: string; total: string; active_charges: number }
}
interface Review { id: number; rating: number; body: string | null; store: string | null; developer_reply: string | null; updated_at: string; draft?: string; saving?: boolean }

const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const apps = ref<PartnerApp[]>([])
const scopes = ref<{ key: string; label: string; description: string }[]>([])
const endpoints = ref({ authorize: '', token: '', api: '' })
const editOpen = ref(false)
const saving = ref(false)
const secret = ref('')
const form = reactive({ id: null as number | null, name: '', tagline: '', description: '', icon_url: '', app_url: '', redirects: '', scopes: [] as string[], uninstall_url: '', embedded: false, pricing: '' })
const scopeLabel = computed(() => Object.fromEntries(scopes.value.map((s) => [s.key, s.label])))
const statusMeta = {
  draft: { label: t('草稿'), type: 'default' }, review: { label: t('审核中'), type: 'info' },
  listed: { label: t('已上架'), type: 'success' }, rejected: { label: t('未通过'), type: 'error' },
} as const

// Merchant reviews + public replies
const reviewsFor = ref<PartnerApp | null>(null)
const reviews = ref<Review[]>([])
const reviewsOpen = ref(false)

async function openReviews(a: PartnerApp) {
  reviewsFor.value = a
  reviewsOpen.value = true
  try {
    reviews.value = (await http.get(`/partner/apps/${a.id}/reviews`)).data.data.map((r: Review) => ({ ...r, draft: r.developer_reply ?? '' }))
  } catch (e) {
    message.error(errorMessage(e))
  }
}

async function reply(r: Review) {
  if (!reviewsFor.value) return
  r.saving = true
  try {
    r.developer_reply = (await http.put(`/partner/apps/${reviewsFor.value.id}/reviews/${r.id}/reply`, { reply: r.draft })).data.developer_reply
    message.success(t('回复已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    r.saving = false
  }
}

async function load() {
  try {
    const { data } = await http.get('/partner/apps')
    apps.value = data.data
    scopes.value = data.scopes
    endpoints.value = data.endpoints
  } finally {
    loading.value = false
  }
}

function edit(a?: PartnerApp) {
  Object.assign(form, a
    ? { id: a.id, name: a.name, tagline: a.tagline ?? '', description: a.description ?? '', icon_url: a.icon_url ?? '', app_url: a.app_url, redirects: a.redirect_uris.join('\n'), scopes: [...a.scopes], uninstall_url: a.uninstall_url ?? '', embedded: a.embedded, pricing: a.pricing ?? '' }
    : { id: null, name: '', tagline: '', description: '', icon_url: '', app_url: '', redirects: '', scopes: [], uninstall_url: '', embedded: false, pricing: '' })
  secret.value = ''
  editOpen.value = true
}

async function save() {
  saving.value = true
  try {
    const body = {
      name: form.name, tagline: form.tagline || null, description: form.description || null, icon_url: form.icon_url || null, app_url: form.app_url,
      redirect_uris: form.redirects.split(/\s+/).filter(Boolean), scopes: form.scopes, uninstall_url: form.uninstall_url || null, embedded: form.embedded, pricing: form.pricing || null,
    }
    if (form.id) {
      await http.put(`/partner/apps/${form.id}`, body)
      editOpen.value = false
    } else {
      secret.value = (await http.post('/partner/apps', body)).data.client_secret
    }
    message.success(t('已保存'))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

async function act(a: PartnerApp, what: 'submit' | 'secret' | 'delete') {
  const run = async () => {
    try {
      if (what === 'delete') {
        await http.delete(`/partner/apps/${a.id}`)
      } else {
        const { data } = await http.post(`/partner/apps/${a.id}/${what}`)
        if (what === 'secret') {
          dialog.success({ title: t('新的 Client Secret'), content: data.client_secret, positiveText: t('复制'), onPositiveClick: () => copy(data.client_secret) })
        } else {
          message.success(t('已提交审核'))
        }
      }
      await load()
    } catch (e) {
      message.error(errorMessage(e))
    }
  }
  if (what === 'submit') return run()
  dialog.warning({
    title: what === 'delete' ? t('删除应用「{0}」？', [a.name]) : t('重新生成 Client Secret？'),
    content: what === 'delete' ? t('所有店铺会立即卸载这个应用。') : t('旧的密钥会立即失效，请同时更新你的服务器配置。'),
    positiveText: t('确定'), negativeText: t('取消'), onPositiveClick: run,
  })
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    message.success(t('已复制'))
  } catch {
    message.info(text)
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Partners</div>
        <h1 class="page-title">{{ t('我的应用（开发者）') }}</h1>
        <p class="page-sub">{{ t('开发可以安装到任何 Lumée 店铺的应用。通过 OAuth 获得店铺授权后，用开放 API 读写数据、订阅 Webhook。上架应用市场前需要平台审核。') }}</p>
      </div>
      <div class="btns">
        <NButton quaternary @click="router.push({ name: 'partner-themes' })">{{ t('我的主题') }}</NButton>
        <NButton quaternary @click="router.push({ name: 'partner-payouts' })">{{ t('收入与结算') }}</NButton>
        <NButton type="primary" @click="edit()">{{ t('创建应用') }}</NButton>
      </div>
    </div>

    <NCard size="small" :title="t('接入地址')">
      <div class="kv"><span>{{ t('授权页') }}</span><code>{{ endpoints.authorize }}?client_id=…&amp;redirect_uri=…&amp;scope=…&amp;state=…</code></div>
      <div class="kv"><span>{{ t('换取令牌') }}</span><code>POST {{ endpoints.token }}</code></div>
      <div class="kv"><span>API</span><code>{{ endpoints.api }}</code></div>
      <p class="muted small">{{ t('完整说明见项目文档 docs/api.md 的「OAuth 应用」一节。') }}</p>
    </NCard>

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else-if="apps.length">
      <NCard v-for="a in apps" :key="a.id" size="small">
        <div class="app-head">
          <div class="grow">
            <strong>{{ a.name }}</strong> <NTag size="small" :bordered="false" :type="statusMeta[a.status].type">{{ statusMeta[a.status].label }}</NTag>
            <span class="muted small"> · {{ t('{0} 家店铺在用', [a.installs]) }}</span>
            <span class="muted small"> · <NRate :value="a.rating" readonly allow-half size="small" /> {{ a.rating_count ? a.rating.toFixed(1) : '' }} {{ t('{0} 条评价', [a.rating_count]) }}</span>
          </div>
          <div class="btns">
            <NButton v-if="a.status === 'draft' || a.status === 'rejected'" size="small" @click="act(a, 'submit')">{{ t('提交审核') }}</NButton>
            <NButton size="small" quaternary @click="openReviews(a)">{{ t('评价') }}</NButton>
            <NButton size="small" quaternary @click="edit(a)">{{ t('编辑') }}</NButton>
            <NButton size="small" quaternary @click="act(a, 'secret')">{{ t('重新生成密钥') }}</NButton>
            <NButton size="small" quaternary type="error" @click="act(a, 'delete')">{{ t('删除') }}</NButton>
          </div>
        </div>
        <NAlert v-if="a.status === 'rejected' && a.review_note" type="error" :bordered="false" style="margin-top: 10px">{{ t('审核意见：{0}', [a.review_note]) }}</NAlert>
        <div v-if="a.earnings" class="kv"><span>{{ t('收入') }}</span>
          <span>{{ t('近 30 天 US${0} · 累计 US${1} · {2} 个付费店铺', [a.earnings.last_30_days, a.earnings.total, a.earnings.active_charges]) }}</span></div>
        <div class="kv"><span>Client ID</span><code>{{ a.client_id }}</code><NButton size="tiny" quaternary @click="copy(a.client_id)">{{ t('复制') }}</NButton></div>
        <div class="kv"><span>Client Secret</span><code>{{ a.client_secret_prefix }}…</code></div>
        <div class="kv"><span>{{ t('签名密钥') }}</span><code>{{ a.signing_secret.slice(0, 8) }}…</code><NButton size="tiny" quaternary @click="copy(a.signing_secret)">{{ t('复制') }}</NButton></div>
        <div class="kv"><span>{{ t('权限') }}</span><span class="tags"><NTag v-for="s in a.scopes" :key="s" size="tiny" :bordered="false">{{ scopeLabel[s] ?? s }}</NTag></span></div>
      </NCard>
    </template>
    <NEmpty v-else :description="t('还没有创建应用')" style="padding: 32px 0" />

    <NModal v-model:show="reviewsOpen" preset="card" :title="t('「{0}」的评价', [reviewsFor?.name ?? ''])" style="max-width: 620px">
      <div v-for="r in reviews" :key="r.id" class="review">
        <div class="review-head"><NRate :value="r.rating" readonly size="small" /><strong>{{ r.store }}</strong></div>
        <p v-if="r.body">{{ r.body }}</p>
        <NInput v-model:value="r.draft" type="textarea" :autosize="{ minRows: 2, maxRows: 6 }" :maxlength="2000" :placeholder="t('公开回复这条评价（留空则删除回复）')" />
        <div class="reply-btn"><NButton size="small" :loading="r.saving" @click="reply(r)">{{ t('保存回复') }}</NButton></div>
      </div>
      <NEmpty v-if="!reviews.length" :description="t('还没有评价')" />
    </NModal>

    <NModal v-model:show="editOpen" preset="card" :title="form.id ? t('编辑应用') : t('创建应用')" style="max-width: 620px">
      <template v-if="secret">
        <NAlert type="warning" :bordered="false">{{ t('请立即复制 Client Secret，它只显示这一次。') }}</NAlert>
        <div class="secret"><code>{{ secret }}</code><NButton size="small" @click="copy(secret)">{{ t('复制') }}</NButton></div>
      </template>
      <div v-else class="form">
        <label>{{ t('应用名称') }}<NInput v-model:value="form.name" maxlength="80" /></label>
        <label>{{ t('一句话介绍') }}<NInput v-model:value="form.tagline" maxlength="160" /></label>
        <label>{{ t('详细介绍') }}<NInput v-model:value="form.description" type="textarea" :autosize="{ minRows: 3, maxRows: 8 }" maxlength="5000" /></label>
        <label>{{ t('图标地址（https）') }}<NInput v-model:value="form.icon_url" placeholder="https://…/icon.png" /></label>
        <label>{{ t('应用地址（商家从后台打开应用的页面）') }}<NInput v-model:value="form.app_url" placeholder="https://your-app.com/" /></label>
        <label>{{ t('回调地址（每行一个，最多 5 个）') }}<NInput v-model:value="form.redirects" type="textarea" :autosize="{ minRows: 2, maxRows: 5 }" placeholder="https://your-app.com/oauth/callback" /></label>
        <div>
          <div class="lbl">{{ t('申请的权限') }}</div>
          <NCheckboxGroup v-model:value="form.scopes"><div class="checks"><NCheckbox v-for="s in scopes" :key="s.key" :value="s.key">{{ s.label }}</NCheckbox></div></NCheckboxGroup>
        </div>
        <label>{{ t('卸载通知地址（选填）') }}<NInput v-model:value="form.uninstall_url" placeholder="https://your-app.com/webhooks/uninstalled" /></label>
        <label>{{ t('价格说明（显示在应用市场，选填）') }}<NInput v-model:value="form.pricing" maxlength="80" :placeholder="t('例如：免费 · 专业版 $19/月')" /></label>
        <NCheckbox v-model:checked="form.embedded">{{ t('嵌入后台打开（App Bridge）：应用页面显示在商家后台里，而不是新窗口') }}</NCheckbox>
      </div>
      <template #footer>
        <div class="foot">
          <NButton v-if="secret" type="primary" @click="editOpen = false">{{ t('我已保存') }}</NButton>
          <NButton v-else type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton>
        </div>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.page { max-width: 900px; display: grid; gap: 16px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; max-width: 640px; }
.center { display: flex; justify-content: center; padding: 48px; }
.kv { display: flex; align-items: center; gap: 10px; font-size: 13px; padding: 4px 0; }
.kv > span:first-child { min-width: 90px; color: var(--ink-muted); }
.kv code { word-break: break-all; }
.muted { color: var(--ink-muted); }
.small { font-size: 12px; margin: 6px 0 0; }
.app-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
.grow { flex: 1; min-width: 0; }
.btns { display: flex; gap: 4px; flex-wrap: wrap; }
.tags { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.form { display: grid; gap: 12px; }
.form label, .lbl { display: grid; gap: 6px; font-size: 13px; }
.checks { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.secret { display: flex; gap: 8px; align-items: center; margin-top: 14px; padding: 10px 12px; background: var(--surface-muted); border-radius: 8px; }
.secret code { flex: 1; word-break: break-all; }
.foot { display: flex; justify-content: flex-end; }
.review { padding: 12px 0; border-bottom: 1px solid var(--border); display: grid; gap: 8px; }
.review-head { display: flex; gap: 8px; align-items: center; }
.review p { margin: 0; font-size: 13px; line-height: 1.6; white-space: pre-line; }
.reply-btn { display: flex; justify-content: flex-end; }
</style>
