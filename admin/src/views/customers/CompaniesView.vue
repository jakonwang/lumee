<script setup lang="ts">
/**
 * Customers › Companies: business buyers (people, locations, payment terms, tax exemption, invoices),
 * wholesale applications, and the store's B2B settings.
 */
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  NAlert, NButton, NCard, NCheckbox, NDataTable, NDrawer, NDrawerContent, NEmpty, NForm, NFormItem, NInput, NInputNumber, NModal,
  NSelect, NSwitch, NTabs, NTab, NTag, useDialog, useMessage, type DataTableColumns,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

interface Location { name?: string; first_name?: string; last_name?: string; address1: string; address2?: string; city: string; province?: string; zip?: string; country: string; phone?: string }
interface Company {
  id: number; name: string; tax_id: string | null; tag: string; payment_terms: number | null; tax_exempt: boolean; locations_count: number
  customers_count?: number; open_invoices?: number; overdue?: number; outstanding?: string; note?: string | null; locations?: Location[]
  customers?: { id: number; email: string; name: string; role: string; has_account: boolean }[]
  orders?: { id: number; name: string; total: string; currency: string; financial_status: string; payment_gateway: string; payment_due_at: string | null; overdue: boolean; po_number: string | null; created_at: string }[]
}
interface Application { id: number; company_name: string; tax_id: string | null; contact_name: string; email: string; phone: string | null; website: string | null; country: string | null; message: string | null; status: string; created_at: string }

const route = useRoute()
const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const currency = useAuth().currentStore?.currency ?? ''
const tab = ref<'companies' | 'applications' | 'settings'>((route.query.tab as 'applications') || 'companies')

// ---- Companies ----
const rows = ref<Company[]>([])
const loading = ref(false)
const q = ref('')
const pending = ref(0)
const settings = reactive({ hide_prices: false, applications: false, tag: 'wholesale', terms: 0 })
const pagination = ref({ page: 1, pageSize: 20, itemCount: 0 })
const columns: DataTableColumns<Company> = [
  { title: t('公司'), key: 'name', minWidth: 200, render: (r) => h('div', [h('div', { style: 'font-weight:500' }, r.name), h('div', { class: 'muted' }, r.tax_id ? `${t('税号')} ${r.tax_id}` : '')]) },
  { title: t('采购人'), key: 'customers_count', width: 80 },
  { title: t('付款方式'), key: 'payment_terms', width: 120, render: (r) => (r.payment_terms ? t('账期 {0} 天', [r.payment_terms]) : t('下单时付款')) },
  { title: t('免税'), key: 'tax_exempt', width: 70, render: (r) => (r.tax_exempt ? t('是') : '—') },
  {
    title: t('未付账单'), key: 'outstanding', width: 160, align: 'right',
    render: (r) => (r.open_invoices ? h('div', [h('div', `${currency} ${r.outstanding}`), r.overdue ? h(NTag, { size: 'tiny', type: 'error', bordered: false }, { default: () => t('{0} 笔逾期', [r.overdue]) }) : null]) : '—'),
  },
]
async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/companies', { params: { q: q.value || undefined, page: pagination.value.page } })
    rows.value = data.data
    pagination.value.itemCount = data.total
    pending.value = data.pending_applications
    Object.assign(settings, data.settings)
  } finally { loading.value = false }
}
let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => { clearTimeout(timer); timer = setTimeout(load, 300) })

// ---- Company drawer ----
const open = ref(false)
const company = ref<Company | null>(null)
const form = reactive({ name: '', tax_id: '', tag: 'wholesale', payment_terms: 0, tax_exempt: false, note: '', locations: [] as Location[] })
const saving = ref(false)
async function openCompany(c?: Company) {
  if (c) {
    company.value = (await http.get(`/companies/${c.id}`)).data.data
    Object.assign(form, { name: company.value!.name, tax_id: company.value!.tax_id ?? '', tag: company.value!.tag, payment_terms: company.value!.payment_terms ?? 0,
      tax_exempt: company.value!.tax_exempt, note: company.value!.note ?? '', locations: (company.value!.locations ?? []).map((l) => ({ ...l })) })
  } else {
    company.value = null
    Object.assign(form, { name: '', tax_id: '', tag: settings.tag, payment_terms: settings.terms, tax_exempt: false, note: '', locations: [] })
  }
  open.value = true
}
async function saveCompany() {
  saving.value = true
  try {
    const body = { ...form, tax_id: form.tax_id || null }
    if (company.value) company.value = (await http.put(`/companies/${company.value.id}`, body)).data.data
    else company.value = (await http.get(`/companies/${(await http.post('/companies', body)).data.data.id}`)).data.data
    message.success(t('已保存'))
    load()
  } catch (e) { message.error(errorMessage(e)) } finally { saving.value = false }
}
function removeCompany() {
  dialog.warning({
    title: t('删除「{0}」？', [company.value!.name]), content: t('采购人的客户账户会保留，只是不再属于这家公司。'),
    positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => { await http.delete(`/companies/${company.value!.id}`); open.value = false; load() },
  })
}
const memberEmail = ref('')
const memberRole = ref('buyer')
async function addMember() {
  try {
    company.value = (await http.post(`/companies/${company.value!.id}/members`, { email: memberEmail.value, role: memberRole.value })).data.data
    memberEmail.value = ''
    load()
  } catch (e) { message.error(errorMessage(e)) }
}
async function removeMember(id: number) {
  company.value = (await http.delete(`/companies/${company.value!.id}/members/${id}`)).data.data
  load()
}
function addLocation() { form.locations.push({ name: '', address1: '', city: '', country: 'US' }) }

// ---- Applications ----
const apps = ref<Application[] | null>(null)
const appStatus = ref('pending')
async function loadApps() { apps.value = (await http.get('/b2b/applications', { params: { status: appStatus.value } })).data.data }
watch(appStatus, loadApps)
const approving = ref<Application | null>(null)
const approveForm = reactive({ payment_terms: 0, tax_exempt: false })
function openApprove(a: Application) { approving.value = a; Object.assign(approveForm, { payment_terms: settings.terms, tax_exempt: false }) }
async function approve() {
  try {
    await http.post(`/b2b/applications/${approving.value!.id}/approve`, approveForm)
    message.success(t('已批准，已发邮件通知对方'))
    approving.value = null
    loadApps(); load()
  } catch (e) { message.error(errorMessage(e)) }
}
const rejecting = ref<Application | null>(null)
const rejectReason = ref('')
async function reject() {
  await http.post(`/b2b/applications/${rejecting.value!.id}/reject`, { reason: rejectReason.value || undefined })
  rejecting.value = null
  rejectReason.value = ''
  loadApps(); load()
}

// ---- Settings ----
const applyUrl = ref('')
async function saveSettings() {
  try {
    const { data } = await http.put('/b2b/settings', settings)
    applyUrl.value = data.apply_url
    message.success(t('已保存'))
  } catch (e) { message.error(errorMessage(e)) }
}
const storefront = computed(() => useAuth().currentStore?.storefront_url ?? '')

watch(tab, (v) => { if (v === 'applications' && !apps.value) loadApps() })
onMounted(() => { load(); if (tab.value === 'applications') loadApps() })
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <div class="eyebrow">Customers · B2B</div>
        <h1 class="page-title">{{ t('公司') }}</h1>
        <p class="page-sub">{{ t('批发 / B2B 客户：一家公司可以有多个采购人和收货地点，可以设置账期付款和免税；公司标签决定适用的批发价格表。') }}</p>
      </div>
      <NButton v-if="tab === 'companies'" type="primary" @click="openCompany()">{{ t('新建公司') }}</NButton>
    </div>

    <NTabs v-model:value="tab" type="line" style="margin-bottom: 12px">
      <NTab name="companies">{{ t('公司') }}</NTab>
      <NTab name="applications">{{ t('批发申请') }}{{ pending ? ` (${pending})` : '' }}</NTab>
      <NTab name="settings">{{ t('B2B 设置') }}</NTab>
    </NTabs>

    <NCard v-if="tab === 'companies'" content-style="padding: 0">
      <div class="toolbar"><NInput v-model:value="q" :placeholder="t('搜索公司')" clearable size="small" style="width: 240px" /></div>
      <NDataTable remote :columns="columns" :data="rows" :loading="loading" :bordered="false" :pagination="pagination" :scroll-x="720"
        :row-props="(r: Company) => ({ style: 'cursor:pointer', onClick: () => openCompany(r) })" @update:page="(p: number) => { pagination.page = p; load() }" />
    </NCard>

    <template v-else-if="tab === 'applications'">
      <NCard content-style="padding: 0">
        <div class="toolbar">
          <NSelect v-model:value="appStatus" size="small" style="width: 140px"
            :options="[{ label: t('待审核'), value: 'pending' }, { label: t('已批准'), value: 'approved' }, { label: t('已拒绝'), value: 'rejected' }, { label: t('全部'), value: 'all' }]" />
          <span v-if="!settings.applications" class="muted">{{ t('前台申请表单未开启，可以在「B2B 设置」里开启。') }}</span>
        </div>
        <NEmpty v-if="apps && !apps.length" :description="t('没有申请')" style="padding: 32px 0" />
        <div v-for="a in apps ?? []" :key="a.id" class="app">
          <div class="app-main">
            <div class="app-name">{{ a.company_name }} <span class="muted">{{ a.tax_id ? `· ${t('税号')} ${a.tax_id}` : '' }}</span></div>
            <div class="muted">{{ a.contact_name }} · {{ a.email }}{{ a.phone ? ` · ${a.phone}` : '' }}{{ a.country ? ` · ${a.country}` : '' }}{{ a.website ? ` · ${a.website}` : '' }} · {{ formatDate(a.created_at) }}</div>
            <p v-if="a.message" class="msg">{{ a.message }}</p>
          </div>
          <div v-if="a.status === 'pending'" class="acts">
            <NButton size="small" @click="rejecting = a">{{ t('拒绝') }}</NButton>
            <NButton size="small" type="primary" @click="openApprove(a)">{{ t('批准') }}</NButton>
          </div>
          <NTag v-else size="small" :bordered="false" :type="a.status === 'approved' ? 'success' : 'default'">{{ a.status === 'approved' ? t('已批准') : t('已拒绝') }}</NTag>
        </div>
      </NCard>
    </template>

    <NCard v-else :title="t('B2B 设置')" size="small">
      <div class="form">
        <div class="row"><div><div>{{ t('开放批发申请') }}</div><div class="muted">{{ t('前台 /wholesale 页面显示申请表单，登录页会出现入口。') }}</div></div><NSwitch v-model:value="settings.applications" /></div>
        <div class="row"><div><div>{{ t('只对登录客户显示价格') }}</div><div class="muted">{{ t('适合纯批发店铺：游客看不到价格、不能下单。') }}</div></div><NSwitch v-model:value="settings.hide_prices" /></div>
        <div class="row"><div><div>{{ t('批准后的公司标签') }}</div><div class="muted">{{ t('和批发价格表里的客户标签对应。') }}</div></div><NInput v-model:value="settings.tag" style="width: 180px" /></div>
        <div class="row"><div><div>{{ t('批准后的默认账期') }}</div><div class="muted">{{ t('0 = 下单时付款') }}</div></div><NInputNumber v-model:value="settings.terms" :min="0" :max="365" style="width: 180px"><template #suffix>{{ t('天') }}</template></NInputNumber></div>
        <NAlert v-if="settings.applications" type="info" :bordered="false">{{ t('申请页面：{0}', [applyUrl || `${storefront}/wholesale`]) }}</NAlert>
        <div><NButton type="primary" @click="saveSettings">{{ t('保存') }}</NButton></div>
      </div>
    </NCard>

    <!-- Company drawer -->
    <NDrawer v-model:show="open" :width="620">
      <NDrawerContent :title="company ? company.name : t('新建公司')" closable>
        <NForm label-placement="top" @submit.prevent="saveCompany">
          <div class="two">
            <NFormItem :label="t('公司名称')"><NInput v-model:value="form.name" maxlength="150" /></NFormItem>
            <NFormItem :label="t('税号')"><NInput v-model:value="form.tax_id" maxlength="60" /></NFormItem>
          </div>
          <div class="two">
            <NFormItem :label="t('价格表标签')"><NInput v-model:value="form.tag" maxlength="40" /></NFormItem>
            <NFormItem :label="t('账期（天，0 = 下单时付款）')"><NInputNumber v-model:value="form.payment_terms" :min="0" :max="365" style="width: 100%" /></NFormItem>
          </div>
          <NCheckbox v-model:checked="form.tax_exempt">{{ t('免税（结账时不收税）') }}</NCheckbox>
          <NFormItem :label="t('内部备注')" style="margin-top: 12px"><NInput v-model:value="form.note" type="textarea" :autosize="{ minRows: 2 }" maxlength="5000" /></NFormItem>

          <div class="sec">{{ t('收货地点') }} <NButton size="tiny" quaternary @click="addLocation">+ {{ t('添加') }}</NButton></div>
          <div v-for="(l, i) in form.locations" :key="i" class="loc">
            <div class="loc-grid">
              <NInput v-model:value="l.name" size="small" :placeholder="t('地点名称，如：总仓')" />
              <NInput v-model:value="l.address1" size="small" :placeholder="t('地址')" />
              <NInput v-model:value="l.city" size="small" :placeholder="t('城市')" />
              <NInput v-model:value="l.province" size="small" :placeholder="t('州/省')" />
              <NInput v-model:value="l.zip" size="small" :placeholder="t('邮编')" />
              <NInput v-model:value="l.country" size="small" maxlength="2" :placeholder="t('国家代码，如 US')" />
            </div>
            <NButton size="tiny" quaternary type="error" @click="form.locations.splice(i, 1)">{{ t('删除') }}</NButton>
          </div>
          <div class="actions">
            <NButton v-if="company" quaternary type="error" @click="removeCompany">{{ t('删除公司') }}</NButton>
            <NButton type="primary" attr-type="submit" :loading="saving">{{ t('保存') }}</NButton>
          </div>
        </NForm>

        <template v-if="company">
          <div class="sec">{{ t('采购人') }}</div>
          <div v-for="m in company.customers" :key="m.id" class="member">
            <div><div>{{ m.name || m.email }}</div><div class="muted">{{ m.email }} · {{ m.role === 'admin' ? t('管理员') : t('采购员') }}{{ m.has_account ? '' : ` · ${t('还没有设置密码')}` }}</div></div>
            <NButton size="tiny" quaternary @click="removeMember(m.id)">{{ t('移除') }}</NButton>
          </div>
          <div class="add-member">
            <NInput v-model:value="memberEmail" size="small" :placeholder="t('采购人邮箱')" />
            <NSelect v-model:value="memberRole" size="small" style="width: 110px" :options="[{ label: t('采购员'), value: 'buyer' }, { label: t('管理员'), value: 'admin' }]" />
            <NButton size="small" :disabled="!memberEmail" @click="addMember">{{ t('添加') }}</NButton>
          </div>
          <div class="sec">{{ t('订单') }}</div>
          <NEmpty v-if="!company.orders?.length" :description="t('还没有订单')" size="small" />
          <div v-for="o in company.orders" :key="o.id" class="member link" @click="router.push({ name: 'order', params: { id: o.id } })">
            <div><div>{{ o.name }}{{ o.po_number ? ` · PO ${o.po_number}` : '' }}</div>
              <div class="muted">{{ formatDate(o.created_at) }}<template v-if="o.payment_gateway === 'terms' && o.financial_status === 'pending'"> · {{ t('到期 {0}', [formatDate(o.payment_due_at)]) }}</template></div></div>
            <div style="text-align: right">
              <div>{{ o.currency }} {{ o.total }}</div>
              <NTag v-if="o.overdue" size="tiny" type="error" :bordered="false">{{ t('逾期') }}</NTag>
              <NTag v-else-if="o.financial_status === 'pending'" size="tiny" type="warning" :bordered="false">{{ t('待付款') }}</NTag>
            </div>
          </div>
        </template>
      </NDrawerContent>
    </NDrawer>

    <NModal :show="!!approving" preset="card" :title="t('批准 {0}', [approving?.company_name])" style="width: 440px; max-width: 94vw" @update:show="(v: boolean) => !v && (approving = null)">
      <p class="muted">{{ t('会创建公司、把申请人设为管理员，并发邮件让对方设置密码登录。') }}</p>
      <NFormItem :label="t('账期（天，0 = 下单时付款）')"><NInputNumber v-model:value="approveForm.payment_terms" :min="0" :max="365" style="width: 100%" /></NFormItem>
      <NCheckbox v-model:checked="approveForm.tax_exempt">{{ t('免税') }}</NCheckbox>
      <div class="actions"><NButton @click="approving = null">{{ t('取消') }}</NButton><NButton type="primary" @click="approve">{{ t('批准') }}</NButton></div>
    </NModal>
    <NModal :show="!!rejecting" preset="card" :title="t('拒绝 {0}', [rejecting?.company_name])" style="width: 440px; max-width: 94vw" @update:show="(v: boolean) => !v && (rejecting = null)">
      <NInput v-model:value="rejectReason" type="textarea" :placeholder="t('原因（可选，会写在邮件里）')" maxlength="1000" />
      <div class="actions"><NButton @click="rejecting = null">{{ t('取消') }}</NButton><NButton type="error" @click="reject">{{ t('拒绝并通知') }}</NButton></div>
    </NModal>
  </div>
</template>

<style scoped>
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.toolbar { display: flex; gap: 12px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--border); }
:deep(.muted), .muted { color: var(--ink-muted); font-size: 12px; }
.app { display: flex; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--border); }
.app-name { font-weight: 600; }
.msg { margin: 8px 0 0; font-size: 13px; white-space: pre-line; }
.acts { display: flex; gap: 6px; align-items: flex-start; }
.form { display: grid; gap: 16px; }
.row { display: flex; justify-content: space-between; align-items: center; gap: 16px; font-size: 14px; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.sec { font-size: 12px; font-weight: 600; color: var(--ink-muted); text-transform: uppercase; letter-spacing: .04em; margin: 18px 0 8px; display: flex; align-items: center; gap: 6px; }
.loc { display: flex; gap: 6px; align-items: flex-start; padding: 8px 0; border-bottom: 1px solid var(--border); }
.loc-grid { flex: 1; display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.member { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 14px; }
.member.link { cursor: pointer; }
.member.link:hover { color: var(--brand-700); }
.add-member { display: flex; gap: 6px; margin-top: 8px; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
</style>
