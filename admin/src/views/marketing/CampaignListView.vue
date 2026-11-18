<script setup lang="ts">
import { computed, h, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NCard, NDataTable, NButton, NTag, NEmpty, NModal, NSwitch, NInput, NInputNumber, NForm, NFormItem, useMessage, type DataTableColumns } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Row {
  id: number
  subject: string
  audience_label: string
  status: 'draft' | 'scheduled' | 'sending' | 'sent'
  scheduled_at: string | null
  sent_at: string | null
  sent_count: number
  open_rate: number
  click_rate: number
  updated_at: string
}
interface Audience { key: string; label: string; count: number }

const router = useRouter()
const message = useMessage()
const rows = ref<Row[]>([])
const audiences = ref<Audience[]>([])
const loading = ref(true)
const popupOpen = ref(false)
const savingPopup = ref(false)
const popup = reactive({ enabled: false, heading: '', text: '', button: '', discount_code: null as string | null, delay: 5 })

const subscribers = computed(() => audiences.value.find((a) => a.key === 'subscribers')?.count ?? 0)
const statusMeta: Record<Row['status'], { label: string; type: 'default' | 'info' | 'warning' | 'success' }> = {
  draft: { label: t('草稿'), type: 'default' },
  scheduled: { label: t('已定时'), type: 'info' },
  sending: { label: t('发送中'), type: 'warning' },
  sent: { label: t('已发送'), type: 'success' },
}

const columns: DataTableColumns<Row> = [
  { title: t('邮件'), key: 'subject', minWidth: 260, render: (r) => h('div', [h('div', { style: 'font-weight:500' }, r.subject), h('div', { class: 'sub' }, r.audience_label)]) },
  { title: t('状态'), key: 'status', width: 100, render: (r) => h(NTag, { size: 'small', bordered: false, type: statusMeta[r.status].type }, () => statusMeta[r.status].label) },
  {
    title: t('时间'), key: 'sent_at', width: 170,
    render: (r) => h('span', { class: 'sub' }, r.sent_at ? formatDate(r.sent_at) : r.scheduled_at ? t('{0} 发送', [formatDate(r.scheduled_at)]) : formatDate(r.updated_at)),
  },
  { title: t('送达'), key: 'sent_count', width: 80, align: 'right', render: (r) => (r.status === 'draft' || r.status === 'scheduled' ? '—' : String(r.sent_count)) },
  { title: t('打开率'), key: 'open_rate', width: 90, align: 'right', render: (r) => (r.sent_count ? `${r.open_rate}%` : '—') },
  { title: t('点击率'), key: 'click_rate', width: 90, align: 'right', render: (r) => (r.sent_count ? `${r.click_rate}%` : '—') },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/campaigns')
    rows.value = data.data
    audiences.value = data.audiences
    Object.assign(popup, data.popup)
  } finally {
    loading.value = false
  }
}

async function savePopup() {
  savingPopup.value = true
  try {
    Object.assign(popup, (await http.put('/campaigns/popup', popup)).data.popup)
    popupOpen.value = false
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    savingPopup.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Marketing</div>
        <h1 class="page-title">{{ t('邮件营销') }}</h1>
        <p class="page-sub">{{ t('给订阅了邮件的顾客发新品、活动通知。只会发给同意接收营销邮件的人，每封邮件都带退订链接。') }}</p>
      </div>
      <NButton type="primary" @click="router.push({ name: 'campaign-new' })">{{ t('写邮件') }}</NButton>
    </div>

    <div class="cards">
      <NCard size="small">
        <div class="label">{{ t('订阅者') }}</div>
        <div class="big">{{ subscribers }}</div>
        <div class="sub">{{ audiences.filter((a) => a.key !== 'subscribers').map((a) => `${a.label} ${a.count}`).join(' · ') }}</div>
      </NCard>
      <NCard size="small">
        <div class="label">{{ t('订阅弹窗') }}</div>
        <div class="popup-row">
          <span class="big small">{{ popup.enabled ? t('已开启') : t('未开启') }}</span>
          <NButton size="small" @click="popupOpen = true">{{ t('设置') }}</NButton>
        </div>
        <div class="sub">{{ t('顾客也可以在结账页、首页「邮件订阅」区块和账户页订阅。') }}</div>
      </NCard>
    </div>

    <NCard content-style="padding: 0">
      <NDataTable
        v-if="loading || rows.length"
        :columns="columns"
        :data="rows"
        :loading="loading"
        :bordered="false"
        :scroll-x="760"
        :row-props="(r: Row) => ({ style: 'cursor:pointer', onClick: () => router.push({ name: 'campaign-edit', params: { id: r.id } }) })"
      />
      <NEmpty v-else :description="t('还没有发过邮件')" style="padding: 48px 0" />
    </NCard>

    <NModal v-model:show="popupOpen" preset="card" :title="t('订阅弹窗')" style="max-width: 520px">
      <NForm label-placement="top">
        <NFormItem :label="t('在店铺页面显示')"><NSwitch v-model:value="popup.enabled" /></NFormItem>
        <NFormItem :label="t('标题')"><NInput v-model:value="popup.heading" maxlength="80" /></NFormItem>
        <NFormItem :label="t('说明')"><NInput v-model:value="popup.text" type="textarea" :autosize="{ minRows: 2, maxRows: 4 }" maxlength="300" /></NFormItem>
        <NFormItem :label="t('按钮文字')"><NInput v-model:value="popup.button" maxlength="30" /></NFormItem>
        <NFormItem :label="t('订阅后显示的折扣码（选填）')">
          <NInput v-model:value="popup.discount_code" clearable :placeholder="t('例如 WELCOME10，需要先在「折扣」里创建')" />
        </NFormItem>
        <NFormItem :label="t('打开页面后几秒弹出')"><NInputNumber v-model:value="popup.delay" :min="0" :max="60" style="width: 140px" /></NFormItem>
      </NForm>
      <p class="sub">{{ t('每位访客只弹出一次；关闭或订阅后不再出现。结账页不会弹出。') }}</p>
      <template #footer>
        <div class="foot"><NButton type="primary" :loading="savingPopup" @click="savePopup">{{ t('保存') }}</NButton></div>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; }
.cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.label { font-size: 12px; color: var(--ink-muted); }
.big { font-size: 24px; font-weight: 600; margin: 4px 0; }
.big.small { font-size: 16px; }
.popup-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.sub, :deep(.sub) { font-size: 12px; color: var(--ink-muted); line-height: 1.6; }
.foot { display: flex; justify-content: flex-end; }
@media (max-width: 640px) { .cards { grid-template-columns: 1fr; } }
</style>
