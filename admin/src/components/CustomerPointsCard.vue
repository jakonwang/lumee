<script setup lang="ts">
/** Customer page › Points: balance, add/remove with a note, recent history. */
import { onMounted, ref } from 'vue'
import { NButton, NCard, NInput, NInputNumber, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

const props = defineProps<{ customerId: number }>()
const message = useMessage()
interface Tx { id: number; points: number; balance_after: number; kind: string; order_id: number | null; note: string | null; user: string | null; created_at: string }
const points = ref(0)
const history = ref<Tx[]>([])
const delta = ref<number | null>(null)
const note = ref('')
const busy = ref(false)

function apply(d: { points: number; history: Tx[] }) { points.value = d.points; history.value = d.history }
onMounted(async () => apply((await http.get(`/customers/${props.customerId}/points`)).data))
async function adjust() {
  if (!delta.value) return
  busy.value = true
  try {
    apply((await http.post(`/customers/${props.customerId}/points`, { points: delta.value, note: note.value || undefined })).data)
    delta.value = null
    note.value = ''
    message.success(t('积分已调整'))
  } catch (e) { message.error(errorMessage(e)) } finally { busy.value = false }
}
const kindLabel: Record<string, string> = { earned: t('下单获得'), redeemed: t('兑换抵扣金'), reversed: t('订单取消扣回'), adjusted: t('手动调整') }
</script>

<template>
  <NCard :title="t('积分')" size="small">
    <template #header-extra><strong class="bal">{{ points.toLocaleString() }}</strong></template>
    <div class="adjust">
      <NInputNumber v-model:value="delta" size="small" :placeholder="t('如 +100 或 -50')" style="width: 130px" />
      <NInput v-model:value="note" size="small" maxlength="200" :placeholder="t('原因')" />
      <NButton size="small" :disabled="!delta" :loading="busy" @click="adjust">{{ t('调整') }}</NButton>
    </div>
    <div v-for="x in history.slice(0, 8)" :key="x.id" class="tx">
      <div>
        <div>{{ kindLabel[x.kind] ?? x.kind }}</div>
        <div class="muted">{{ formatDate(x.created_at) }}{{ x.user ? ` · ${x.user}` : '' }}{{ x.note ? ` · ${x.note}` : '' }}</div>
      </div>
      <div class="num" :class="x.points > 0 ? 'up' : ''">{{ x.points > 0 ? '+' : '' }}{{ x.points }}</div>
    </div>
  </NCard>
</template>

<style scoped>
.bal { font-size: 16px; font-variant-numeric: tabular-nums; }
.adjust { display: flex; gap: 6px; margin-bottom: 8px; }
.tx { display: flex; justify-content: space-between; gap: 8px; padding: 8px 0; border-top: 1px solid var(--border); font-size: 13px; }
.muted { color: var(--ink-muted); font-size: 12px; }
.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.up { color: var(--brand-700); }
</style>
