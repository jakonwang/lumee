<script setup lang="ts">
/** Marketing › Affiliates: partners with a ?ref= link, commission rates, earnings and payouts. */
import { onMounted, reactive, ref } from 'vue'
import { NButton, NCard, NEmpty, NInput, NInputNumber, NModal, NSelect, NSpin, NTag, useDialog, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Stats { clicks: number; orders: number; sales: string; pending: string; payable: string; paid: string }
interface Affiliate { id: number; name: string; email: string; code: string; commission_rate: number; status: 'active' | 'paused'; note: string | null; link: string; portal_url: string; stats: Stats }
interface Commission { id: number; order_name: string | null; base_amount: string; amount: string; rate: number; status: string; created_at: string; paid_at: string | null; payout_reference: string | null }

const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const rows = ref<Affiliate[]>([])
const currency = ref('')
const settings = reactive({ cookie_days: 30, return_days: 30, default_rate: 10 })
const editOpen = ref(false)
const saving = ref(false)
const form = reactive({ id: null as number | null, name: '', email: '', code: '', commission_rate: 10, status: 'active' as 'active' | 'paused', note: '' })
const detail = ref<Affiliate | null>(null)
const commissions = ref<Commission[]>([])

function apply(d: { data: Affiliate[]; settings: typeof settings; currency: string }) {
  rows.value = d.data
  Object.assign(settings, d.settings)
  currency.value = d.currency
}

async function load() {
  try {
    apply((await http.get('/affiliates')).data)
  } finally {
    loading.value = false
  }
}

function edit(a?: Affiliate) {
  Object.assign(form, a
    ? { id: a.id, name: a.name, email: a.email, code: a.code, commission_rate: a.commission_rate, status: a.status, note: a.note ?? '' }
    : { id: null, name: '', email: '', code: '', commission_rate: settings.default_rate, status: 'active', note: '' })
  editOpen.value = true
}

async function save() {
  saving.value = true
  try {
    const body = { ...form, note: form.note || null }
    apply((form.id ? await http.put(`/affiliates/${form.id}`, body) : await http.post('/affiliates', body)).data)
    editOpen.value = false
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

async function saveSettings() {
  try {
    apply((await http.put('/affiliates/settings', settings)).data)
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  }
}

function pay(a: Affiliate) {
  const ref = { value: '' }
  dialog.info({
    title: t('结算 {0} 的佣金', [a.name]),
    content: t('将把已过退货期的佣金 {0} {1} 标记为已支付。请先通过转账或 PayPal 把钱付给推广员。', [currency.value, a.stats.payable]),
    positiveText: t('标记为已支付'), negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        apply((await http.post(`/affiliates/${a.id}/pay`, { reference: ref.value || null })).data)
        message.success(t('已结算'))
      } catch (e) {
        message.error(errorMessage(e))
      }
    },
  })
}

function remove(a: Affiliate) {
  dialog.warning({
    title: t('删除推广员「{0}」？', [a.name]), content: t('推广链接会失效，已有的佣金记录也会删除。'),
    positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        apply((await http.delete(`/affiliates/${a.id}`)).data)
      } catch (e) {
        message.error(errorMessage(e))
      }
    },
  })
}

async function openDetail(a: Affiliate) {
  detail.value = a
  commissions.value = (await http.get(`/affiliates/${a.id}/commissions`)).data.data
}

function copy(text: string) {
  navigator.clipboard?.writeText(text)
  message.success(t('已复制'))
}

const statusMeta: Record<string, { label: string; type: 'warning' | 'success' | 'default' }> = {
  pending: { label: t('待结算'), type: 'warning' }, paid: { label: t('已支付'), type: 'success' }, void: { label: t('已作废'), type: 'default' },
}
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Marketing</div>
        <h1 class="page-title">{{ t('联盟营销') }}</h1>
        <p class="page-sub">{{ t('给博主、达人或老顾客一个专属推广链接。通过链接来的订单付款后，推广员按比例获得佣金（按折扣后的商品金额计算，不含运费和税）。') }}</p>
      </div>
      <NButton type="primary" @click="edit()">{{ t('添加推广员') }}</NButton>
    </div>

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else>
      <NCard v-if="rows.length" size="small" content-style="padding: 0">
        <div v-for="a in rows" :key="a.id" class="row">
          <div class="main">
            <div><strong>{{ a.name }}</strong> <span class="muted">{{ a.email }}</span>
              <NTag v-if="a.status === 'paused'" size="tiny" :bordered="false">{{ t('已暂停') }}</NTag></div>
            <div class="muted">
              <code>?ref={{ a.code }}</code> · {{ a.commission_rate }}% ·
              {{ t('{0} 次点击 · {1} 单 · 销售额 {2} {3}', [a.stats.clicks, a.stats.orders, currency, a.stats.sales]) }}
            </div>
            <div class="money">
              <span>{{ t('待结算') }} {{ currency }} {{ a.stats.pending }}</span>
              <span>{{ t('可结算') }} <b>{{ currency }} {{ a.stats.payable }}</b></span>
              <span>{{ t('已支付') }} {{ currency }} {{ a.stats.paid }}</span>
            </div>
          </div>
          <div class="btns">
            <NButton size="small" quaternary @click="copy(a.link)">{{ t('复制链接') }}</NButton>
            <NButton size="small" quaternary @click="copy(a.portal_url)">{{ t('复制推广员后台') }}</NButton>
            <NButton size="small" quaternary @click="openDetail(a)">{{ t('佣金明细') }}</NButton>
            <NButton size="small" :disabled="Number(a.stats.payable) <= 0" @click="pay(a)">{{ t('结算') }}</NButton>
            <NButton size="small" quaternary @click="edit(a)">{{ t('编辑') }}</NButton>
            <NButton size="small" quaternary type="error" @click="remove(a)">{{ t('删除') }}</NButton>
          </div>
        </div>
      </NCard>
      <NEmpty v-else :description="t('还没有推广员')" style="padding: 32px 0" />

      <NCard size="small" :title="t('计划设置')">
        <div class="settings">
          <label>{{ t('链接有效期（天）') }}<NInputNumber v-model:value="settings.cookie_days" :min="1" :max="365" /></label>
          <label>{{ t('退货期（天，过后才能结算）') }}<NInputNumber v-model:value="settings.return_days" :min="0" :max="180" /></label>
          <label>{{ t('默认佣金比例（%）') }}<NInputNumber v-model:value="settings.default_rate" :min="0" :max="90" :precision="2" /></label>
        </div>
        <p class="muted">{{ t('买家点推广链接后，在有效期内下单都算这个推广员的（以最后一次点击为准）。推广员自己下单不算佣金。退款会按比例减少佣金，取消订单会作废佣金。') }}</p>
        <div class="foot"><NButton type="primary" size="small" @click="saveSettings">{{ t('保存') }}</NButton></div>
      </NCard>
    </template>

    <NModal v-model:show="editOpen" preset="card" :title="form.id ? t('编辑推广员') : t('添加推广员')" style="max-width: 520px">
      <div class="form">
        <label>{{ t('名称') }}<NInput v-model:value="form.name" maxlength="100" /></label>
        <label>{{ t('邮箱') }}<NInput v-model:value="form.email" maxlength="255" /></label>
        <label>{{ t('推广码（链接里的 ?ref=…）') }}<NInput v-model:value="form.code" maxlength="40" placeholder="anna" /></label>
        <label>{{ t('佣金比例（%）') }}<NInputNumber v-model:value="form.commission_rate" :min="0" :max="90" :precision="2" /></label>
        <label>{{ t('状态') }}<NSelect v-model:value="form.status" :options="[{ value: 'active', label: t('启用') }, { value: 'paused', label: t('已暂停') }]" /></label>
        <label>{{ t('备注（选填）') }}<NInput v-model:value="form.note" maxlength="300" /></label>
      </div>
      <template #footer><div class="foot"><NButton @click="editOpen = false">{{ t('取消') }}</NButton><NButton type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton></div></template>
    </NModal>

    <NModal :show="!!detail" preset="card" :title="t('{0} 的佣金明细', [detail?.name ?? ''])" style="max-width: 680px" @update:show="(v: boolean) => { if (!v) detail = null }">
      <div v-for="c in commissions" :key="c.id" class="crow">
        <span>{{ c.order_name }}</span>
        <span class="muted">{{ formatDate(c.created_at) }}</span>
        <span>{{ currency }} {{ c.base_amount }} × {{ c.rate }}%</span>
        <b>{{ currency }} {{ c.amount }}</b>
        <NTag size="tiny" :bordered="false" :type="statusMeta[c.status]?.type">{{ statusMeta[c.status]?.label ?? c.status }}</NTag>
      </div>
      <NEmpty v-if="!commissions.length" :description="t('还没有佣金')" />
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; max-width: 680px; }
.center { display: flex; justify-content: center; padding: 48px; }
.row { display: flex; gap: 12px; padding: 12px 16px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.row:first-child { border-top: 0; }
.main { flex: 1; min-width: 260px; display: grid; gap: 4px; }
.muted { color: var(--ink-muted); font-size: 12px; }
.money { display: flex; gap: 14px; font-size: 13px; flex-wrap: wrap; }
.btns { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
.settings { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.settings label, .form label { display: grid; gap: 4px; font-size: 13px; }
.form { display: grid; gap: 12px; }
.foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
.crow { display: grid; grid-template-columns: 90px 110px 1fr 100px 70px; gap: 8px; padding: 8px 0; border-top: 1px solid var(--border); font-size: 13px; align-items: center; }
@media (max-width: 720px) { .settings { grid-template-columns: 1fr; } .crow { grid-template-columns: 1fr 1fr; } }
</style>
