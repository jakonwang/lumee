<script setup lang="ts">
/** Settings › Activity log: who changed what in this store (sign-ins, edits, refunds, exports…). */
import { computed, h, onMounted, ref, watch } from 'vue'
import EmptyState from '@/components/EmptyState.vue'
import { NCard, NDataTable, NSelect, type DataTableColumns } from 'naive-ui'
import { http } from '@/api/http'
import { formatDate } from '@/api/orders'
import SettingsNav from '@/components/SettingsNav.vue'
import { t } from '@/i18n'

interface Entry { id: number; action: string; label: string; subject: string | null; user: { id: number; name: string; email: string } | null; ip: string | null; created_at: string }

const rows = ref<Entry[]>([])
const people = ref<{ id: number; name: string }[]>([])
const loading = ref(false)
const userId = ref<number | null>(null)
const area = ref<string | null>(null)
const pagination = ref({ page: 1, pageSize: 30, itemCount: 0 })
const areas = [
  { label: t('登录与安全'), value: 'auth' }, { label: t('商品'), value: 'products' }, { label: t('订单'), value: 'orders' },
  { label: t('客户'), value: 'customers' }, { label: t('礼品卡'), value: 'gift-cards' }, { label: t('店铺装修'), value: 'theme' },
  { label: t('设置'), value: 'settings' }, { label: t('员工'), value: 'staff' },
]
const peopleOptions = computed(() => people.value.map((p) => ({ label: p.name, value: p.id })))

const columns: DataTableColumns<Entry> = [
  { title: t('时间'), key: 'created_at', width: 170, render: (r) => formatDate(r.created_at) },
  { title: t('员工'), key: 'user', width: 180, render: (r) => (r.user ? h('div', [h('div', r.user.name), h('div', { class: 'muted' }, r.user.email)]) : '—') },
  { title: t('操作'), key: 'label', minWidth: 200, render: (r) => h('span', [r.label, r.subject ? h('span', { class: 'muted' }, ` · ${r.subject}`) : null]) },
  { title: 'IP', key: 'ip', width: 140, render: (r) => h('span', { class: 'muted' }, r.ip ?? '—') },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/activity', { params: { page: pagination.value.page, user_id: userId.value ?? undefined, area: area.value ?? undefined } })
    rows.value = data.data
    people.value = data.people
    pagination.value.itemCount = data.total
  } finally { loading.value = false }
}
watch([userId, area], () => { pagination.value.page = 1; load() })
onMounted(load)
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Settings</div>
      <h1 class="page-title">{{ t('设置') }}</h1>
    </div>
    <SettingsNav />
    <NCard size="small" content-style="padding: 0">
      <template #header>{{ t('操作日志') }}</template>
      <template #header-extra><span class="muted">{{ t('记录员工的登录和所有修改操作') }}</span></template>
      <div class="toolbar">
        <NSelect v-model:value="userId" :options="peopleOptions" :placeholder="t('全部员工')" clearable size="small" style="width: 180px" />
        <NSelect v-model:value="area" :options="areas" :placeholder="t('全部类型')" clearable size="small" style="width: 160px" />
      </div>
      <NDataTable remote :columns="columns" :data="rows" :loading="loading" :bordered="false" :pagination="pagination" :scroll-x="720"
        @update:page="(p: number) => { pagination.page = p; load() }" >
        <template #empty>
          <EmptyState :title="t('还没有操作记录')" :text="t('员工在后台修改商品、订单、设置或导出数据时，会记录在这里。')" />
        </template>
      </NDataTable>
    </NCard>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.toolbar { display: flex; gap: 8px; padding: 12px 16px; border-bottom: 1px solid var(--border); }
:deep(.muted) { color: var(--ink-muted); font-size: 12px; }
</style>
