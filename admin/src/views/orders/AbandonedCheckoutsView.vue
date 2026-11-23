<script setup lang="ts">
import { computed, h, onMounted, reactive, ref } from 'vue'
import EmptyState from '@/components/EmptyState.vue'
import { NCard, NDataTable, NTag, NSwitch, NSelect, NInput, NButton, NAlert, useMessage, type DataTableColumns } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Row {
  id: number
  email: string
  items: { title: string; variant_title: string | null; quantity: number }[]
  item_count: number
  subtotal: string
  started_at: string
  last_activity_at: string
  recovery_sent_at: string | null
  locale: string | null
}
interface Settings { enabled: boolean; delay_hours: number; discount_code: string | null }

const auth = useAuth()
const message = useMessage()
const canEdit = computed(() => auth.can('marketing'))
const loading = ref(true)
const saving = ref(false)
const rows = ref<Row[]>([])
const currency = ref('')
const stats = ref({ sent: 0, recovered_orders: 0, recovered_revenue: '0.00' })
const settings = reactive<Settings>({ enabled: true, delay_hours: 4, discount_code: null })
const delays = ref<number[]>([])
const dirty = ref(false)
const pagination = reactive({ page: 1, pageSize: 20, itemCount: 0 })

const delayOptions = computed(() => delays.value.map((d) => ({ value: d, label: t('{0} 小时后', [d]) })))

const columns: DataTableColumns<Row> = [
  { title: () => t('邮箱'), key: 'email', minWidth: 200 },
  {
    title: () => t('商品'), key: 'items', minWidth: 240,
    render: (r) => h('div', { class: 'items' }, r.items.slice(0, 3).map((i) =>
      h('div', `${i.title}${i.variant_title ? ' · ' + i.variant_title : ''} × ${i.quantity}`)).concat(
      r.items.length > 3 ? [h('div', { class: 'muted' }, t('等 {0} 件商品', [r.item_count]))] : [])),
  },
  { title: () => t('金额'), key: 'subtotal', width: 120, render: (r) => `${currency.value} ${r.subtotal}` },
  { title: () => t('开始结账'), key: 'started_at', width: 160, render: (r) => formatDate(r.started_at) },
  {
    title: () => t('挽回邮件'), key: 'recovery_sent_at', width: 150,
    render: (r) => r.recovery_sent_at
      ? h(NTag, { size: 'small', bordered: false, type: 'success' }, () => t('已发送 {0}', [formatDate(r.recovery_sent_at)]))
      : h(NTag, { size: 'small', bordered: false }, () => t('未发送')),
  },
]

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await http.get('/abandoned-checkouts', { params: { page } })
    rows.value = data.data
    currency.value = data.currency
    stats.value = data.stats
    Object.assign(settings, data.settings)
    delays.value = data.delays
    pagination.page = data.meta.current_page
    pagination.itemCount = data.meta.total
    dirty.value = false
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const { data } = await http.put('/abandoned-checkouts/settings', settings)
    Object.assign(settings, data.settings)
    dirty.value = false
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Sales</div>
      <h1 class="page-title">{{ t('弃单') }}</h1>
      <p class="page-sub">{{ t('买家进入结账页并填写了邮箱，但没有完成付款。系统会自动发一封提醒邮件，带买家回到结账页。') }}</p>
    </div>

    <div class="stats">
      <NCard size="small"><div class="stat-label">{{ t('近 30 天发送提醒') }}</div><div class="stat">{{ stats.sent }}</div></NCard>
      <NCard size="small"><div class="stat-label">{{ t('挽回订单') }}</div><div class="stat">{{ stats.recovered_orders }}</div></NCard>
      <NCard size="small"><div class="stat-label">{{ t('挽回销售额') }}</div><div class="stat">{{ currency }} {{ stats.recovered_revenue }}</div></NCard>
    </div>

    <NCard :title="t('弃单挽回邮件')" size="small">
      <div class="settings">
        <div class="row">
          <NSwitch v-model:value="settings.enabled" :disabled="!canEdit" @update:value="dirty = true" />
          <span>{{ settings.enabled ? t('已开启：每个弃单只发一封，用买家结账时的语言') : t('已关闭') }}</span>
        </div>
        <template v-if="settings.enabled">
          <div class="field">
            <label>{{ t('发送时间') }}</label>
            <NSelect v-model:value="settings.delay_hours" :options="delayOptions" :disabled="!canEdit" style="width: 200px" @update:value="dirty = true" />
            <span class="muted">{{ t('从买家最后一次操作购物车算起') }}</span>
          </div>
          <div class="field">
            <label>{{ t('附带折扣码（选填）') }}</label>
            <NInput v-model:value="settings.discount_code" :disabled="!canEdit" clearable :placeholder="t('例如 COMEBACK10')" style="width: 200px" @update:value="dirty = true" />
            <span class="muted">{{ t('折扣码需要先在「折扣」里创建') }}</span>
          </div>
        </template>
        <NAlert type="info" :bordered="false">{{ t('邮件由定时任务发送（lumee:recover-checkouts，每 15 分钟检查一次）。超过 7 天的弃单，以及之后已经下单的买家，不会收到提醒。') }}</NAlert>
        <div v-if="canEdit"><NButton type="primary" :disabled="!dirty" :loading="saving" @click="save">{{ t('保存') }}</NButton></div>
      </div>
    </NCard>

    <NCard :title="t('近 30 天的弃单')" content-style="padding: 0" size="small">
      <NDataTable
        remote
        :columns="columns"
        :data="rows"
        :loading="loading"
        :bordered="false"
        :pagination="pagination"
        :scroll-x="860"
        @update:page="load"
      >
        <template #empty>
          <EmptyState :title="t('没有弃单')" :text="t('买家填写了邮箱但没有完成付款时，会出现在这里。开启挽回邮件后，系统会自动提醒他们回来完成购买。')" />
        </template>
      </NDataTable>
    </NCard>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; }
.stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.stat-label { font-size: 12px; color: var(--ink-muted); }
.stat { font-size: 22px; font-weight: 600; margin-top: 4px; }
.settings { display: grid; gap: 14px; }
.row { display: flex; align-items: center; gap: 10px; }
.field { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.field label { min-width: 140px; font-size: 13px; }
.muted { color: var(--ink-muted); font-size: 12px; }
:deep(.items) { font-size: 13px; line-height: 1.5; }
@media (max-width: 640px) { .stats { grid-template-columns: 1fr; } }
</style>
