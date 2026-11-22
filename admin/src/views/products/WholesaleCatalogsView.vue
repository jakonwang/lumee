<script setup lang="ts">
/**
 * Products › Wholesale catalogs (批发目录): private links with retail and wholesale prices in a
 * market's currency — a page for prospects (send it in outreach emails), and JSON / CSV feeds that
 * distributors import into their own systems.
 */
import { onMounted, reactive, ref } from 'vue'
import {
  NButton, NCard, NDatePicker, NEmpty, NForm, NFormItem, NInput, NModal, NSelect, NSpin, NSwitch, NTag, useDialog, useMessage,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Catalog { id: number; name: string; market_id: number | null; price_list_id: number | null; collection_id: number | null; lead_id: number | null; show_stock: boolean; expires_at: string | null; views: number; last_viewed_at: string | null; live: boolean; url: string; json: string; csv: string; order_key: string | null; orders_url: string; orders_count: number }
const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const rows = ref<Catalog[]>([])
const markets = ref<{ id: number; name: string; currency: string }[]>([])
const lists = ref<{ id: number; name: string; market_id: number | null; enabled: boolean }[]>([])
const collections = ref<{ id: number; title: string }[]>([])

async function load() {
  loading.value = true
  try {
    const d = (await http.get('/wholesale-catalogs')).data
    rows.value = d.data
    markets.value = d.markets
    lists.value = d.price_lists
    collections.value = d.collections
  } finally {
    loading.value = false
  }
}
onMounted(load)

const show = ref(false)
const editing = ref<Catalog | null>(null)
const form = reactive({ name: '', market_id: null as number | null, price_list_id: null as number | null, collection_id: null as number | null, show_stock: true, expires_at: null as number | null })
function open(c?: Catalog) {
  editing.value = c ?? null
  Object.assign(form, c ? { name: c.name, market_id: c.market_id, price_list_id: c.price_list_id, collection_id: c.collection_id, show_stock: c.show_stock, expires_at: c.expires_at ? Date.parse(c.expires_at) : null }
    : { name: '', market_id: null, price_list_id: null, collection_id: null, show_stock: true, expires_at: null })
  show.value = true
}
async function save() {
  try {
    const body = { ...form, expires_at: form.expires_at ? new Date(form.expires_at).toISOString().slice(0, 10) : null }
    if (editing.value) await http.put(`/wholesale-catalogs/${editing.value.id}`, body)
    else await http.post('/wholesale-catalogs', body)
    show.value = false
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  }
}
function remove(c: Catalog) {
  dialog.warning({ title: t('删除「{0}」？', [c.name]), content: t('链接和数据接口会立即失效。'), positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => { await http.delete(`/wholesale-catalogs/${c.id}`); await load() } })
}
async function copy(text: string) {
  try { await navigator.clipboard.writeText(text); message.success(t('已复制')) } catch { message.error(t('复制失败，请手动选择复制')) }
}
/** Distributor order API: a separate key, so a forwarded catalog link can't place orders. */
function toggleOrders(c: Catalog, enabled: boolean) {
  const run = async () => {
    try {
      const row: Catalog = (await http.post(`/wholesale-catalogs/${c.id}/order-key`, { enabled })).data.data
      Object.assign(c, row)
      message.success(enabled ? t('下单接口已开启，把密钥发给分销商') : t('下单接口已关闭'))
    } catch (e) {
      message.error(errorMessage(e))
    }
  }
  if (enabled && !c.order_key) return run()
  dialog.warning({ title: enabled ? t('重新生成密钥？') : t('关闭下单接口？'), content: t('旧密钥会立即失效，分销商需要换成新的密钥。'), positiveText: t('确定'), negativeText: t('取消'), onPositiveClick: run })
}
const marketName = (id: number | null) => (id ? markets.value.find((m) => m.id === id)?.name : t('默认站点'))
const listName = (id: number | null) => (id ? lists.value.find((l) => l.id === id)?.name : t('零售价'))
const listOptions = () => lists.value.filter((l) => l.market_id === null || l.market_id === form.market_id).map((l) => ({ label: l.name + (l.enabled ? '' : t('（已停用）')), value: l.id }))
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">B2B wholesale</div>
        <h1 class="page-title">{{ t('批发目录') }}</h1>
        <p class="muted">{{ t('生成私密链接，按某个市场的货币和价格表展示零售价、批发价、起订量和库存。发给潜在批发客户，或把 JSON / CSV 数据接口给分销商导入他们的系统。获客雷达里的线索也能一键生成。') }}</p>
      </div>
      <NButton type="primary" @click="open()">{{ t('新建目录') }}</NButton>
    </div>

    <div v-if="loading" class="center"><NSpin /></div>
    <NEmpty v-else-if="!rows.length" :description="t('还没有批发目录')" style="padding: 40px 0" />
    <NCard v-for="c in rows" v-else :key="c.id" size="small">
      <div class="row">
        <div>
          <strong>{{ c.name }}</strong>
          <NTag v-if="!c.live" size="small" type="error" :bordered="false">{{ t('已过期') }}</NTag>
          <NTag v-if="c.lead_id" size="small" :bordered="false">{{ t('来自获客雷达') }}</NTag>
          <div class="muted small">{{ marketName(c.market_id) }} · {{ listName(c.price_list_id) }}{{ c.expires_at ? ' · ' + t('{0} 到期', [formatDate(c.expires_at)]) : '' }}</div>
          <div class="muted small">{{ t('被打开 {0} 次', [c.views]) }}{{ c.last_viewed_at ? ' · ' + t('最近 {0}', [formatDate(c.last_viewed_at)]) : '' }}</div>
        </div>
        <div class="actions">
          <NButton size="small" secondary @click="copy(c.url)">{{ t('复制链接') }}</NButton>
          <NButton size="small" quaternary @click="copy(c.json)">JSON</NButton>
          <NButton size="small" quaternary @click="copy(c.csv)">CSV</NButton>
          <a :href="c.url" target="_blank" rel="noopener"><NButton size="small" quaternary>{{ t('预览') }}</NButton></a>
          <NButton size="small" quaternary @click="open(c)">{{ t('编辑') }}</NButton>
          <NButton size="small" quaternary type="error" @click="remove(c)">{{ t('删除') }}</NButton>
        </div>
      </div>
      <div class="api">
        <div class="api-head">
          <div>
            <strong>{{ t('下单接口') }}</strong>
            <span class="muted small"> · {{ c.order_key ? t('已开启 · 收到 {0} 个订单', [c.orders_count]) : t('分销商的系统可以按这个目录的价格直接下单') }}</span>
          </div>
          <NSwitch :value="!!c.order_key" size="small" @update:value="(v: boolean) => toggleOrders(c, v)" />
        </div>
        <template v-if="c.order_key">
          <div class="kv"><span class="muted small">{{ t('接口地址') }}</span><code>POST {{ c.orders_url }}</code><NButton size="tiny" quaternary @click="copy(c.orders_url)">{{ t('复制') }}</NButton></div>
          <div class="kv"><span class="muted small">{{ t('密钥') }}</span><code>Bearer {{ c.order_key }}</code><NButton size="tiny" quaternary @click="copy(c.order_key!)">{{ t('复制') }}</NButton><NButton size="tiny" quaternary @click="toggleOrders(c, true)">{{ t('重新生成') }}</NButton></div>
          <p class="muted small">{{ t('订单按目录里的批发价和起订量计算，进入「订单」后是待付款状态：确认运费和付款方式后再发货。同一个采购单号重复提交不会重复下单。') }}</p>
        </template>
      </div>
    </NCard>

    <NModal v-model:show="show" preset="card" :title="editing ? t('编辑目录') : t('新建目录')" style="max-width: 520px">
      <NForm label-placement="top">
        <NFormItem :label="t('名称（只在后台显示，例如客户名称）')"><NInput v-model:value="form.name" maxlength="120" /></NFormItem>
        <NFormItem :label="t('市场（决定货币和价格）')">
          <NSelect v-model:value="form.market_id" clearable :placeholder="t('默认站点')" :options="markets.map((m) => ({ label: `${m.name} · ${m.currency}`, value: m.id }))" @update:value="form.price_list_id = null" />
        </NFormItem>
        <NFormItem :label="t('批发价格表')"><NSelect v-model:value="form.price_list_id" clearable :placeholder="t('只显示零售价')" :options="listOptions()" /></NFormItem>
        <NFormItem :label="t('只包含这个分类（可选）')"><NSelect v-model:value="form.collection_id" clearable filterable :placeholder="t('全部在售商品')" :options="collections.map((c) => ({ label: c.title, value: c.id }))" /></NFormItem>
        <div class="two">
          <NFormItem :label="t('显示库存')"><NSwitch v-model:value="form.show_stock" /></NFormItem>
          <NFormItem :label="t('到期日（可选）')"><NDatePicker v-model:value="form.expires_at" type="date" clearable :is-date-disabled="(ts: number) => ts < Date.now()" /></NFormItem>
        </div>
      </NForm>
      <template #footer>
        <div class="foot"><NButton @click="show = false">{{ t('取消') }}</NButton><NButton type="primary" :disabled="!form.name.trim()" @click="save">{{ t('保存') }}</NButton></div>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; max-width: 1000px; grid-template-columns: minmax(0, 1fr); }
.head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
.head > div { flex: 1 1 320px; min-width: 0; }
.page-title { margin: 0 0 4px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0; }
.small { font-size: 12px; }
.center { display: flex; justify-content: center; padding: 40px; }
.row { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
.actions { display: flex; gap: 4px; flex-wrap: wrap; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
.api { margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--border, #EEF0EF); display: grid; gap: 6px; }
.api-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.kv { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
.kv code { font-size: 12px; background: #F4F6F5; padding: 2px 6px; border-radius: 4px; overflow-wrap: anywhere; min-width: 0; }
</style>
