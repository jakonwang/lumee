<script setup lang="ts">
/** Customer data requests (GDPR / CCPA): exports and deletion requests to work through within 30 days. */
import { onMounted, ref } from 'vue'
import { NAlert, NButton, NCard, NEmpty, NInput, NModal, NSpin, NTag, useDialog, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Row {
  id: number; email: string; type: 'export' | 'erase'; source: 'customer' | 'merchant'; status: 'pending' | 'completed' | 'rejected'
  note: string | null; created_at: string; completed_at: string | null; due_at: string; blocker: string | null
}

const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const rows = ref<Row[]>([])
const rejecting = ref<Row | null>(null)
const note = ref('')
const statusMeta = { pending: { label: t('待处理'), type: 'warning' }, completed: { label: t('已完成'), type: 'success' }, rejected: { label: t('已拒绝'), type: 'default' } } as const

async function load() {
  try {
    rows.value = (await http.get('/privacy-requests')).data.data
  } finally {
    loading.value = false
  }
}

function complete(r: Row) {
  dialog.warning({
    title: t('删除 {0} 的个人数据？', [r.email]),
    content: t('客户账户会被删除，订单里的姓名、邮箱、地址、电话和备注会被清除（订单金额和商品保留，用于财务记录）。这个操作无法撤销。'),
    positiveText: t('删除个人数据'), negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        await http.post(`/privacy-requests/${r.id}/complete`)
        message.success(t('已删除，并已邮件通知客户'))
        await load()
      } catch (e) {
        message.error(errorMessage(e))
      }
    },
  })
}

async function reject() {
  if (!rejecting.value) return
  try {
    await http.post(`/privacy-requests/${rejecting.value.id}/reject`, { note: note.value })
    rejecting.value = null
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Customers</div>
      <h1 class="page-title">{{ t('隐私请求') }}</h1>
      <p class="page-sub">{{ t('顾客可以在账户页下载自己的数据或申请删除账户。按照 GDPR 等法规，删除申请需要在 30 天内处理。也可以在客户详情页直接导出或删除某位客户的数据。') }}</p>
    </div>
    <div v-if="loading" class="center"><NSpin /></div>
    <NCard v-else-if="rows.length" size="small" content-style="padding: 0">
      <div v-for="r in rows" :key="r.id" class="row">
        <div class="main">
          <div><strong>{{ r.email }}</strong>
            <NTag size="tiny" :bordered="false">{{ r.type === 'erase' ? t('删除数据') : t('导出数据') }}</NTag>
            <NTag size="tiny" :bordered="false" :type="statusMeta[r.status].type">{{ statusMeta[r.status].label }}</NTag>
          </div>
          <div class="muted">
            {{ r.source === 'customer' ? t('顾客在账户页提交') : t('商家操作') }} · {{ formatDate(r.created_at) }}
            <template v-if="r.status === 'pending'"> · {{ t('请在 {0} 前处理', [formatDate(r.due_at)]) }}</template>
            <template v-if="r.note"> · {{ r.note }}</template>
          </div>
          <NAlert v-if="r.blocker" type="warning" :bordered="false" style="margin-top: 6px" size="small">{{ r.blocker }}</NAlert>
        </div>
        <div v-if="r.status === 'pending' && r.type === 'erase'" class="btns">
          <NButton size="small" type="error" secondary :disabled="!!r.blocker" @click="complete(r)">{{ t('删除个人数据') }}</NButton>
          <NButton size="small" quaternary @click="rejecting = r; note = ''">{{ t('拒绝') }}</NButton>
        </div>
      </div>
    </NCard>
    <NEmpty v-else :description="t('还没有隐私请求')" style="padding: 32px 0" />

    <NModal :show="!!rejecting" preset="card" :title="t('拒绝原因')" style="max-width: 460px" @update:show="(v: boolean) => { if (!v) rejecting = null }">
      <NInput v-model:value="note" type="textarea" maxlength="300" :placeholder="t('例如：法律要求保留的数据')" />
      <template #footer><div class="foot"><NButton type="primary" :disabled="!note.trim()" @click="reject">{{ t('确定') }}</NButton></div></template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; max-width: 720px; }
.center { display: flex; justify-content: center; padding: 48px; }
.row { display: flex; gap: 12px; align-items: flex-start; padding: 12px 16px; border-top: 1px solid var(--border); }
.row:first-child { border-top: 0; }
.main { flex: 1; min-width: 0; display: grid; gap: 4px; }
.muted { color: var(--ink-muted); font-size: 12px; }
.btns { display: flex; gap: 6px; }
.foot { display: flex; justify-content: flex-end; }
</style>
