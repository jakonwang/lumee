<script setup lang="ts">
/**
 * Marketing › Outreach (开发信): the store's own mailbox, sequences (first email + follow-ups) for leads and
 * creators, who is in them and what happened. Sending, pacing and reply detection run on the server.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { NAlert, NButton, NCard, NCheckbox, NDrawer, NDrawerContent, NEmpty, NInput, NInputNumber, NModal, NPagination, NRadio, NRadioGroup, NSelect, NSpin, NSwitch, NTag, useDialog, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { SEGMENT_OPTIONS } from '@/api/leads'
import { t } from '@/i18n'

interface Step { delay_days: number; subject: string; body: string }
interface Auto { enabled: boolean; min_score: number; segments: string[]; countries: string[] }
interface Sequence {
  id: number; name: string; audience: 'leads' | 'creators'; steps: Step[]; ai_first: boolean; auto: Auto | null; status: 'active' | 'paused'
  counts: Record<string, number>; total: number; sent: number; reply_rate: number | null
}
interface Mailbox {
  from_name: string; from_email: string; smtp_host: string; smtp_port: number; smtp_encryption: 'tls' | 'ssl'; smtp_username: string
  imap_host: string; imap_port: number; imap_encryption: 'ssl' | 'tls' | 'none'; imap_username: string
  daily_limit: number; window_start: number; window_end: number; gap_minutes: number; signature: string; address: string; skip_consent_countries: boolean
  smtp_password_set: boolean; imap_password_set: boolean; can_send: boolean; can_read_replies: boolean
}
interface Enrollment { id: number; email: string; name: string | null; country: string | null; step: number; status: string; next_at: string | null; last_sent_at: string | null; error: string | null; score: number | null }

const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const sequences = ref<Sequence[]>([])
const mailbox = ref<Mailbox | null>(null)
const sentToday = ref(0)
const aiReady = ref(false)

async function load() {
  try {
    const d = (await http.get('/outreach')).data
    sequences.value = d.sequences
    mailbox.value = d.mailbox
    sentToday.value = d.sent_today
    aiReady.value = d.ai_ready
  } finally {
    loading.value = false
  }
}
onMounted(load)

const STATUS: Record<string, string> = {
  active: t('进行中'), replied: t('已回复'), completed: t('已发完'), unsubscribed: t('已退订'), bounced: t('退信'), stopped: t('已停止'), failed: t('发送失败'),
}

// ---- mailbox ----
const mbOpen = ref(false)
const mb = reactive({ smtp_password: '', imap_password: '', clear_imap_password: false } as Record<string, unknown>)
const mbSaving = ref(false)
const testing = ref<'' | 'smtp' | 'imap'>('')
const testResult = reactive<Record<string, { ok: boolean; message: string } | null>>({ smtp: null, imap: null })
function openMailbox() {
  Object.assign(mb, { ...mailbox.value, smtp_password: '', imap_password: '', clear_imap_password: false })
  testResult.smtp = testResult.imap = null
  mbOpen.value = true
}
async function saveMailbox(close = true) {
  mbSaving.value = true
  try {
    mailbox.value = (await http.put('/outreach/mailbox', mb)).data.mailbox
    mb.smtp_password = mb.imap_password = ''
    if (close) { mbOpen.value = false; message.success(t('已保存')) }
    return true
  } catch (e) {
    message.error(errorMessage(e))
    return false
  } finally {
    mbSaving.value = false
  }
}
async function test(service: 'smtp' | 'imap') {
  if (!(await saveMailbox(false))) return
  testing.value = service
  try {
    testResult[service] = (await http.post('/outreach/mailbox/test', { service }, { timeout: 60000 })).data
  } catch (e) {
    testResult[service] = { ok: false, message: errorMessage(e) }
  } finally {
    testing.value = ''
  }
}
const hours = Array.from({ length: 25 }, (_, h) => ({ label: `${h}:00`, value: h }))

// ---- sequences ----
const DEFAULT_STEPS: Record<'leads' | 'creators', Step[]> = {
  leads: [
    { delay_days: 0, subject: '', body: 'Hi {{first_name}},\n\nWe make products that shops like {{company}} sell. Would a price list and samples be useful?\n\n{{sender_name}}' },
    { delay_days: 3, subject: '', body: 'Hi {{first_name}},\n\nJust making sure my note below reached you. Happy to send our catalog and wholesale prices.\n\n{{sender_name}}' },
    { delay_days: 5, subject: '', body: 'Hi {{first_name}},\n\nLast note from me. If this isn\'t a fit right now, no problem at all. If it is, just reply and I\'ll send samples.\n\n{{sender_name}}' },
  ],
  creators: [
    { delay_days: 0, subject: '', body: 'Hi {{first_name}},\n\nWe love your content and would like to send you a free product to try.\n\n{{sender_name}}' },
    { delay_days: 4, subject: '', body: 'Hi {{first_name}},\n\nJust checking you saw my message below. Happy to send a sample whenever suits you.\n\n{{sender_name}}' },
  ],
}
const editOpen = ref(false)
const saving = ref(false)
const form = reactive({ id: null as number | null, name: '', audience: 'leads' as 'leads' | 'creators', steps: [] as Step[], ai_first: true, status: 'active' as 'active' | 'paused',
  auto: { enabled: false, min_score: 60, segments: [] as string[], countries: [] as string[] } as Auto })
function edit(s?: Sequence) {
  Object.assign(form, s
    ? { id: s.id, name: s.name, audience: s.audience, steps: s.steps.map((x) => ({ ...x })), ai_first: s.ai_first, status: s.status, auto: { enabled: false, min_score: 60, segments: [], countries: [], ...(s.auto ?? {}) } }
    : { id: null, name: '', audience: 'leads', steps: DEFAULT_STEPS.leads.map((x) => ({ ...x })), ai_first: aiReady.value, status: 'active', auto: { enabled: false, min_score: 60, segments: [], countries: [] } })
  editOpen.value = true
}
function setAudience(a: 'leads' | 'creators') {
  form.audience = a
  if (!form.id) form.steps = DEFAULT_STEPS[a].map((x) => ({ ...x }))
}
async function save() {
  saving.value = true
  try {
    const body = { name: form.name, audience: form.audience, steps: form.steps, ai_first: form.ai_first, status: form.status, auto: form.auto }
    if (form.id) await http.put(`/outreach/sequences/${form.id}`, body)
    else await http.post('/outreach/sequences', body)
    editOpen.value = false
    message.success(t('已保存'))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}
async function toggle(s: Sequence) {
  try {
    await http.put(`/outreach/sequences/${s.id}`, { name: s.name, steps: s.steps, ai_first: s.ai_first, auto: s.auto, status: s.status === 'active' ? 'paused' : 'active' })
    await load()
  } catch (e) { message.error(errorMessage(e)) }
}
function remove(s: Sequence) {
  dialog.warning({
    title: t('删除序列「{0}」？', [s.name]), content: t('还没发出的邮件不会再发，已发送的记录也会一起删除。退订名单会保留。'),
    positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => { await http.delete(`/outreach/sequences/${s.id}`); await load() },
  })
}

// ---- recipients ----
const drawer = ref<Sequence | null>(null)
const people = ref<Enrollment[]>([])
const pMeta = ref({ total: 0, page: 1, last_page: 1 })
const pStatus = ref<string | null>(null)
const pLoading = ref(false)
async function openPeople(s: Sequence, page = 1) {
  drawer.value = s
  pLoading.value = true
  try {
    const d = (await http.get(`/outreach/sequences/${s.id}/enrollments`, { params: { page, status: pStatus.value || undefined } })).data
    people.value = d.data
    pMeta.value = d.meta
  } finally {
    pLoading.value = false
  }
}
async function stop(e: Enrollment) {
  await http.post(`/outreach/enrollments/${e.id}/stop`)
  e.status = 'stopped'
}
const preview = ref<{ who: Enrollment; emails: { step: number; delay_days: number; subject: string; body: string; sent: string | null }[] } | null>(null)
const previewing = ref<number | null>(null)
async function showPreview(e: Enrollment) {
  previewing.value = e.id
  try {
    preview.value = { who: e, emails: (await http.get(`/outreach/enrollments/${e.id}/preview`, { timeout: 120000 })).data.data }
  } catch (err) { message.error(errorMessage(err)) } finally { previewing.value = null }
}
// Kept out of the template: the {{…}} placeholders would end Vue's interpolation
const variablesHelp = t('可以用：{0} 对方名字（没有就用公司名）、{1} 公司名、{2} 你的名字、{3} 你的公司、{4} 你的网站。签名、公司地址和退订链接会自动加在最后。',
  ['{{first_name}}', '{{company}}', '{{sender_name}}', '{{sender_company}}', '{{website}}'])
const statusOptions = computed(() => Object.entries(STATUS).map(([value, label]) => ({ value, label })))
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Marketing</div>
        <h1 class="page-title">{{ t('开发信') }}</h1>
        <p class="page-sub">{{ t('用你自己的邮箱，给获客雷达找到的客户和达人自动发开发信：第一封（可以由 AI 用对方的语言写）+ 2–3 封跟进。对方回复、退订或退信后自动停止；每天限量、只在对方的工作时间发送。') }}</p>
      </div>
      <NButton type="primary" @click="edit()">{{ t('新建序列') }}</NButton>
    </div>

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else-if="mailbox">
      <NCard size="small">
        <div class="mb">
          <div>
            <div class="mb-title">{{ t('发信邮箱') }}
              <NTag size="small" :bordered="false" :type="mailbox.can_send ? 'success' : 'warning'">{{ mailbox.can_send ? t('可以发送') : t('未设置') }}</NTag>
              <NTag v-if="mailbox.can_send" size="small" :bordered="false" :type="mailbox.can_read_replies ? 'success' : 'default'">{{ mailbox.can_read_replies ? t('自动识别回复') : t('未设置收信，不能识别回复') }}</NTag>
            </div>
            <div class="muted">
              <template v-if="mailbox.from_email">{{ mailbox.from_name }} &lt;{{ mailbox.from_email }}&gt; · </template>
              {{ t('今天已发 {0} / {1} 封 · 每封间隔至少 {2} 分钟 · 对方当地 {3}:00–{4}:00、工作日', [sentToday, mailbox.daily_limit, mailbox.gap_minutes, mailbox.window_start, mailbox.window_end]) }}
            </div>
          </div>
          <NButton size="small" @click="openMailbox">{{ mailbox.can_send ? t('修改') : t('设置邮箱') }}</NButton>
        </div>
      </NCard>

      <NAlert v-if="!mailbox.can_send" type="info" :bordered="false">
        {{ t('建议用公司域名的邮箱（例如 sales@你的公司.com），并在域名里设置好 SPF、DKIM。新邮箱先从每天 20–30 封开始，几周后再慢慢加量，免得被当成垃圾邮件。') }}
      </NAlert>

      <NCard size="small" content-style="padding: 0">
        <div v-for="s in sequences" :key="s.id" class="row">
          <div class="main">
            <div class="name">
              <button type="button" class="link" @click="openPeople(s)">{{ s.name }}</button>
              <NTag size="tiny" :bordered="false">{{ s.audience === 'leads' ? t('B 端客户') : t('达人') }}</NTag>
              <NTag v-if="s.status === 'paused'" size="tiny" :bordered="false" type="warning">{{ t('已暂停') }}</NTag>
              <NTag v-if="s.auto?.enabled" size="tiny" :bordered="false" type="success">{{ t('自动加入 ≥ {0} 分', [s.auto.min_score]) }}</NTag>
            </div>
            <div class="muted">
              {{ t('{0} 封邮件', [s.steps.length]) }}{{ s.ai_first ? ` · ${t('第一封由 AI 写')}` : '' }} ·
              {{ t('{0} 人 · 进行中 {1} · 已发 {2} 封 · 回复 {3}', [s.total, s.counts.active ?? 0, s.sent, s.counts.replied ?? 0]) }}<template v-if="s.reply_rate !== null"> · {{ t('回复率 {0}%', [s.reply_rate]) }}</template>
            </div>
          </div>
          <div class="btns">
            <NButton size="small" quaternary @click="openPeople(s)">{{ t('收件人') }}</NButton>
            <NButton size="small" quaternary @click="edit(s)">{{ t('编辑') }}</NButton>
            <NButton size="small" quaternary @click="toggle(s)">{{ s.status === 'active' ? t('暂停') : t('继续') }}</NButton>
            <NButton size="small" quaternary type="error" @click="remove(s)">{{ t('删除') }}</NButton>
          </div>
        </div>
        <NEmpty v-if="!sequences.length" :description="t('还没有序列')" style="padding: 32px 0">
          <template #extra><NButton size="small" @click="edit()">{{ t('新建序列') }}</NButton></template>
        </NEmpty>
      </NCard>
      <p class="muted">{{ t('加入收件人：在「获客雷达」勾选线索，或在「达人合作」里，点「加入开发信序列」。') }}
        <RouterLink :to="{ name: 'leads' }">{{ t('去获客雷达') }}</RouterLink></p>
    </template>

    <!-- Mailbox -->
    <NModal v-model:show="mbOpen" preset="card" :title="t('发信邮箱')" style="max-width: 680px">
      <div class="form">
        <div class="grid2">
          <label>{{ t('发件人名称') }}<NInput v-model:value="(mb.from_name as string)" placeholder="Fiona Wang" /></label>
          <label>{{ t('发件邮箱') }}<NInput v-model:value="(mb.from_email as string)" placeholder="sales@yourfactory.com" /></label>
        </div>
        <div class="sec">{{ t('发信服务器（SMTP）') }}</div>
        <div class="grid3">
          <label>{{ t('服务器') }}<NInput v-model:value="(mb.smtp_host as string)" placeholder="smtp.gmail.com" /></label>
          <label>{{ t('端口') }}<NSelect v-model:value="(mb.smtp_port as number)" :options="[587, 465, 25, 2525].map((p) => ({ label: String(p), value: p }))" /></label>
          <label>{{ t('加密') }}<NSelect v-model:value="(mb.smtp_encryption as string)" :options="[{ label: 'STARTTLS', value: 'tls' }, { label: 'SSL', value: 'ssl' }]" /></label>
        </div>
        <div class="grid2">
          <label>{{ t('用户名（留空 = 发件邮箱）') }}<NInput v-model:value="(mb.smtp_username as string)" /></label>
          <label>{{ t('密码 / 应用专用密码') }}<NInput v-model:value="(mb.smtp_password as string)" type="password" show-password-on="click" :placeholder="mailbox?.smtp_password_set ? t('已保存（留空则不修改）') : ''" /></label>
        </div>
        <div class="test"><NButton size="small" :loading="testing === 'smtp'" @click="test('smtp')">{{ t('测试发信') }}</NButton>
          <span v-if="testResult.smtp" :class="testResult.smtp.ok ? 'ok' : 'bad'">{{ testResult.smtp.message }}</span></div>

        <div class="sec">{{ t('收信服务器（IMAP，用来自动识别回复和退信）') }}</div>
        <div class="grid3">
          <label>{{ t('服务器') }}<NInput v-model:value="(mb.imap_host as string)" placeholder="imap.gmail.com" /></label>
          <label>{{ t('端口') }}<NSelect v-model:value="(mb.imap_port as number)" :options="[993, 143].map((p) => ({ label: String(p), value: p }))" /></label>
          <label>{{ t('加密') }}<NSelect v-model:value="(mb.imap_encryption as string)" :options="[{ label: 'SSL', value: 'ssl' }, { label: 'STARTTLS', value: 'tls' }, { label: t('不加密'), value: 'none' }]" /></label>
        </div>
        <div class="grid2">
          <label>{{ t('用户名（留空 = 发件邮箱）') }}<NInput v-model:value="(mb.imap_username as string)" /></label>
          <label>{{ t('密码（留空 = 和发信相同）') }}<NInput v-model:value="(mb.imap_password as string)" type="password" show-password-on="click" :placeholder="mailbox?.imap_password_set ? t('已保存（留空则不修改）') : ''" /></label>
        </div>
        <div class="test"><NButton size="small" :loading="testing === 'imap'" :disabled="!(mb.imap_host as string)" @click="test('imap')">{{ t('测试收信') }}</NButton>
          <span v-if="testResult.imap" :class="testResult.imap.ok ? 'ok' : 'bad'">{{ testResult.imap.message }}</span></div>
        <p class="muted">{{ t('Gmail、Outlook 需要先开启两步验证，再生成“应用专用密码”填在这里；企业邮箱（腾讯、阿里、Zoho 等）在邮箱设置里开启 SMTP / IMAP。') }}</p>

        <div class="sec">{{ t('发送规则') }}</div>
        <div class="grid4">
          <label>{{ t('每天最多') }}<NInputNumber v-model:value="(mb.daily_limit as number)" :min="1" :max="300" /></label>
          <label>{{ t('间隔（分钟）') }}<NInputNumber v-model:value="(mb.gap_minutes as number)" :min="1" :max="120" /></label>
          <label>{{ t('对方当地从') }}<NSelect v-model:value="(mb.window_start as number)" :options="hours.slice(0, 24)" /></label>
          <label>{{ t('到') }}<NSelect v-model:value="(mb.window_end as number)" :options="hours.slice(1)" /></label>
        </div>
        <label>{{ t('签名') }}<NInput v-model:value="(mb.signature as string)" type="textarea" :autosize="{ minRows: 2, maxRows: 5 }" placeholder="Fiona Wang&#10;Sales Manager · Shenzhen XX Technology&#10;WhatsApp +86 138 0000 0000" /></label>
        <label>{{ t('公司地址（必填：法律要求营销邮件写明发件人的实际地址）') }}<NInput v-model:value="(mb.address as string)" placeholder="No. 1 Industrial Rd, Bao'an, Shenzhen, China" /></label>
        <NCheckbox v-model:checked="(mb.skip_consent_countries as boolean)">{{ t('跳过德国、奥地利的收件人（当地法律要求 B2B 也要事先同意才能发营销邮件）') }}</NCheckbox>
      </div>
      <template #footer><div class="foot"><NButton @click="mbOpen = false">{{ t('取消') }}</NButton><NButton type="primary" :loading="mbSaving" @click="saveMailbox()">{{ t('保存') }}</NButton></div></template>
    </NModal>

    <!-- Sequence editor -->
    <NModal v-model:show="editOpen" preset="card" :title="form.id ? t('编辑序列') : t('新建序列')" style="max-width: 720px">
      <div class="form">
        <div class="grid2">
          <label>{{ t('名称') }}<NInput v-model:value="form.name" maxlength="120" :placeholder="t('例如：墨西哥手机配件批发商')" /></label>
          <label>{{ t('发给') }}
            <NRadioGroup :value="form.audience" :disabled="!!form.id" @update:value="setAudience"><NRadio value="leads">{{ t('B 端客户（获客雷达）') }}</NRadio><NRadio value="creators">{{ t('达人') }}</NRadio></NRadioGroup>
          </label>
        </div>
        <div class="toggle">
          <NSwitch v-model:value="form.ai_first" :disabled="!aiReady && form.audience === 'leads'" />
          <span>{{ form.audience === 'leads' ? t('第一封由 AI 按每家公司的官网，用对方的语言写（需要 Claude Key）；跟进邮件也会翻译成对方的语言。') : t('第一封用「达人合作」里的合作邀请（有 Claude Key 时由 AI 写，含推广码和佣金）。') }}</span>
        </div>
        <div v-for="(st, i) in form.steps" :key="i" class="step">
          <div class="step-head">
            <b>{{ i === 0 ? t('第 1 封') : t('第 {0} 封 · 上一封之后', [i + 1]) }}</b>
            <template v-if="i > 0"><NInputNumber v-model:value="st.delay_days" size="small" :min="1" :max="60" style="width: 90px" /> {{ t('天') }}</template>
            <span class="grow" />
            <NButton v-if="i > 0" size="tiny" quaternary @click="form.steps.splice(i, 1)">{{ t('删除') }}</NButton>
          </div>
          <template v-if="i === 0 && form.ai_first"><p class="muted">{{ t('AI 写不了时（没有官网信息、AI 出错）用下面的内容。') }}</p></template>
          <NInput v-if="i === 0" v-model:value="st.subject" size="small" :placeholder="t('标题，例如：Screen protectors for {{company}}')" maxlength="200" />
          <NInput v-else v-model:value="st.subject" size="small" :placeholder="t('标题留空 = 作为回复发在同一封邮件里（Re: …），推荐')" maxlength="200" />
          <NInput v-model:value="st.body" type="textarea" :autosize="{ minRows: 3, maxRows: 10 }" maxlength="5000" />
        </div>
        <NButton v-if="form.steps.length < 5" size="small" dashed @click="form.steps.push({ delay_days: 4, subject: '', body: '' })">{{ t('添加跟进邮件') }}</NButton>
        <p class="muted">{{ variablesHelp }}</p>

        <div class="sec">{{ t('自动加入') }}</div>
        <div class="toggle"><NSwitch v-model:value="form.auto.enabled" /><span>{{ form.audience === 'leads' ? t('获客雷达每次搜索完成后，把符合条件、有公开邮箱的新线索自动加入这个序列') : t('每次找达人后，把符合条件、有邮箱的达人自动加入这个序列') }}</span></div>
        <div v-if="form.auto.enabled" class="grid2">
          <label>{{ t('评分至少') }}<NInputNumber v-model:value="form.auto.min_score" :min="0" :max="100" /></label>
          <label v-if="form.audience === 'leads'">{{ t('客户类型（不选 = 全部）') }}<NSelect v-model:value="form.auto.segments" multiple :options="SEGMENT_OPTIONS" /></label>
        </div>
      </div>
      <template #footer><div class="foot"><NButton @click="editOpen = false">{{ t('取消') }}</NButton><NButton type="primary" :loading="saving" :disabled="!form.name.trim()" @click="save">{{ t('保存') }}</NButton></div></template>
    </NModal>

    <!-- Recipients -->
    <NDrawer :show="!!drawer" width="min(720px, 100vw)" placement="right" @update:show="(v: boolean) => { if (!v) drawer = null }">
      <NDrawerContent v-if="drawer" :title="drawer.name" closable>
        <div class="filters">
          <NSelect v-model:value="pStatus" size="small" clearable :placeholder="t('全部状态')" :options="statusOptions" style="width: 150px" @update:value="openPeople(drawer!)" />
          <span class="muted">{{ t('共 {0} 人', [pMeta.total]) }}</span>
        </div>
        <div v-if="pLoading" class="center"><NSpin size="small" /></div>
        <div v-for="e in people" :key="e.id" class="person">
          <div class="pmain">
            <div><b>{{ e.name || e.email }}</b> <span class="muted">{{ e.email }}</span></div>
            <div class="muted">
              <NTag size="tiny" :bordered="false" :type="e.status === 'replied' ? 'success' : e.status === 'bounced' || e.status === 'failed' ? 'error' : 'default'">{{ STATUS[e.status] ?? e.status }}</NTag>
              {{ t('已发 {0} / {1} 封', [e.step, drawer.steps.length]) }}
              <template v-if="e.status === 'active' && e.next_at"> · {{ t('下一封 {0}', [formatDate(e.next_at)]) }}</template>
              <template v-if="e.error"> · <span class="bad">{{ e.error }}</span></template>
            </div>
          </div>
          <div class="btns">
            <NButton size="tiny" quaternary :loading="previewing === e.id" @click="showPreview(e)">{{ t('预览邮件') }}</NButton>
            <NButton v-if="e.status === 'active'" size="tiny" quaternary @click="stop(e)">{{ t('停止') }}</NButton>
          </div>
        </div>
        <NEmpty v-if="!pLoading && !people.length" :description="t('还没有收件人')" />
        <div v-if="pMeta.last_page > 1" class="pager"><NPagination :page="pMeta.page" :page-count="pMeta.last_page" @update:page="(p: number) => openPeople(drawer!, p)" /></div>
      </NDrawerContent>
    </NDrawer>

    <NModal :show="!!preview" preset="card" :title="t('{0} 会收到的邮件', [preview?.who.name || preview?.who.email || ''])" style="max-width: 680px" @update:show="(v: boolean) => { if (!v) preview = null }">
      <div v-for="m in preview?.emails ?? []" :key="m.step" class="pv">
        <div class="pv-head"><b>{{ t('第 {0} 封', [m.step + 1]) }}</b> <span class="muted">{{ m.sent ? t('已发送 {0}', [formatDate(m.sent)]) : m.step ? t('上一封之后 {0} 天', [m.delay_days]) : '' }}</span></div>
        <div class="pv-subject">{{ m.subject }}</div>
        <pre class="pv-body">{{ m.body }}</pre>
      </div>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; max-width: 720px; }
.center { display: flex; justify-content: center; padding: 32px; }
.muted { color: var(--ink-muted); font-size: 12px; line-height: 1.6; }
.mb { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.mb-title { font-weight: 600; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 4px; }
.row { display: flex; gap: 12px; padding: 12px 16px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.row:first-child { border-top: 0; }
.main { flex: 1; min-width: 260px; display: grid; gap: 4px; }
.name { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.link { background: none; border: 0; padding: 0; cursor: pointer; color: var(--brand-700); font-weight: 600; font-size: 14px; }
.btns { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
.form { display: grid; gap: 12px; }
.form label:not(.n-radio):not(.n-checkbox) { display: grid; gap: 4px; font-size: 13px; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.grid3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 12px; }
.grid4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.sec { font-size: 12px; font-weight: 600; color: var(--ink-muted); margin-top: 6px; }
.test { display: flex; gap: 8px; align-items: center; font-size: 12px; flex-wrap: wrap; }
.ok { color: var(--brand-700); }
.bad { color: var(--danger); }
.toggle { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; line-height: 1.6; }
.step { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; display: grid; gap: 8px; }
.step-head { display: flex; gap: 8px; align-items: center; font-size: 13px; }
.step p { margin: 0; }
.grow { flex: 1; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
.filters { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.person { display: flex; gap: 8px; padding: 10px 0; border-top: 1px solid var(--border); flex-wrap: wrap; }
.pmain { flex: 1; min-width: 220px; display: grid; gap: 4px; font-size: 13px; }
.pager { display: flex; justify-content: center; padding: 12px; }
.pv { border-top: 1px solid var(--border); padding: 12px 0; }
.pv:first-child { border-top: 0; padding-top: 0; }
.pv-head { display: flex; gap: 8px; align-items: baseline; font-size: 13px; }
.pv-subject { font-weight: 600; margin: 6px 0; }
.pv-body { white-space: pre-wrap; font-family: inherit; font-size: 13px; line-height: 1.6; margin: 0; background: var(--surface-muted); border-radius: 8px; padding: 10px 12px; }
@media (max-width: 640px) { .grid2, .grid3, .grid4 { grid-template-columns: 1fr; } }
</style>
