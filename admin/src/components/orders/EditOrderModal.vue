<script setup lang="ts">
/** Edit an unshipped order: change quantities, remove lines, add products. The server works out refunds / balance due. */
import { computed, ref, watch } from 'vue'
import { NAlert, NButton, NCheckbox, NInput, NInputNumber, NModal, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import type { Order } from '@/api/orders'
import { t } from '@/i18n'

interface Found { id: number; title: string; variant_title: string | null; sku: string | null; price: string; inventory: number }

const props = defineProps<{ order: Order }>()
const show = defineModel<boolean>('show', { required: true })
const emit = defineEmits<{ saved: [order: Order] }>()
const message = useMessage()
const items = computed(() => props.order.items ?? [])

const qty = ref<Record<number, number>>({})
const added = ref<{ variant: Found; quantity: number }[]>([])
const q = ref('')
const results = ref<Found[]>([])
const notify = ref(true)
const saving = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

watch(show, (v) => {
  if (!v) return
  qty.value = Object.fromEntries(items.value.map((i) => [i.id, i.quantity]))
  added.value = []
  q.value = ''
  results.value = []
})

watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    if (!q.value.trim()) { results.value = []; return }
    results.value = (await http.get('/orders/manual/variants', { params: { q: q.value } })).data.data.slice(0, 8)
  }, 250)
})

function add(v: Found) {
  const ex = added.value.find((a) => a.variant.id === v.id)
  if (ex) ex.quantity++
  else added.value.push({ variant: v, quantity: 1 })
  q.value = ''
}

const newSubtotal = computed(() => items.value.reduce((s, i) => s + Number(i.price) * (qty.value[i.id] ?? i.quantity), 0)
  + added.value.reduce((s, a) => s + Number(a.variant.price) * a.quantity, 0))
const changed = computed(() => added.value.length > 0 || items.value.some((i) => (qty.value[i.id] ?? i.quantity) !== i.quantity))
const paid = computed(() => ['paid', 'partially_refunded'].includes(props.order.financial_status))

async function save() {
  saving.value = true
  try {
    const { data } = await http.post(`/orders/${props.order.id}/edit`, {
      items: items.value.filter((i) => qty.value[i.id] !== i.quantity).map((i) => ({ id: i.id, quantity: qty.value[i.id] })),
      add: added.value.map((a) => ({ variant_id: a.variant.id, quantity: a.quantity })),
      notify: notify.value,
    })
    const r = data.result
    message.success(r.refunded > 0 ? t('订单已修改，已退还差价 {0} {1}', [props.order.currency, (r.refunded / 100).toFixed(2)])
      : paid.value && r.difference > 0 ? t('订单已修改，买家需要补付 {0} {1}', [props.order.currency, (r.difference / 100).toFixed(2)]) : t('订单已修改'))
    emit('saved', data.data)
    show.value = false
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <NModal v-model:show="show" preset="card" :title="t('编辑订单 {0}', [order.name])" style="max-width: 640px">
    <div class="lines">
      <div v-for="i in items" :key="i.id" class="line" :class="{ removed: qty[i.id] === 0 }">
        <div class="name">{{ i.title }}<small v-if="i.variant_title"> · {{ i.variant_title }}</small></div>
        <span class="muted">{{ order.currency }} {{ i.price }}</span>
        <NInputNumber v-model:value="qty[i.id]" :min="i.refunded_quantity || 0" size="small" style="width: 100px" />
      </div>
      <div v-for="(a, idx) in added" :key="'n' + a.variant.id" class="line new">
        <div class="name">{{ a.variant.title }}<small v-if="a.variant.variant_title"> · {{ a.variant.variant_title }}</small> <em>{{ t('新增') }}</em></div>
        <span class="muted">{{ order.currency }} {{ a.variant.price }}</span>
        <NInputNumber v-model:value="a.quantity" :min="1" size="small" style="width: 100px" />
        <NButton size="tiny" quaternary @click="added.splice(idx, 1)">×</NButton>
      </div>
    </div>
    <NInput v-model:value="q" :placeholder="t('搜索商品名称或 SKU 来添加')" clearable style="margin-top: 12px" />
    <div v-if="results.length" class="results">
      <button v-for="v in results" :key="v.id" type="button" class="result" @click="add(v)">
        <span>{{ v.title }}<small v-if="v.variant_title"> · {{ v.variant_title }}</small></span>
        <span class="muted">{{ order.currency }} {{ v.price }} · {{ t('库存 {0}', [v.inventory]) }}</span>
      </button>
    </div>
    <div class="sum">{{ t('新的商品小计：{0} {1}', [order.currency, newSubtotal.toFixed(2)]) }}<span class="muted">（{{ t('原来 {0} {1}', [order.currency, order.subtotal]) }}）</span></div>
    <NAlert type="info" :bordered="false" style="margin-top: 10px">
      {{ paid ? t('已付款订单：金额变少时，差价会自动原路退给买家；金额变多时，订单会记录待补付金额，收到后点「已收到补付」。税费会重新计算，库存会同步调整。') : t('未付款订单：订单金额直接更新，买家按新金额付款。') }}
    </NAlert>
    <NCheckbox v-if="paid" v-model:checked="notify" style="margin-top: 10px">{{ t('退还差价时给买家发退款通知') }}</NCheckbox>
    <template #footer>
      <div class="foot"><NButton @click="show = false">{{ t('取消') }}</NButton><NButton type="primary" :loading="saving" :disabled="!changed" @click="save">{{ t('保存修改') }}</NButton></div>
    </template>
  </NModal>
</template>

<style scoped>
.lines { display: grid; }
.line { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border); }
.line.removed .name { text-decoration: line-through; color: var(--ink-muted); }
.line.new em { font-style: normal; font-size: 11px; color: var(--brand-700, #0A7350); }
.name { flex: 1; min-width: 0; font-size: 14px; }
.name small { color: var(--ink-muted); }
.muted { color: var(--ink-muted); font-size: 12px; }
.results { border: 1px solid var(--border); border-radius: 8px; margin-top: 6px; max-height: 220px; overflow: auto; }
.result { all: unset; display: flex; justify-content: space-between; gap: 8px; width: 100%; box-sizing: border-box; padding: 8px 10px; cursor: pointer; font-size: 13px; }
.result:hover { background: var(--brand-50, #EAF7F1); }
.sum { margin-top: 12px; font-size: 14px; font-weight: 600; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
</style>
