<script setup lang="ts">
/** Products › Gift cards: issue (code shown once), email to the recipient, see usage, adjust or disable. */
import { h, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  NAlert, NButton, NCard, NCheckbox, NDataTable, NDatePicker, NDrawer, NDrawerContent, NForm, NFormItem, NInput, NInputNumber,
  NModal, NSwitch, NTabs, NTab, NTag, useMessage, type DataTableColumns,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

interface Card {
  id: number; masked: string; last4: string; status: 'active' | 'used_up' | 'expired' | 'disabled'; initial_value: string; balance: string
  currency: string; recipient_email: string | null; recipient_name: string | null; expires_on: string | null; sent_at: string | null; created_at: string
  message?: string | null; note?: string | null
  transactions?: { id: number; kind: string; amount: string; balance_after: string; order: { id: number; name: string } | null; user: string | null; note: string | null; created_at: string }[]
}

const auth = useAuth()
const router = useRouter()
const message = useMessage()
const currency = auth.currentStore?.currency ?? ''
const rows = ref<Card[]>([])
const summary = ref({ count: 0, outstanding: '0.00' })
const loading = ref(false)
const q = ref('')
const status = ref('all')
const pagination = ref({ page: 1, pageSize: 20, itemCount: 0 })

const statusTag: Record<Card['status'], { label: string; type: 'success' | 'default' | 'warning' }> = {
  active: { label: t('可用'), type: 'success' }, used_up: { label: t('已用完'), type: 'default' },
  expired: { label: t('已过期'), type: 'warning' }, disabled: { label: t('已停用'), type: 'default' },
}
const columns: DataTableColumns<Card> = [
  { title: t('代码'), key: 'masked', width: 120, render: (r) => h('code', r.masked) },
  { title: t('状态'), key: 'status', width: 90, render: (r) => h(NTag, { size: 'small', bordered: false, type: statusTag[r.status].type }, { default: () => statusTag[r.status].label }) },
  { title: t('收件人'), key: 'recipient_email', minWidth: 200, render: (r) => r.recipient_email ? h('div', [h('div', r.recipient_name || r.recipient_email), r.recipient_name ? h('div', { class: 'muted' }, r.recipient_email) : null]) : '—' },
  { title: t('余额'), key: 'balance', width: 150, align: 'right', render: (r) => h('div', [h('div', `${r.currency} ${r.balance}`), h('div', { class: 'muted' }, t('面值 {0}', [r.initial_value]))]) },
  { title: t('有效期至'), key: 'expires_on', width: 120, render: (r) => r.expires_on ?? t('长期有效') },
  { title: t('创建时间'), key: 'created_at', width: 160, render: (r) => formatDate(r.created_at) },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/gift-cards', { params: { q: q.value || undefined, status: status.value === 'all' ? undefined : status.value, page: pagination.value.page } })
    rows.value = data.data
    summary.value = data.summary
    pagination.value.itemCount = data.total
  } finally { loading.value = false }
}
let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => { pagination.value.page = 1; load() }, 300) })
watch(status, () => { pagination.value.page = 1; load() })
onMounted(load)

// ---- Create ----
const showNew = ref(false)
const form = reactive({ value: 50 as number | null, custom: false, code: '', recipient_email: '', recipient_name: '', message: '', note: '', expires: null as number | null, send: true })
const created = ref<Card & { code: string } | null>(null)
const saving = ref(false)
function openNew() {
  Object.assign(form, { value: 50, custom: false, code: '', recipient_email: '', recipient_name: '', message: '', note: '', expires: null, send: true })
  created.value = null
  showNew.value = true
}
async function create() {
  saving.value = true
  try {
    const d = form.expires ? new Date(form.expires) : null
    const { data } = await http.post('/gift-cards', {
      value: form.value, code: form.custom ? form.code : undefined,
      recipient_email: form.recipient_email || undefined, recipient_name: form.recipient_name || undefined,
      message: form.message || undefined, note: form.note || undefined,
      expires_on: d ? `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` : undefined,
      send: !!form.recipient_email && form.send,
    })
    created.value = data.data
    load()
  } catch (e) { message.error(errorMessage(e)) } finally { saving.value = false }
}
function copy(text: string) { navigator.clipboard?.writeText(text).then(() => message.success(t('已复制'))) }

// ---- Detail ----
const detail = ref<Card | null>(null)
const newBalance = ref<number | null>(null)
const reason = ref('')
async function openDetail(r: Card) {
  detail.value = (await http.get(`/gift-cards/${r.id}`)).data.data
  newBalance.value = Number(detail.value!.balance)
  reason.value = ''
}
async function patch(body: Record<string, unknown>, ok = t('已保存')) {
  try {
    detail.value = (await http.patch(`/gift-cards/${detail.value!.id}`, body)).data.data
    newBalance.value = Number(detail.value!.balance)
    message.success(ok)
    load()
  } catch (e) { message.error(errorMessage(e)) }
}
async function resend() {
  try { await http.post(`/gift-cards/${detail.value!.id}/resend`); message.success(t('邮件已发送')) } catch (e) { message.error(errorMessage(e)) }
}
const kindLabel: Record<string, string> = { issued: t('发放'), spent: t('消费'), restored: t('订单取消退回'), adjusted: t('手动调整') }
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <div class="eyebrow">Products</div>
        <h1 class="page-title">{{ t('礼品卡') }}</h1>
        <p class="page-sub">{{ t('发放礼品卡给顾客或用作售后补偿。顾客在购物车或结账时输入代码即可抵扣，余额可以分多次使用。') }}</p>
      </div>
      <NButton type="primary" @click="openNew">{{ t('发放礼品卡') }}</NButton>
    </div>

    <div class="stats">
      <div class="stat"><div class="label">{{ t('未停用的礼品卡') }}</div><div class="value">{{ summary.count }}</div></div>
      <div class="stat"><div class="label">{{ t('未使用余额') }}</div><div class="value">{{ currency }} {{ summary.outstanding }}</div></div>
    </div>

    <NCard content-style="padding: 0">
      <div class="toolbar">
        <NTabs v-model:value="status" type="line" size="small" style="flex: 1">
          <NTab name="all">{{ t('全部') }}</NTab><NTab name="active">{{ t('可用') }}</NTab><NTab name="used_up">{{ t('已用完') }}</NTab>
          <NTab name="expired">{{ t('已过期') }}</NTab><NTab name="disabled">{{ t('已停用') }}</NTab>
        </NTabs>
        <NInput v-model:value="q" :placeholder="t('代码后 4 位或邮箱')" clearable size="small" style="width: 200px" />
      </div>
      <NDataTable remote :columns="columns" :data="rows" :loading="loading" :bordered="false" :pagination="pagination" :scroll-x="840"
        :row-props="(r: Card) => ({ style: 'cursor:pointer', onClick: () => openDetail(r) })" @update:page="(p: number) => { pagination.page = p; load() }" />
    </NCard>

    <!-- Create -->
    <NModal v-model:show="showNew" preset="card" :title="t('发放礼品卡')" style="width: 520px; max-width: 94vw">
      <template v-if="!created">
        <NForm label-placement="top" @submit.prevent="create">
          <NFormItem :label="t('面值')"><NInputNumber v-model:value="form.value" :min="1" :max="100000" :precision="2" style="width: 100%"><template #prefix>{{ currency }}</template></NInputNumber></NFormItem>
          <NCheckbox v-model:checked="form.custom">{{ t('自定义代码（默认随机生成 16 位）') }}</NCheckbox>
          <NFormItem v-if="form.custom" :show-label="false" style="margin-top: 8px"><NInput v-model:value="form.code" maxlength="32" placeholder="SPRING2026GIFT" /></NFormItem>
          <div class="two">
            <NFormItem :label="t('收件人邮箱（可选）')"><NInput v-model:value="form.recipient_email" placeholder="friend@example.com" /></NFormItem>
            <NFormItem :label="t('收件人姓名')"><NInput v-model:value="form.recipient_name" /></NFormItem>
          </div>
          <NFormItem :label="t('给收件人的话（会写在邮件里）')"><NInput v-model:value="form.message" type="textarea" maxlength="500" :autosize="{ minRows: 2 }" /></NFormItem>
          <div class="two">
            <NFormItem :label="t('有效期至（可选）')"><NDatePicker v-model:value="form.expires" type="date" clearable :is-date-disabled="(ts: number) => ts < Date.now()" style="width: 100%" /></NFormItem>
            <NFormItem :label="t('内部备注')"><NInput v-model:value="form.note" maxlength="500" :placeholder="t('例如：售后补偿 #1004')" /></NFormItem>
          </div>
          <NCheckbox v-if="form.recipient_email" v-model:checked="form.send">{{ t('立即发送礼品卡邮件给收件人') }}</NCheckbox>
          <div class="modal-actions">
            <NButton @click="showNew = false">{{ t('取消') }}</NButton>
            <NButton type="primary" attr-type="submit" :loading="saving" :disabled="!form.value">{{ t('发放') }}</NButton>
          </div>
        </NForm>
      </template>
      <template v-else>
        <NAlert type="success" :bordered="false">{{ created.sent_at ? t('礼品卡已发放，并已发邮件给 {0}。', [created.recipient_email]) : t('礼品卡已发放。') }}</NAlert>
        <div class="code-box">
          <div class="muted">{{ t('礼品卡代码（只显示这一次，请复制保存）') }}</div>
          <code class="big">{{ created.code }}</code>
          <div>{{ created.currency }} {{ created.balance }}</div>
        </div>
        <div class="modal-actions">
          <NButton @click="copy(created.code)">{{ t('复制代码') }}</NButton>
          <NButton type="primary" @click="showNew = false">{{ t('完成') }}</NButton>
        </div>
      </template>
    </NModal>

    <!-- Detail -->
    <NDrawer :show="!!detail" :width="460" @update:show="(v: boolean) => !v && (detail = null)">
      <NDrawerContent v-if="detail" :title="t('礼品卡 {0}', [detail.masked])" closable>
        <div class="d-head">
          <NTag :type="statusTag[detail.status].type" :bordered="false">{{ statusTag[detail.status].label }}</NTag>
          <div class="d-balance">{{ detail.currency }} {{ detail.balance }}</div>
          <div class="muted">{{ t('面值 {0} · 创建于 {1}', [detail.initial_value, formatDate(detail.created_at)]) }}</div>
        </div>
        <div class="d-row"><span>{{ t('启用') }}</span><NSwitch :value="detail.status !== 'disabled'" @update:value="(v: boolean) => patch({ disabled: !v })" /></div>
        <div v-if="detail.recipient_email" class="d-row">
          <span>{{ detail.recipient_email }}<br><span class="muted">{{ detail.sent_at ? t('{0} 已发送', [formatDate(detail.sent_at)]) : t('还没有发送') }}</span></span>
          <NButton size="small" :disabled="detail.status !== 'active'" @click="resend">{{ detail.sent_at ? t('重新发送') : t('发送') }}</NButton>
        </div>
        <div class="d-sec">{{ t('调整余额') }}</div>
        <div class="adjust">
          <NInputNumber v-model:value="newBalance" :min="0" :precision="2" size="small" style="width: 130px" />
          <NInput v-model:value="reason" size="small" :placeholder="t('原因')" maxlength="200" />
          <NButton size="small" :disabled="newBalance === null || newBalance === Number(detail.balance)" @click="patch({ balance: newBalance, reason: reason || undefined }, t('余额已调整'))">{{ t('保存') }}</NButton>
        </div>
        <div v-if="detail.message" class="d-sec">{{ t('给收件人的话') }}</div>
        <p v-if="detail.message" class="msg">{{ detail.message }}</p>
        <div class="d-sec">{{ t('使用记录') }}</div>
        <div v-for="x in detail.transactions" :key="x.id" class="tx">
          <div>
            <div>{{ kindLabel[x.kind] ?? x.kind }}
              <a v-if="x.order" @click="router.push({ name: 'order', params: { id: x.order.id } })">{{ x.order.name }}</a></div>
            <div class="muted">{{ formatDate(x.created_at) }}{{ x.user ? ` · ${x.user}` : '' }}{{ x.note ? ` · ${x.note}` : '' }}</div>
          </div>
          <div class="num"><div :class="x.amount.startsWith('-') ? '' : 'up'">{{ x.amount.startsWith('-') ? '' : '+' }}{{ x.amount }}</div><div class="muted">{{ t('余额 {0}', [x.balance_after]) }}</div></div>
        </div>
      </NDrawerContent>
    </NDrawer>
  </div>
</template>

<style scoped>
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.stats { display: grid; grid-template-columns: repeat(2, minmax(0, 220px)); gap: 12px; margin-bottom: 16px; }
.stat { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 12px 16px; }
.label { font-size: 12px; color: var(--ink-muted); }
.value { font-size: 20px; font-weight: 600; margin-top: 2px; font-variant-numeric: tabular-nums; }
.toolbar { display: flex; align-items: center; gap: 16px; padding: 8px 16px 0; }
:deep(.muted), .muted { color: var(--ink-muted); font-size: 12px; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.code-box { text-align: center; margin-top: 16px; padding: 20px; border: 1px dashed var(--brand-300); border-radius: 12px; background: var(--brand-50); display: grid; gap: 8px; }
.big { font-size: 22px; font-weight: 700; letter-spacing: 2px; color: var(--brand-800); }
.d-head { display: grid; gap: 4px; justify-items: start; margin-bottom: 16px; }
.d-balance { font-size: 26px; font-weight: 700; font-variant-numeric: tabular-nums; }
.d-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 0; border-top: 1px solid var(--border); font-size: 14px; }
.d-sec { font-size: 12px; font-weight: 600; color: var(--ink-muted); text-transform: uppercase; letter-spacing: .04em; margin: 18px 0 8px; }
.adjust { display: flex; gap: 6px; }
.msg { font-size: 13px; white-space: pre-line; margin: 0; }
.tx { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.tx a { color: var(--brand-700); cursor: pointer; margin-left: 4px; }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.up { color: var(--brand-700); }
</style>
