<script setup lang="ts">
/**
 * Product page › stock: adjust a variant's count with a reason (stocktake, damaged, received)
 * and see every change — orders, refunds, cancels, edits, imports, API — newest first.
 */
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { NButton, NCard, NEmpty, NInput, NInputNumber, NRadioGroup, NRadioButton, NSelect, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

const props = defineProps<{ productId: number; variants: { id?: number; title?: string; inventory: number | null }[] }>()
const emit = defineEmits<{ adjusted: [variantId: number, inventory: number] }>()
const message = useMessage()

interface Entry { id: number; variant_id: number; variant: string | null; delta: number; quantity_after: number; reason: string; order: { id: number; name: string } | null; user: string | null; note: string | null; created_at: string }
const rows = ref<Entry[]>([])
const loaded = ref(false)
async function load() {
  rows.value = (await http.get(`/products/${props.productId}/inventory`)).data.data
  loaded.value = true
}
onMounted(load)

const saved = computed(() => props.variants.filter((v) => v.id))
const variantOptions = computed(() => saved.value.map((v) => ({ label: !v.title || v.title === 'Default' ? t('默认') : v.title, value: v.id! })))
const variantId = ref<number | null>(saved.value[0]?.id ?? null)
const mode = ref<'adjust' | 'set'>('adjust')
const qty = ref<number | null>(null)
const note = ref('')
const busy = ref(false)
const current = computed(() => saved.value.find((v) => v.id === variantId.value)?.inventory ?? 0)

async function apply() {
  if (!variantId.value || qty.value === null) return
  busy.value = true
  try {
    const { data } = await http.post(`/products/${props.productId}/inventory`, {
      variant_id: variantId.value, note: note.value || undefined,
      ...(mode.value === 'set' ? { available: qty.value } : { adjustment: qty.value }),
    })
    emit('adjusted', data.data.variant_id, data.data.inventory)
    message.success(t('库存已更新为 {0}', [data.data.inventory]))
    qty.value = null
    note.value = ''
    load()
  } catch (e) { message.error(errorMessage(e)) } finally { busy.value = false }
}

const reasonLabel: Record<string, string> = {
  created: t('创建商品'), edit: t('编辑商品'), manual: t('手动调整'), order: t('订单'), refund: t('退款退回'),
  cancel: t('取消订单退回'), import: t('CSV 导入'), api: t('API'), copy: t('从其他店铺复制'),
}
</script>

<template>
  <NCard :title="t('库存记录')" size="small">
    <div v-if="saved.length" class="adjust">
      <NSelect v-if="saved.length > 1" v-model:value="variantId" :options="variantOptions" size="small" style="width: 160px" />
      <NRadioGroup v-model:value="mode" size="small">
        <NRadioButton value="adjust">{{ t('增减') }}</NRadioButton>
        <NRadioButton value="set">{{ t('设为') }}</NRadioButton>
      </NRadioGroup>
      <NInputNumber v-model:value="qty" size="small" :placeholder="mode === 'adjust' ? t('如 +10 或 -2') : t('盘点数量')" style="width: 130px" />
      <NInput v-model:value="note" size="small" maxlength="200" :placeholder="t('原因（如：盘点、到货、损坏）')" style="flex: 1; min-width: 160px" />
      <NButton size="small" type="primary" :disabled="qty === null || !variantId" :loading="busy" @click="apply">{{ t('调整') }}</NButton>
    </div>
    <p class="hint">{{ t('当前库存 {0}。在这里调整会立即生效并留下记录；修改规格表里的库存需要保存商品。', [current]) }}</p>

    <NEmpty v-if="loaded && !rows.length" :description="t('还没有库存变动')" size="small" />
    <table v-else-if="rows.length" class="tbl">
      <thead><tr><th>{{ t('时间') }}</th><th v-if="saved.length > 1">{{ t('规格') }}</th><th>{{ t('原因') }}</th><th class="num">{{ t('变动') }}</th><th class="num">{{ t('结余') }}</th></tr></thead>
      <tbody>
        <tr v-for="r in rows" :key="r.id">
          <td class="muted">{{ formatDate(r.created_at) }}</td>
          <td v-if="saved.length > 1">{{ r.variant ?? '—' }}</td>
          <td>
            {{ reasonLabel[r.reason] ?? r.reason }}
            <RouterLink v-if="r.order" :to="{ name: 'order', params: { id: r.order.id } }">{{ r.order.name }}</RouterLink>
            <div v-if="r.note || r.user" class="muted">{{ [r.note, r.user].filter(Boolean).join(' · ') }}</div>
          </td>
          <td class="num" :class="r.delta > 0 ? 'up' : 'down'">{{ r.delta > 0 ? '+' : '' }}{{ r.delta }}</td>
          <td class="num">{{ r.quantity_after }}</td>
        </tr>
      </tbody>
    </table>
  </NCard>
</template>

<style scoped>
.adjust { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.hint { font-size: 12px; color: var(--ink-muted); margin: 8px 0 12px; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { text-align: left; font-size: 12px; font-weight: 500; color: var(--ink-muted); padding: 6px 4px; border-bottom: 1px solid var(--border); }
.tbl td { padding: 8px 4px; border-bottom: 1px solid var(--border); vertical-align: top; }
.tbl tr:last-child td { border-bottom: 0; }
.num { text-align: right !important; white-space: nowrap; font-variant-numeric: tabular-nums; }
.up { color: var(--brand-700); }
.down { color: var(--ink); }
.muted { color: var(--ink-muted); font-size: 12px; }
a { margin-left: 4px; color: var(--brand-700); }
</style>
