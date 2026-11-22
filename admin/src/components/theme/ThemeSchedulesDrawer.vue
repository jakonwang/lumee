<script setup lang="ts">
/**
 * Theme editor › 活动排期: campaign versions of the design that go live and come down on their own.
 * From an upcoming shopping event (the automatic designer builds the campaign version) or from the
 * current draft; versions prepared automatically wait here for approval.
 */
import { ref, watch } from 'vue'
import { NButton, NDatePicker, NDrawer, NDrawerContent, NEmpty, NFormItem, NInput, NSwitch, NTag, useDialog, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Schedule { id: number; name: string; event_key: string | null; starts_at: string; ends_at: string; status: 'review' | 'scheduled' | 'active' | 'done' | 'cancelled'; source: 'manual' | 'auto' }
interface Event { key: string; name: string; start: string; end: string; prep_from: string; days_left: number; preparing: boolean; schedule_id: number | null }

const props = defineProps<{ show: boolean; themeId: number; marketId: number | null; marketName: string | null }>()
const emit = defineEmits<{ 'update:show': [boolean] }>()
const message = useMessage()
const dialog = useDialog()

const rows = ref<Schedule[]>([])
const events = ref<Event[]>([])
const auto = ref(false)
const timezone = ref('UTC')
const busy = ref<string | null>(null)
const params = () => ({ id: props.themeId, market: props.marketId ?? undefined })

async function load() {
  const d = (await http.get('/theme/schedules', { params: params() })).data
  rows.value = d.data
  events.value = d.events
  auto.value = d.auto
  timezone.value = d.timezone
}
watch(() => [props.show, props.marketId], ([open]) => { if (open) load() }, { immediate: true })

const statusLabel: Record<string, string> = { review: t('待确认'), scheduled: t('已排期'), active: t('进行中'), done: t('已结束'), cancelled: t('已取消') }
const statusType: Record<string, 'warning' | 'info' | 'success' | 'default'> = { review: 'warning', scheduled: 'info', active: 'success', done: 'default', cancelled: 'default' }

async function fromEvent(e: Event) {
  busy.value = e.key
  try {
    await http.post('/theme/schedules', { ...params(), market: props.marketId, event_key: e.key })
    message.success(t('已生成「{0}」的活动版本：{1} 自动上线，结束后自动恢复', [e.name, e.start]))
    await load()
  } catch (err) {
    message.error(errorMessage(err))
  } finally {
    busy.value = null
  }
}
async function act(s: Schedule, action: 'approve' | 'cancel') {
  const run = async () => {
    try {
      await http.post(`/theme/schedules/${s.id}/${action}`)
      await load()
    } catch (err) {
      message.error(errorMessage(err))
    }
  }
  if (action === 'cancel' && s.status === 'active') {
    dialog.warning({ title: t('现在结束「{0}」？', [s.name]), content: t('活动版本会马上下线，店铺恢复成活动开始前的样子。'), positiveText: t('结束活动'), negativeText: t('取消'), onPositiveClick: run })
    return
  }
  await run()
}
async function toggleAuto(v: boolean) {
  try {
    const d = (await http.put('/theme/auto-campaigns', { enabled: v })).data
    auto.value = d.auto
    if (d.prepared) message.success(t('已为 {0} 个即将到来的活动生成了活动版本，确认后才会上线', [d.prepared]))
    await load()
  } catch (err) {
    message.error(errorMessage(err))
  }
}

// From the current draft between two times
const draft = ref({ name: '', range: null as [number, number] | null })
async function fromDraft() {
  if (!draft.value.range) return
  const fmt = (ts: number) => { const d = new Date(ts); const p = (n: number) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}` }
  try {
    await http.post('/theme/schedules', { ...params(), market: props.marketId, name: draft.value.name || t('活动版本'), starts_at: fmt(draft.value.range[0]), ends_at: fmt(draft.value.range[1]) })
    message.success(t('已排期：到时间自动上线，结束后自动恢复'))
    draft.value = { name: '', range: null }
    await load()
  } catch (err) {
    message.error(errorMessage(err))
  }
}
</script>

<template>
  <NDrawer :show="show" :width="440" placement="right" @update:show="(v: boolean) => emit('update:show', v)">
    <NDrawerContent :title="marketName ? t('活动排期 · {0}', [marketName]) : t('活动排期 · 主站点')" closable>
      <p class="hint">{{ t('活动版本到开始时间自动上线，结束后自动恢复成原来的样子；活动进行中你自己发布的修改会保留。时间按店铺时区（{0}）。', [timezone]) }}</p>

      <div class="auto">
        <div>
          <strong>{{ t('自动准备活动版本') }}</strong>
          <div class="hint" style="margin: 2px 0 0">{{ t('活动进入准备期时，系统按活动日历自动生成活动版本，等你确认后才会上线（首页待办会提醒）。') }}</div>
        </div>
        <NSwitch :value="auto" @update:value="toggleAuto" />
      </div>

      <h4>{{ t('排期') }}</h4>
      <NEmpty v-if="!rows.length" :description="t('还没有活动版本')" style="padding: 12px 0" />
      <div v-for="s in rows" :key="s.id" class="row">
        <div class="grow">
          <div class="title">{{ s.name }} <NTag size="tiny" :bordered="false" :type="statusType[s.status]">{{ statusLabel[s.status] }}</NTag>
            <NTag v-if="s.source === 'auto'" size="tiny" :bordered="false">{{ t('自动生成') }}</NTag></div>
          <div class="hint" style="margin: 2px 0 0">{{ formatDate(s.starts_at) }} – {{ formatDate(s.ends_at) }}</div>
        </div>
        <NButton v-if="s.status === 'review'" size="tiny" type="primary" secondary @click="act(s, 'approve')">{{ t('确认排期') }}</NButton>
        <NButton v-if="['review', 'scheduled', 'active'].includes(s.status)" size="tiny" quaternary @click="act(s, 'cancel')">{{ s.status === 'active' ? t('结束') : t('取消') }}</NButton>
      </div>

      <h4>{{ t('即将到来的活动') }}</h4>
      <div v-for="e in events" :key="e.key" class="row">
        <div class="grow">
          <div class="title">{{ e.name }} <NTag v-if="e.preparing" size="tiny" :bordered="false" type="success">{{ t('准备期') }}</NTag></div>
          <div class="hint" style="margin: 2px 0 0">{{ e.start }}{{ e.end !== e.start ? ' – ' + e.end : '' }} · {{ t('还有 {0} 天', [e.days_left]) }}</div>
        </div>
        <NButton v-if="!e.schedule_id" size="tiny" secondary :loading="busy === e.key" @click="fromEvent(e)">{{ t('生成活动版本') }}</NButton>
        <span v-else class="hint" style="margin: 0">{{ t('已有排期') }}</span>
      </div>

      <h4>{{ t('用当前草稿排期') }}</h4>
      <NFormItem :label="t('名称')" :show-feedback="false"><NInput v-model:value="draft.name" maxlength="120" :placeholder="t('例如：周末大促')" /></NFormItem>
      <NFormItem :label="t('上线时间')" :show-feedback="false" style="margin-top: 8px"><NDatePicker v-model:value="draft.range" type="datetimerange" clearable style="width: 100%" /></NFormItem>
      <NButton size="small" type="primary" secondary style="margin-top: 10px" :disabled="!draft.range" @click="fromDraft">{{ t('排期') }}</NButton>
    </NDrawerContent>
  </NDrawer>
</template>

<style scoped>
.hint { font-size: 12px; color: var(--ink-muted); margin: 0 0 12px; line-height: 1.5; }
.auto { display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; padding: 12px; border: 1px solid var(--border); border-radius: 10px; margin-bottom: 8px; }
h4 { font-size: 13px; margin: 18px 0 6px; }
.row { display: flex; gap: 8px; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--border); }
.grow { flex: 1; min-width: 0; }
.title { font-size: 13.5px; font-weight: 500; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
</style>
