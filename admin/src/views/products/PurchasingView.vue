<script setup lang="ts">
/**
 * Products › Purchasing (采购): what to reorder (sales speed vs stock and open orders), purchase orders
 * and their progress, and suppliers — each with a private portal link where they confirm orders,
 * share ship dates and tracking, and offer new products.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  NButton, NCard, NCheckbox, NEmpty, NForm, NFormItem, NInput, NInputNumber, NModal, NRadioButton, NRadioGroup, NSelect, NSpin, NTag, useDialog, useMessage,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Supplier { id: number; name: string; contact_name: string | null; email: string | null; phone: string | null; platform: string | null; note: string | null; portal_url: string; products: number; open_orders: number }
interface Item { variant_id: number; title: string; variant_title: string | null; sku: string | null; quantity: number; cost: string }
interface Order { id: number; name: string; status: string; supplier: string; supplier_id: number; total: string; units: number; items: Item[]; note: string | null; supplier_note: string | null; tracking: string | null; expected_at: string | null; created_at: string; sent_at: string | null }
interface Suggestion { variant_id: number; product_id: number; title: string; variant_title: string | null; sku: string | null; supplier_id: number | null; stock: number; inbound: number; sold: number; suggested: number; cost: string | null; hot: boolean }

const message = useMessage()
const dialog = useDialog()
const tab = ref<'suggest' | 'orders' | 'suppliers'>('suggest')
const loading = ref(true)
const suppliers = ref<Supplier[]>([])
const orders = ref<Order[]>([])
const suggestions = ref<Suggestion[]>([])
const currency = ref('USD')

async function load() {
  loading.value = true
  try {
    const d = (await http.get('/purchasing')).data
    suppliers.value = d.suppliers
    orders.value = d.orders
    suggestions.value = d.suggestions
    currency.value = d.currency
  } finally {
    loading.value = false
  }
}
onMounted(load)

const statusLabel: Record<string, string> = { draft: t('草稿'), sent: t('已发给供应商'), confirmed: t('供应商已确认'), shipped: t('已发货'), received: t('已入库'), cancelled: t('已取消') }
const statusType: Record<string, 'default' | 'info' | 'warning' | 'success' | 'error'> = { draft: 'default', sent: 'warning', confirmed: 'info', shipped: 'info', received: 'success', cancelled: 'error' }
const supplierName = (id: number | null) => suppliers.value.find((s) => s.id === id)?.name ?? t('未指定供应商')

// Suggestions → purchase order (per supplier)
const picked = ref<Record<number, number>>({})   // variant id → quantity
const groups = computed(() => {
  const by = new Map<number | null, Suggestion[]>()
  for (const s of suggestions.value) by.set(s.supplier_id, [...(by.get(s.supplier_id) ?? []), s])
  return [...by.entries()].map(([id, rows]) => ({ id, rows }))
})
function toggle(s: Suggestion, on: boolean) {
  if (on) picked.value[s.variant_id] = s.suggested
  else delete picked.value[s.variant_id]
}

// New order dialog
const showOrder = ref(false)
const orderForm = reactive({ supplier_id: null as number | null, note: '', lines: [] as { variant_id: number; label: string; quantity: number; cost: number | null }[] })
function newOrder(supplierId: number | null, rows: Suggestion[]) {
  const chosen = rows.filter((r) => picked.value[r.variant_id])
  if (!chosen.length) return message.warning(t('先勾选要采购的商品'))
  Object.assign(orderForm, {
    supplier_id: supplierId ?? suppliers.value[0]?.id ?? null, note: '',
    lines: chosen.map((r) => ({ variant_id: r.variant_id, label: r.title + (r.variant_title ? ` · ${r.variant_title}` : '') + (r.sku ? ` (${r.sku})` : ''), quantity: picked.value[r.variant_id], cost: r.cost ? Number(r.cost) : null })),
  })
  showOrder.value = true
}
const creating = ref(false)
async function createOrder() {
  creating.value = true
  try {
    await http.post('/purchase-orders', { supplier_id: orderForm.supplier_id, note: orderForm.note || null, lines: orderForm.lines.map((l) => ({ variant_id: l.variant_id, quantity: l.quantity, cost: l.cost })) })
    message.success(t('已创建采购单（草稿），确认后点「发给供应商」'))
    showOrder.value = false
    picked.value = {}
    tab.value = 'orders'
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    creating.value = false
  }
}

async function act(o: Order, action: 'send' | 'receive' | 'cancel') {
  const run = async () => {
    try {
      await http.post(`/purchase-orders/${o.id}/action`, { action })
      message.success(action === 'send' ? t('已发给供应商') : action === 'receive' ? t('已入库，库存已增加') : t('已取消'))
      await load()
    } catch (e) {
      message.error(errorMessage(e))
    }
  }
  if (action === 'receive') {
    dialog.info({ title: t('确认收货入库？'), content: t('{0} 件商品会加到库存里，成本更新为采购价。', [o.units]), positiveText: t('入库'), negativeText: t('取消'), onPositiveClick: run })
  } else {
    await run()
  }
}

// Suppliers
const showSupplier = ref(false)
const editingSupplier = ref<Supplier | null>(null)
const supplierForm = reactive({ name: '', contact_name: '', email: '', phone: '', platform: 'factory', note: '' })
function editSupplier(s?: Supplier) {
  editingSupplier.value = s ?? null
  Object.assign(supplierForm, s ? { name: s.name, contact_name: s.contact_name ?? '', email: s.email ?? '', phone: s.phone ?? '', platform: s.platform ?? 'factory', note: s.note ?? '' }
    : { name: '', contact_name: '', email: '', phone: '', platform: 'factory', note: '' })
  showSupplier.value = true
}
async function saveSupplier() {
  try {
    const body = { ...supplierForm, email: supplierForm.email || null }
    if (editingSupplier.value) await http.put(`/suppliers/${editingSupplier.value.id}`, body)
    else await http.post('/suppliers', body)
    showSupplier.value = false
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  }
}
async function copy(text: string) {
  try { await navigator.clipboard.writeText(text); message.success(t('已复制')) } catch { message.error(t('复制失败，请手动选择复制')) }
}
function resetLink(s: Supplier) {
  dialog.warning({ title: t('重新生成链接？'), content: t('旧链接会立即失效，需要把新链接发给供应商。'), positiveText: t('重新生成'), negativeText: t('取消'),
    onPositiveClick: async () => { await http.post(`/suppliers/${s.id}/link`); await load() } })
}
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Purchasing</div>
        <h1 class="page-title">{{ t('采购') }}</h1>
        <p class="muted">{{ t('按近 14 天的销量算出未来 30 天要备多少货，扣掉库存和在途数量；测款中的潜力款库存不足时也会提醒。采购单发给供应商后，供应商用专属链接确认交期、填写物流单号。') }}</p>
      </div>
      <NButton secondary @click="editSupplier()">{{ t('添加供应商') }}</NButton>
    </div>
    <NRadioGroup v-model:value="tab" size="small">
      <NRadioButton value="suggest">{{ t('补货建议') }} ({{ suggestions.length }})</NRadioButton>
      <NRadioButton value="orders">{{ t('采购单') }} ({{ orders.length }})</NRadioButton>
      <NRadioButton value="suppliers">{{ t('供应商') }} ({{ suppliers.length }})</NRadioButton>
    </NRadioGroup>

    <div v-if="loading" class="center"><NSpin /></div>

    <template v-else-if="tab === 'suggest'">
      <NEmpty v-if="!suggestions.length" :description="t('暂时不需要补货')" style="padding: 40px 0" />
      <NCard v-for="g in groups" :key="g.id ?? 0" size="small" :title="supplierName(g.id)">
        <template #header-extra><NButton size="small" type="primary" secondary @click="newOrder(g.id, g.rows)">{{ t('生成采购单') }}</NButton></template>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th /><th>{{ t('商品') }}</th><th class="r">{{ t('近 14 天销量') }}</th><th class="r">{{ t('库存') }}</th><th class="r">{{ t('在途') }}</th><th class="r">{{ t('建议采购') }}</th><th class="r">{{ t('成本（{0}）', [currency]) }}</th></tr></thead>
            <tbody>
              <tr v-for="s in g.rows" :key="s.variant_id">
                <td><NCheckbox :checked="!!picked[s.variant_id]" :aria-label="t('选择')" @update:checked="(v: boolean) => toggle(s, v)" /></td>
                <td>
                  <RouterLink :to="{ name: 'product-edit', params: { id: s.product_id } }" class="title">{{ s.title }}</RouterLink>
                  <span v-if="s.variant_title" class="muted"> · {{ s.variant_title }}</span>
                  <NTag v-if="s.hot" size="tiny" type="success" :bordered="false" style="margin-left: 6px">{{ t('潜力款') }}</NTag>
                  <div v-if="s.sku" class="muted small">{{ s.sku }}</div>
                </td>
                <td class="r num">{{ s.sold }}</td><td class="r num">{{ s.stock }}</td><td class="r num">{{ s.inbound || '' }}</td>
                <td class="r"><NInputNumber v-if="picked[s.variant_id]" v-model:value="picked[s.variant_id]" :min="1" size="small" :show-button="false" style="width: 80px; margin-left: auto" /><strong v-else class="num">{{ s.suggested }}</strong></td>
                <td class="r num">{{ s.cost ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="g.id === null" class="muted small">{{ t('这些商品还没有指定供应商。在供应商列表里可以把商品分给供应商。') }}</p>
      </NCard>
    </template>

    <template v-else-if="tab === 'orders'">
      <NEmpty v-if="!orders.length" :description="t('还没有采购单。在「补货建议」里勾选商品生成。')" style="padding: 40px 0" />
      <NCard v-for="o in orders" :key="o.id" size="small">
        <div class="po-head">
          <div>
            <strong>{{ o.name }}</strong> · {{ o.supplier }} <NTag size="small" :type="statusType[o.status]" :bordered="false">{{ statusLabel[o.status] ?? o.status }}</NTag>
            <div class="muted small">{{ t('{0} 件 · {1} {2} · 创建于 {3}', [o.units, currency, o.total, formatDate(o.created_at)]) }}<template v-if="o.expected_at"> · {{ t('预计发货 {0}', [o.expected_at]) }}</template><template v-if="o.tracking"> · {{ t('物流 {0}', [o.tracking]) }}</template></div>
          </div>
          <div class="actions">
            <NButton v-if="o.status === 'draft'" size="small" type="primary" @click="act(o, 'send')">{{ t('发给供应商') }}</NButton>
            <NButton v-if="['sent', 'confirmed', 'shipped'].includes(o.status)" size="small" type="primary" secondary @click="act(o, 'receive')">{{ t('收货入库') }}</NButton>
            <NButton v-if="['draft', 'sent', 'confirmed'].includes(o.status)" size="small" quaternary @click="act(o, 'cancel')">{{ t('取消') }}</NButton>
          </div>
        </div>
        <ul class="items"><li v-for="i in o.items" :key="i.variant_id">{{ i.title }}<span v-if="i.variant_title"> · {{ i.variant_title }}</span> × {{ i.quantity }} <span class="muted">@ {{ currency }} {{ i.cost }}</span></li></ul>
        <div v-if="o.supplier_note" class="muted small">{{ t('供应商备注：{0}', [o.supplier_note]) }}</div>
      </NCard>
    </template>

    <template v-else>
      <NEmpty v-if="!suppliers.length" :description="t('还没有供应商')" style="padding: 40px 0" />
      <NCard v-for="s in suppliers" :key="s.id" size="small">
        <div class="po-head">
          <div>
            <strong>{{ s.name }}</strong> <NTag v-if="s.platform" size="tiny" :bordered="false">{{ s.platform }}</NTag>
            <div class="muted small">{{ [s.contact_name, s.email, s.phone].filter(Boolean).join(' · ') || '—' }}</div>
            <div class="muted small">{{ t('{0} 个商品 · {1} 张进行中的采购单', [s.products, s.open_orders]) }}</div>
          </div>
          <div class="actions">
            <NButton size="small" secondary @click="copy(s.portal_url)">{{ t('复制供应商链接') }}</NButton>
            <NButton size="small" quaternary @click="editSupplier(s)">{{ t('编辑') }}</NButton>
            <NButton size="small" quaternary @click="resetLink(s)">{{ t('重新生成链接') }}</NButton>
          </div>
        </div>
        <p class="muted small">{{ t('供应商用这个链接查看采购单、确认交期、填写物流单号，还可以推荐新品（会进入「商品采集」待审核）。不需要注册账号。在商品页的「供应商」卡片里给商品指定供应商。') }}</p>
      </NCard>
    </template>

    <NModal v-model:show="showOrder" preset="card" :title="t('新建采购单')" style="max-width: 600px">
      <NForm label-placement="top">
        <NFormItem :label="t('供应商')"><NSelect v-model:value="orderForm.supplier_id" :options="suppliers.map((s) => ({ label: s.name, value: s.id }))" :placeholder="t('先添加供应商')" /></NFormItem>
        <table class="tbl">
          <thead><tr><th>{{ t('商品') }}</th><th class="r">{{ t('数量') }}</th><th class="r">{{ t('单价（{0}）', [currency]) }}</th></tr></thead>
          <tbody>
            <tr v-for="l in orderForm.lines" :key="l.variant_id">
              <td>{{ l.label }}</td>
              <td class="r"><NInputNumber v-model:value="l.quantity" :min="1" size="small" :show-button="false" style="width: 80px; margin-left: auto" /></td>
              <td class="r"><NInputNumber v-model:value="l.cost" :min="0" :precision="2" size="small" :show-button="false" clearable style="width: 100px; margin-left: auto" /></td>
            </tr>
          </tbody>
        </table>
        <NFormItem :label="t('给供应商的备注')" style="margin-top: 12px"><NInput v-model:value="orderForm.note" type="textarea" maxlength="1000" :autosize="{ minRows: 2 }" /></NFormItem>
      </NForm>
      <template #footer>
        <div class="foot"><NButton @click="showOrder = false">{{ t('取消') }}</NButton><NButton type="primary" :loading="creating" :disabled="!orderForm.supplier_id" @click="createOrder">{{ t('创建') }}</NButton></div>
      </template>
    </NModal>

    <NModal v-model:show="showSupplier" preset="card" :title="editingSupplier ? t('编辑供应商') : t('添加供应商')" style="max-width: 480px">
      <NForm label-placement="top">
        <NFormItem :label="t('名称')"><NInput v-model:value="supplierForm.name" maxlength="150" /></NFormItem>
        <div class="two">
          <NFormItem :label="t('联系人')"><NInput v-model:value="supplierForm.contact_name" /></NFormItem>
          <NFormItem :label="t('来源')"><NSelect v-model:value="supplierForm.platform" :options="[{ label: '1688', value: '1688' }, { label: 'Alibaba', value: 'alibaba' }, { label: t('工厂'), value: 'factory' }, { label: t('其他'), value: 'other' }]" /></NFormItem>
        </div>
        <div class="two">
          <NFormItem :label="t('邮箱（发采购单时通知）')"><NInput v-model:value="supplierForm.email" /></NFormItem>
          <NFormItem :label="t('电话 / 微信')"><NInput v-model:value="supplierForm.phone" /></NFormItem>
        </div>
        <NFormItem :label="t('备注')"><NInput v-model:value="supplierForm.note" type="textarea" maxlength="500" :autosize="{ minRows: 2 }" /></NFormItem>
      </NForm>
      <template #footer>
        <div class="foot"><NButton @click="showSupplier = false">{{ t('取消') }}</NButton><NButton type="primary" :disabled="!supplierForm.name.trim()" @click="saveSupplier">{{ t('保存') }}</NButton></div>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; max-width: 1100px; grid-template-columns: minmax(0, 1fr); }
.head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
.head > div { flex: 1 1 320px; min-width: 0; }
.page-title { margin: 0 0 4px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0; }
.small { font-size: 12px; }
.center { display: flex; justify-content: center; padding: 40px; }
.table-wrap { overflow-x: auto; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { text-align: left; font-weight: 500; color: var(--ink-muted); padding: 6px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.tbl td { padding: 6px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.r { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.title { color: var(--ink); font-weight: 500; }
.po-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
.actions { display: flex; gap: 6px; flex-wrap: wrap; }
.items { margin: 8px 0 0; padding-left: 18px; font-size: 13px; line-height: 1.7; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
@media (max-width: 640px) { .two { grid-template-columns: 1fr; } }
</style>
