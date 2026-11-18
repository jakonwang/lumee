<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { NCard, NInput, NInputNumber, NButton, NIcon, NSkeleton, NSwitch, NSelect, NCheckbox, NModal, NAlert, useMessage } from 'naive-ui'
import { ArrowBackOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import ProductPicker from '@/components/ProductPicker.vue'
import type { CollectionProduct } from '@/api/collections'
import { t } from '@/i18n'

interface Step {
  id?: number
  delay_hours: number
  subject: string
  preheader: string | null
  heading: string | null
  body: string
  button_label: string | null
  button_url: string | null
  discount_code: string | null
  product_ids: number[]
  products: { id: number; title: string; featured_image: string | null }[]
  sent_count?: number
  opened_count?: number
  clicked_count?: number
}

const route = useRoute()
const router = useRouter()
const message = useMessage()
const id = Number(route.params.id)
const loading = ref(true)
const saving = ref(false)
const dirty = ref(false)
const flow = reactive({ type: '', label: '', description: '', enabled: false, stop_on_order: true, win_back_days: 90, browse_hours: 4 })
const browseOptions = [1, 2, 4, 8, 24].map((h) => ({ label: t('{0} 小时', [h]), value: h }))
const steps = ref<Step[]>([])
const open = ref(0)
const pickerFor = ref<number | null>(null)
const previewHtml = ref('')
const previewOpen = ref(false)

const winBackOptions = [30, 60, 90, 180].map((d) => ({ value: d, label: t('{0} 天', [d]) }))
const unitOptions = [{ value: 1, label: t('小时') }, { value: 24, label: t('天') }]
const delays = ref<{ amount: number; unit: number }[]>([])

const totalDays = computed(() => Math.round(delays.value.reduce((n, d) => n + d.amount * d.unit, 0) / 24 * 10) / 10)

function apply(d: Record<string, unknown> & { steps: Step[] }) {
  Object.assign(flow, { type: d.type, label: d.label, description: d.description, enabled: d.enabled, stop_on_order: d.stop_on_order, win_back_days: d.win_back_days, browse_hours: d.browse_hours ?? 4 })
  steps.value = d.steps.map((s) => ({ ...s, body: s.body ?? '', product_ids: s.product_ids ?? [], products: s.products ?? [] }))
  delays.value = steps.value.map((s) => (s.delay_hours % 24 === 0 && s.delay_hours > 0 ? { amount: s.delay_hours / 24, unit: 24 } : { amount: s.delay_hours, unit: 1 }))
}

async function load() {
  try {
    apply((await http.get(`/flows/${id}`)).data.data)
  } finally {
    loading.value = false
    setTimeout(() => { dirty.value = false })
  }
}

watch([flow, steps, delays], () => { dirty.value = true }, { deep: true })

function addStep() {
  steps.value.push({ delay_hours: 72, subject: '', preheader: null, heading: null, body: '', button_label: null, button_url: null, discount_code: null, product_ids: [], products: [] })
  delays.value.push({ amount: 3, unit: 24 })
  open.value = steps.value.length - 1
}

function removeStep(i: number) {
  steps.value.splice(i, 1)
  delays.value.splice(i, 1)
  open.value = Math.max(0, i - 1)
}

function addProducts(picked: CollectionProduct[]) {
  const s = steps.value[pickerFor.value ?? 0]
  for (const p of picked) {
    if (s.products.length >= 4) break
    if (!s.products.some((x) => x.id === p.id)) s.products.push({ id: p.id, title: p.title, featured_image: p.featured_image })
  }
}

async function save(): Promise<boolean> {
  saving.value = true
  try {
    const body = {
      enabled: flow.enabled, stop_on_order: flow.stop_on_order, win_back_days: flow.win_back_days, browse_hours: flow.browse_hours,
      steps: steps.value.map((s, i) => ({ ...s, delay_hours: delays.value[i].amount * delays.value[i].unit, product_ids: s.products.map((p) => p.id), products: undefined })),
    }
    apply((await http.put(`/flows/${id}`, body)).data.data)
    setTimeout(() => { dirty.value = false })
    message.success(t('已保存'))
    return true
  } catch (e) {
    message.error(errorMessage(e))
    return false
  } finally {
    saving.value = false
  }
}

async function preview(i: number) {
  if (dirty.value && !(await save())) return
  previewHtml.value = (await http.get(`/campaigns/${steps.value[i].id}/preview`, { responseType: 'text' })).data
  previewOpen.value = true
}

async function test(i: number) {
  if (dirty.value && !(await save())) return
  try {
    message.success((await http.post(`/campaigns/${steps.value[i].id}/test`)).data.message)
  } catch (e) {
    message.error(errorMessage(e))
  }
}

function delayText(i: number) {
  const d = delays.value[i]
  if (!d || d.amount === 0) return i === 0 ? t('立即发送') : t('紧接上一封')
  const what = d.unit === 24 ? t('{0} 天', [d.amount]) : t('{0} 小时', [d.amount])
  return i === 0 ? t('{0}后', [what]) : t('上一封之后 {0}', [what])
}

onBeforeRouteLeave(() => (dirty.value ? window.confirm(t('有未保存的修改，确定离开吗？')) : true))
onMounted(load)
</script>

<template>
  <div class="editor">
    <NButton text class="back" @click="router.push({ name: 'flows' })">
      <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
      {{ t('自动化') }}
    </NButton>

    <NSkeleton v-if="loading" text :repeat="6" />
    <template v-else>
      <div class="head">
        <div>
          <h1 class="page-title">{{ flow.label }}</h1>
          <p class="page-sub">{{ flow.description }}</p>
        </div>
        <div class="actions">
          <span class="switch"><NSwitch v-model:value="flow.enabled" /> {{ flow.enabled ? t('已开启') : t('未开启') }}</span>
          <NButton type="primary" :loading="saving" :disabled="!dirty" @click="save">{{ t('保存') }}</NButton>
        </div>
      </div>

      <NCard size="small" :title="t('触发条件')">
        <p v-if="flow.type === 'welcome'" class="rule">{{ t('有人订阅邮件时（弹窗、首页订阅区块、结账页勾选、账户页）。') }}</p>
        <p v-else-if="flow.type === 'post_purchase'" class="rule">{{ t('顾客第一笔订单付款成功后。同一位顾客只会收到一次。') }}</p>
        <p v-else-if="flow.type === 'birthday'" class="rule">{{ t('顾客生日当天（按店铺时区）。顾客在账户页填写生日，也可以在后台客户详情里填写。每年发一次。建议在邮件里填一个折扣码。') }}</p>
        <div v-else-if="flow.type === 'browse_abandon'" class="rule row">
          <span>{{ t('已登录的顾客浏览商品后') }}</span>
          <NSelect v-model:value="flow.browse_hours" :options="browseOptions" style="width: 110px" />
          <span>{{ t('还没有下单（同一位顾客每周最多一次，邮件里会放他看过的商品）') }}</span>
        </div>
        <div v-else class="rule row">
          <span>{{ t('顾客最后一次下单已经超过') }}</span>
          <NSelect v-model:value="flow.win_back_days" :options="winBackOptions" style="width: 110px" />
          <span>{{ t('（每次流失只发一次，再次下单后停止）') }}</span>
        </div>
        <NCheckbox v-if="flow.type === 'welcome'" v-model:checked="flow.stop_on_order" style="margin-top: 8px">{{ t('顾客下单后，不再发送后面的欢迎邮件') }}</NCheckbox>
        <p class="muted small">{{ t('只发给同意接收营销邮件的顾客；顾客退订后立即停止。整个流程约 {0} 天。', [totalDays]) }}</p>
      </NCard>

      <div class="timeline">
        <div v-for="(s, i) in steps" :key="s.id ?? `new-${i}`" class="step">
          <div class="when">{{ delayText(i) }}</div>
          <NCard size="small" class="step-card" :class="{ open: open === i }">
            <div class="step-head" @click="open = open === i ? -1 : i">
              <span class="num">{{ i + 1 }}</span>
              <div class="step-title">
                <strong>{{ s.subject || t('（未填写主题）') }}</strong>
                <span v-if="s.sent_count" class="muted small">{{ t('已发送 {0} · 打开 {1} · 点击 {2}', [s.sent_count, s.opened_count ?? 0, s.clicked_count ?? 0]) }}</span>
              </div>
              <span class="chev">{{ open === i ? '−' : '+' }}</span>
            </div>
            <div v-if="open === i" class="step-body">
              <div class="row">
                <span>{{ i === 0 ? t('触发后等待') : t('上一封之后等待') }}</span>
                <NInputNumber v-model:value="delays[i].amount" :min="0" :max="delays[i].unit === 24 ? 90 : 2160" style="width: 100px" />
                <NSelect v-model:value="delays[i].unit" :options="unitOptions" style="width: 90px" />
              </div>
              <label class="lbl">{{ t('邮件主题') }}<NInput v-model:value="s.subject" maxlength="150" /></label>
              <label class="lbl">{{ t('预览文字（选填）') }}<NInput v-model:value="s.preheader" maxlength="150" /></label>
              <label class="lbl">{{ t('大标题') }}<NInput v-model:value="s.heading" maxlength="150" /></label>
              <label class="lbl">{{ t('正文（Markdown）') }}<NInput v-model:value="s.body" type="textarea" :autosize="{ minRows: 5, maxRows: 16 }" maxlength="50000" class="md" /></label>
              <div class="grid2">
                <label class="lbl">{{ t('按钮文字') }}<NInput v-model:value="s.button_label" maxlength="60" /></label>
                <label class="lbl">{{ t('按钮链接') }}<NInput v-model:value="s.button_url" maxlength="500" placeholder="/collections/all" /></label>
              </div>
              <label class="lbl">{{ t('附带折扣码（选填）') }}<NInput v-model:value="s.discount_code" maxlength="40" clearable :placeholder="t('例如 WELCOME10，需要先在「折扣」里创建')" /></label>
              <div class="lbl">
                <div class="row between"><span>{{ t('推荐商品（最多 4 件）') }}</span><NButton size="tiny" :disabled="s.products.length >= 4" @click="pickerFor = i">+ {{ t('添加商品') }}</NButton></div>
                <div v-if="s.products.length" class="chips">
                  <span v-for="(p, j) in s.products" :key="p.id" class="chip">{{ p.title }} <button type="button" @click="s.products.splice(j, 1)">×</button></span>
                </div>
              </div>
              <div class="row step-actions">
                <NButton size="small" :disabled="!s.id && !dirty" @click="preview(i)">{{ t('预览') }}</NButton>
                <NButton size="small" @click="test(i)">{{ t('发测试邮件给我') }}</NButton>
                <NButton v-if="steps.length > 1" size="small" quaternary type="error" @click="removeStep(i)">{{ t('删除这封') }}</NButton>
              </div>
            </div>
          </NCard>
        </div>
        <NButton v-if="steps.length < 5" dashed block @click="addStep">+ {{ t('再加一封邮件') }}</NButton>
      </div>

      <NAlert v-if="!flow.enabled" type="info" :bordered="false">{{ t('开启后才会开始发送；开启之前订阅或下单的顾客不会收到。') }}</NAlert>
    </template>

    <NModal v-model:show="previewOpen" preset="card" :title="t('预览')" style="max-width: 640px">
      <iframe class="preview" :srcdoc="previewHtml" sandbox="" title="preview" />
    </NModal>
    <ProductPicker :show="pickerFor !== null" :exclude="pickerFor !== null ? steps[pickerFor].products.map((p) => p.id) : []"
      endpoint="/promotions/products" @update:show="(v: boolean) => { if (!v) pickerFor = null }" @pick="addProducts" />
  </div>
</template>

<style scoped>
.back { margin-bottom: 12px; color: var(--ink-muted); }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; }
.actions { display: flex; align-items: center; gap: 12px; }
.switch { display: inline-flex; align-items: center; gap: 8px; font-size: 14px; }
.editor > .n-card, .editor > .n-alert { margin-bottom: 16px; }
.rule { margin: 0; font-size: 14px; }
.row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 14px; }
.row.between { justify-content: space-between; }
.muted { color: var(--ink-muted); }
.small { font-size: 12px; }
p.small { margin: 10px 0 0; }
.timeline { display: grid; gap: 4px; margin-bottom: 16px; }
.step { display: grid; gap: 6px; }
.when { font-size: 12px; color: var(--ink-muted); padding: 6px 0 0 14px; border-left: 2px solid var(--border); margin-left: 18px; }
.step-head { display: flex; align-items: center; gap: 12px; cursor: pointer; }
.num { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; background: var(--brand-50, #EAF7F1); color: var(--brand-700, #0A7350); font-size: 13px; font-weight: 600; flex: none; }
.step-title { display: grid; gap: 2px; flex: 1; min-width: 0; }
.chev { color: var(--ink-muted); font-size: 18px; }
.step-body { display: grid; gap: 12px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); }
.lbl { display: grid; gap: 6px; font-size: 13px; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.md :deep(textarea) { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; line-height: 1.6; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 999px; background: var(--surface-muted); font-size: 12px; }
.chip button { border: 0; background: none; cursor: pointer; color: var(--ink-muted); }
.step-actions { justify-content: flex-end; }
.preview { width: 100%; height: 600px; border: 0; }
@media (max-width: 640px) { .grid2 { grid-template-columns: 1fr; } }
</style>
