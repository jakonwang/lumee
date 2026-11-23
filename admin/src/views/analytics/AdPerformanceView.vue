<script setup lang="ts">
/**
 * Analytics › Ad performance: what each ad platform cost and brought (orders, revenue, ROAS, cost per order),
 * by platform, country site and campaign, with plain-language findings. Spend comes from the platforms'
 * CSV exports (or typed in); orders are credited to the last non-direct visit.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { NAlert, NButton, NCard, NDatePicker, NEmpty, NInput, NInputNumber, NModal, NRadioButton, NRadioGroup, NSelect, NSkeleton, NTag, NUpload, useMessage, type UploadFileInfo } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

interface Row {
  key: string | number; label: string; spend: string; impressions: number; clicks: number; paid_sessions: number; organic_sessions: number
  orders: number; revenue: string; organic_orders: number; organic_revenue: string; roas: number | null; cpa: string | null; conversion: number | null; cpc: string | null
}
interface SpendLine { id: number; date: string; platform: string; market_id: number | null; campaign: string; spend: string; currency: string; amount: string; source: string }
interface Report {
  currency: string; range: [string, string]; totals: Row; platforms: Row[]; markets: Row[]; campaigns: Row[]; insights: string[]; has_spend: boolean
  spend_lines: SpendLine[]; markets_list: { id: number; name: string }[]; platform_labels: Record<string, string>
}

const message = useMessage()
const loading = ref(true)
const data = ref<Report | null>(null)
const days = ref<'7' | '30' | '90'>('30')
const tab = ref<'platforms' | 'markets' | 'campaigns'>('platforms')

async function load() {
  loading.value = true
  try {
    data.value = (await http.get('/ads/report', { params: { days: days.value } })).data
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(days, load)

const rows = computed(() => data.value?.[tab.value] ?? [])
const money = (v: string | null) => (v === null ? '—' : `${data.value?.currency ?? ''} ${v}`)
const roasClass = (r: number | null) => (r === null ? '' : r >= 2 ? 'good' : r < 1 ? 'bad' : '')
const tiles = computed(() => {
  const tt = data.value?.totals
  if (!tt) return []
  return [
    { label: t('广告花费'), value: money(tt.spend) },
    { label: t('广告带来的销售额'), value: money(tt.revenue), foot: t('{0} 单', [tt.orders]) },
    { label: 'ROAS', value: tt.roas === null ? '—' : String(tt.roas), foot: t('每花 1 元带来的销售额'), cls: roasClass(tt.roas) },
    { label: t('每单成本'), value: money(tt.cpa) },
    { label: t('广告访问'), value: String(tt.paid_sessions), foot: tt.conversion === null ? '' : t('转化率 {0}%', [tt.conversion]) },
    { label: t('自然流量订单'), value: String(tt.organic_orders), foot: t('来自这些平台、不花钱的访问') },
  ]
})

// Import
const platformOptions = computed(() => Object.entries(data.value?.platform_labels ?? {}).map(([value, label]) => ({ value, label })))
const imp = reactive({ open: false, platform: 'meta', text: '', file: null as File | null })
const importing = ref(false)
function onFile(list: UploadFileInfo[]) { imp.file = list[0]?.file ?? null }
async function runImport() {
  importing.value = true
  try {
    const body = new FormData()
    body.append('platform', imp.platform)
    if (imp.file) body.append('file', imp.file)
    else body.append('text', imp.text)
    const r = (await http.post('/ads/spend/import', body)).data
    message.success(t('已导入 {0} 行（{1} 至 {2}）', [r.imported, r.from, r.to]))
    Object.assign(imp, { open: false, text: '', file: null })
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    importing.value = false
  }
}

// Manual line
const manual = reactive({ open: false, date: Date.now(), platform: 'google', market_id: null as number | null, campaign: '', amount: 0, currency: '' })
async function addLine() {
  try {
    await http.post('/ads/spend', { ...manual, date: new Date(manual.date).toISOString().slice(0, 10), currency: manual.currency || data.value?.currency })
    manual.open = false
    message.success(t('已保存'))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  }
}
async function removeLine(id: number) {
  await http.delete(`/ads/spend/${id}`)
  await load()
}
const marketName = (id: number | null) => (id ? data.value?.markets_list.find((m) => m.id === id)?.name ?? '—' : t('全部'))
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Analytics</div>
        <h1 class="page-title">{{ t('广告效果') }}</h1>
        <p class="page-sub">{{ t('每个广告平台花了多少、带来多少订单和销售额，按平台、国家站点和广告系列看 ROAS 和每单成本。花费来自广告平台导出的报表；订单算给下单前最后一次非直接访问的来源。') }}</p>
      </div>
      <div class="actions">
        <NRadioGroup v-model:value="days" size="small">
          <NRadioButton value="7">{{ t('7 天') }}</NRadioButton>
          <NRadioButton value="30">{{ t('30 天') }}</NRadioButton>
          <NRadioButton value="90">{{ t('90 天') }}</NRadioButton>
        </NRadioGroup>
        <NButton size="small" @click="manual.open = true">{{ t('手动添加花费') }}</NButton>
        <NButton size="small" type="primary" @click="imp.open = true">{{ t('导入花费报表') }}</NButton>
      </div>
    </div>

    <NSkeleton v-if="loading && !data" :height="300" />
    <template v-else-if="data">
      <div class="tiles">
        <NCard v-for="it in tiles" :key="it.label" size="small">
          <div class="tile-label">{{ it.label }}</div>
          <div class="tile-value" :class="it.cls">{{ it.value }}</div>
          <div v-if="it.foot" class="tile-foot">{{ it.foot }}</div>
        </NCard>
      </div>

      <NAlert v-if="data.insights.length" type="info" :bordered="false" :title="t('建议')">
        <ul class="insights"><li v-for="(i, n) in data.insights" :key="n">{{ i }}</li></ul>
      </NAlert>

      <NCard size="small" content-style="padding: 0">
        <div class="tabs">
          <NRadioGroup v-model:value="tab" size="small">
            <NRadioButton value="platforms">{{ t('按平台') }}</NRadioButton>
            <NRadioButton value="markets">{{ t('按国家站点') }}</NRadioButton>
            <NRadioButton value="campaigns">{{ t('按广告系列') }}</NRadioButton>
          </NRadioGroup>
        </div>
        <div class="table-wrap">
          <table v-if="rows.length" class="tbl">
            <thead>
              <tr>
                <th>{{ tab === 'platforms' ? t('平台') : tab === 'markets' ? t('国家站点') : t('广告系列') }}</th>
                <th class="r">{{ t('花费') }}</th><th class="r">{{ t('广告访问') }}</th><th class="r">{{ t('订单') }}</th>
                <th class="r">{{ t('销售额') }}</th><th class="r">ROAS</th><th class="r">{{ t('每单成本') }}</th><th class="r">{{ t('转化率') }}</th>
                <th class="r">{{ t('点击单价') }}</th><th class="r">{{ t('自然流量订单') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rows" :key="r.key">
                <td class="name">{{ r.label }}</td>
                <td class="r">{{ money(r.spend) }}</td>
                <td class="r">{{ r.paid_sessions }}</td>
                <td class="r">{{ r.orders }}</td>
                <td class="r">{{ money(r.revenue) }}</td>
                <td class="r"><span class="roas" :class="roasClass(r.roas)">{{ r.roas ?? '—' }}</span></td>
                <td class="r">{{ money(r.cpa) }}</td>
                <td class="r">{{ r.conversion === null ? '—' : `${r.conversion}%` }}</td>
                <td class="r">{{ money(r.cpc) }}</td>
                <td class="r muted">{{ r.organic_orders }}</td>
              </tr>
            </tbody>
          </table>
          <NEmpty v-else :description="t('这段时间没有广告数据')" style="padding: 32px 0" />
        </div>
      </NCard>

      <NCard size="small" :title="t('花费记录')">
        <template #header-extra><span class="muted">{{ t('最近 100 行') }}</span></template>
        <div v-for="s in data.spend_lines" :key="s.id" class="line">
          <span>{{ s.date }}</span>
          <span>{{ data.platform_labels[s.platform] ?? s.platform }}</span>
          <span class="muted">{{ marketName(s.market_id) }}</span>
          <span class="camp">{{ s.campaign || '—' }}</span>
          <span class="r">{{ s.currency }} {{ s.amount }}</span>
          <NTag size="tiny" :bordered="false">{{ s.source === 'import' ? t('导入') : t('手动') }}</NTag>
          <button type="button" class="del" :aria-label="t('删除')" @click="removeLine(s.id)">×</button>
        </div>
        <NEmpty v-if="!data.spend_lines.length" :description="t('还没有花费记录')" size="small" />
        <p class="muted tip">{{ t('提示：在广告链接上加 UTM 参数（utm_source=facebook&utm_medium=cpc&utm_campaign=广告系列名），广告系列名和导入报表里的一致，就能按广告系列看效果。Google 和 TikTok 的点击 ID（gclid、ttclid）会自动识别。') }}</p>
      </NCard>
    </template>

    <NModal v-model:show="imp.open" preset="card" :title="t('导入花费报表')" style="max-width: 560px">
      <div class="form">
        <label>{{ t('广告平台') }}<NSelect v-model:value="imp.platform" :options="platformOptions" /></label>
        <NUpload :max="1" accept=".csv,.tsv,.txt" :default-upload="false" @update:file-list="onFile"><NButton>{{ t('选择 CSV 文件') }}</NButton></NUpload>
        <label>{{ t('或者粘贴报表内容') }}<NInput v-model:value="imp.text" type="textarea" :autosize="{ minRows: 4, maxRows: 10 }" :disabled="!!imp.file" /></label>
        <p class="muted">{{ t('导出时按“天”细分，需要包含日期和花费列；包含广告系列、国家/地区、展示次数、点击次数会更有用。支持中英文列名，金额会按汇率换算成店铺货币。同一份报表重复导入会覆盖，不会重复计算。') }}</p>
      </div>
      <template #footer><div class="foot"><NButton @click="imp.open = false">{{ t('取消') }}</NButton><NButton type="primary" :loading="importing" :disabled="!imp.file && !imp.text.trim()" @click="runImport">{{ t('导入') }}</NButton></div></template>
    </NModal>

    <NModal v-model:show="manual.open" preset="card" :title="t('手动添加花费')" style="max-width: 480px">
      <div class="form">
        <label>{{ t('日期') }}<NDatePicker v-model:value="manual.date" type="date" /></label>
        <label>{{ t('广告平台') }}<NSelect v-model:value="manual.platform" :options="platformOptions" /></label>
        <label>{{ t('国家站点（可选）') }}<NSelect v-model:value="manual.market_id" clearable :options="(data?.markets_list ?? []).map((m) => ({ value: m.id, label: m.name }))" /></label>
        <label>{{ t('广告系列（可选）') }}<NInput v-model:value="manual.campaign" maxlength="150" /></label>
        <div class="pair">
          <label>{{ t('金额') }}<NInputNumber v-model:value="manual.amount" :min="0" :precision="2" /></label>
          <label>{{ t('货币') }}<NInput v-model:value="manual.currency" :placeholder="data?.currency" maxlength="3" /></label>
        </div>
      </div>
      <template #footer><div class="foot"><NButton @click="manual.open = false">{{ t('取消') }}</NButton><NButton type="primary" @click="addLine">{{ t('保存') }}</NButton></div></template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; max-width: 720px; }
.actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; }
.tile-label { font-size: 13px; color: var(--ink-muted); }
.tile-value { font-family: var(--font-display); font-size: 24px; font-weight: 700; margin: 6px 0; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tile-value.good { color: var(--brand-700); }
.tile-value.bad { color: var(--danger); }
.tile-foot { font-size: 12px; color: var(--ink-muted); }
.insights { margin: 0; padding-left: 18px; line-height: 1.7; }
.tabs { padding: 12px 16px; border-bottom: 1px solid var(--border); }
.table-wrap { overflow-x: auto; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { text-align: left; font-weight: 500; color: var(--ink-muted); padding: 8px 12px; background: var(--surface-muted); white-space: nowrap; }
.tbl td { padding: 8px 12px; border-top: 1px solid var(--border); font-variant-numeric: tabular-nums; white-space: nowrap; }
.tbl .r { text-align: right; }
.tbl .name { font-weight: 500; max-width: 260px; overflow: hidden; text-overflow: ellipsis; }
.roas.good { color: var(--brand-700); font-weight: 600; }
.roas.bad { color: var(--danger); font-weight: 600; }
.muted { color: var(--ink-muted); font-size: 12px; }
.line { display: grid; grid-template-columns: 90px 80px 110px 1fr 120px 44px 20px; gap: 8px; align-items: center; font-size: 13px; padding: 6px 0; border-top: 1px solid var(--border); }
.line .r { text-align: right; font-variant-numeric: tabular-nums; }
.camp { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.del { background: none; border: 0; cursor: pointer; color: var(--ink-muted); font-size: 16px; }
.tip { margin: 10px 0 0; line-height: 1.6; }
.form { display: grid; gap: 12px; }
.form label { display: grid; gap: 4px; font-size: 13px; }
.pair { display: grid; grid-template-columns: 1fr 120px; gap: 8px; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
@media (max-width: 640px) { .line { grid-template-columns: 1fr 1fr; } }
</style>
