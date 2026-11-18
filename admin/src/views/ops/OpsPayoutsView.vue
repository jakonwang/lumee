<script setup lang="ts">
/** Ops: developer payouts to send (PayPal / bank transfer outside Lumée), then record the transfer. */
import { onMounted, ref, watch } from 'vue'
import { NButton, NCard, NEmpty, NInput, NModal, NSpin, NTab, NTabs, NTag, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Row {
  id: number; period: string; amount: string; status: 'pending' | 'paid'; method: string | null; details: string | null
  reference: string | null; paid_at: string | null; developer: { name: string; email: string } | null
}

const message = useMessage()
const tab = ref<'pending' | 'paid'>('pending')
const rows = ref<Row[]>([])
const total = ref('0.00')
const loading = ref(true)
const paying = ref<Row | null>(null)
const reference = ref('')

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/ops/payouts', { params: { status: tab.value } })
    rows.value = data.data
    total.value = data.pending_total
  } finally {
    loading.value = false
  }
}

async function markPaid() {
  if (!paying.value) return
  try {
    await http.post(`/ops/payouts/${paying.value.id}/paid`, { reference: reference.value })
    message.success(t('已标记为已支付'))
    paying.value = null
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  }
}

watch(tab, load)
onMounted(load)
</script>

<template>
  <div class="page">
    <h1 class="page-title">{{ t('开发者结算') }}</h1>
    <p class="muted">{{ t('待支付合计 US${0}。请通过 PayPal 或银行转账付款，然后填写流水号标记为已支付。', [total]) }}</p>
    <NTabs v-model:value="tab" type="line" size="small">
      <NTab name="pending">{{ t('待支付') }}</NTab>
      <NTab name="paid">{{ t('已支付') }}</NTab>
    </NTabs>
    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else-if="rows.length">
      <NCard v-for="r in rows" :key="r.id" size="small">
        <div class="head">
          <div>
            <strong>US${{ r.amount }}</strong> <span class="muted">· {{ r.period }}</span>
            <NTag size="small" :bordered="false" :type="r.status === 'paid' ? 'success' : 'warning'" style="margin-left: 6px">{{ r.status === 'paid' ? t('已支付') : t('待支付') }}</NTag>
            <div class="muted">{{ r.developer?.name }} · {{ r.developer?.email }}</div>
          </div>
          <NButton v-if="r.status === 'pending'" size="small" type="primary" @click="paying = r; reference = ''">{{ t('标记为已支付') }}</NButton>
        </div>
        <div class="acct">
          <template v-if="r.method">{{ r.method === 'paypal' ? 'PayPal' : t('银行转账') }}：<span class="pre">{{ r.details }}</span></template>
          <span v-else class="warn">{{ t('开发者还没有设置收款账户') }}</span>
        </div>
        <div v-if="r.paid_at" class="muted">{{ formatDate(r.paid_at) }} · {{ r.reference }}</div>
      </NCard>
    </template>
    <NEmpty v-else :description="t('没有结算')" style="padding: 32px 0" />

    <NModal :show="!!paying" preset="card" :title="t('标记为已支付')" style="max-width: 440px" @update:show="(v: boolean) => { if (!v) paying = null }">
      <NInput v-model:value="reference" maxlength="120" :placeholder="t('转账流水号')" />
      <template #footer><div class="foot"><NButton type="primary" :disabled="!reference.trim()" @click="markPaid">{{ t('确定') }}</NButton></div></template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 12px; max-width: 900px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 40px; }
.head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.muted { color: var(--ink-muted); font-size: 12px; line-height: 1.7; }
.acct { font-size: 13px; margin-top: 8px; }
.pre { white-space: pre-line; }
.warn { color: var(--warning, #B45309); }
.foot { display: flex; justify-content: flex-end; }
</style>
