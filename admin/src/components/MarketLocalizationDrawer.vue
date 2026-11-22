<script setup lang="ts">
/**
 * Settings › Markets › Localize: make one market feel local — a regional preset to start from,
 * a link to its own store design, how import duties work, cash on delivery and installments,
 * the local payment methods to turn on, and the shopping events coming up there.
 */
import { reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import {
  NAlert, NButton, NDrawer, NDrawerContent, NFormItem, NInputNumber, NRadio, NRadioGroup, NSelect, NSwitch, NTabPane, NTabs, NTag, useMessage,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

interface Market { id: number; name: string; currency: string; url: string; settings: { duties: { mode: string; rate: number; threshold: string }; cod: { enabled: boolean; fee: string; max: string }; installments: { enabled: boolean; months: number; min: string; interest_free: boolean; available: boolean }; preset: string | null }; payments: { key: string; name: string; enabled: boolean }[] }
interface Preset { key: string; name: string; rounding: string; duty_mode: string; payments: string[]; tips: string[] }
interface Event { key: string; name: string; start: string; end: string; prep_from: string; days_left: number; preparing: boolean; tip: string }

const props = defineProps<{ market: Market | null; presets: Preset[]; themeId: number | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const message = useMessage()

const tab = ref('design')
const duties = reactive({ mode: 'none', rate: 0, threshold: 0 })
const cod = reactive({ enabled: false, fee: 0, max: 0 })
const installments = reactive({ enabled: false, months: 12, min: 0, interest_free: true })
const events = ref<Event[]>([])
const saving = ref(false)

function fill(m: Market) {
  Object.assign(duties, { mode: m.settings.duties.mode, rate: m.settings.duties.rate, threshold: Number(m.settings.duties.threshold) })
  Object.assign(cod, { enabled: m.settings.cod.enabled, fee: Number(m.settings.cod.fee), max: Number(m.settings.cod.max) })
  const i = m.settings.installments
  Object.assign(installments, { enabled: i.enabled, months: i.months, min: Number(i.min), interest_free: i.interest_free })
}
watch(() => props.market, async (m) => {
  if (!m) return
  fill(m)
  tab.value = 'design'
  events.value = (await http.get(`/markets/${m.id}/calendar`)).data.data
}, { immediate: true })

async function save() {
  saving.value = true
  try {
    await http.put(`/markets/${props.market!.id}/localization`, { duties, cod, installments })
    message.success(t('已保存'))
    emit('saved')
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}
async function applyPreset(key: string) {
  try {
    const m: Market = (await http.post(`/markets/${props.market!.id}/preset`, { preset: key })).data.data
    fill(m)
    message.success(t('已套用预设：公告、购买保障、价格尾数和关税方式已更新，可以继续修改'))
    tab.value = 'design'
    emit('saved')
  } catch (e) {
    message.error(errorMessage(e))
  }
}
// Automatic design of this market (draft), then open it in the editor
const router = useRouter()
const designing = ref(false)
async function autoDesign() {
  if (!props.themeId || !props.market) return
  designing.value = true
  try {
    await http.post('/theme/generate', { id: props.themeId, market: props.market.id })
    message.success(t('已生成「{0}」站点的装修草稿，请检查后发布', [props.market.name]))
    router.push({ name: 'theme-editor', params: { id: props.themeId }, query: { market: String(props.market.id) } })
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    designing.value = false
  }
}
const modeLabels: Record<string, string> = { none: t('不处理'), included: t('已含在售价里'), checkout: t('结账时收取'), on_delivery: t('买家收货时付') }
</script>

<template>
  <NDrawer :show="!!market" width="min(680px, 100vw)" @update:show="(v: boolean) => { if (!v) emit('close') }">
    <NDrawerContent v-if="market" :title="t('本地化 · {0}', [market.name])" closable>
      <NTabs v-model:value="tab" type="line" size="small">
        <NTabPane name="design" :tab="t('店铺装修')">
          <p class="muted">{{ t('这个市场的公告、购买保障、颜色、首页文字和图片，都在店铺装修里单独设置：没改过的内容跟随主站点，也可以给这个市场做一个完全不同的首页。') }}</p>
          <div class="design-links">
            <RouterLink v-if="themeId" :to="{ name: 'theme-editor', params: { id: themeId }, query: { market: String(market.id) } }">
              <NButton type="primary">{{ t('装修「{0}」站点', [market.name]) }}</NButton>
            </RouterLink>
            <NButton v-if="themeId" secondary :loading="designing" @click="autoDesign">{{ t('自动装修这个市场') }}</NButton>
            <a :href="market.url" target="_blank" rel="noopener"><NButton quaternary>{{ t('打开这个市场的店铺') }}</NButton></a>
          </div>
          <p class="muted small" style="margin-top: 10px">{{ t('自动装修：按这个市场的地区套用风格包，用当地语言生成首页模块和文案（免邮门槛、退货天数、分期等数字取自你的设置），并用热卖商品合成首屏横幅。生成的是草稿，检查后再发布。') }}</p>
        </NTabPane>

        <NTabPane name="duties" :tab="t('关税')">
          <NRadioGroup v-model:value="duties.mode">
            <div class="radios">
              <NRadio v-for="(label, k) in modeLabels" :key="k" :value="k">{{ label }}</NRadio>
            </div>
          </NRadioGroup>
          <NAlert type="info" :bordered="false" style="margin: 12px 0">
            <template v-if="duties.mode === 'included'">{{ t('关税已经算进售价（用市场的「价格调整」覆盖）。结账页会告诉买家收货时不用再付钱。') }}</template>
            <template v-else-if="duties.mode === 'checkout'">{{ t('结账时按商品金额（折扣后）收取一行「进口关税」，低于免税额的订单不收。') }}</template>
            <template v-else-if="duties.mode === 'on_delivery'">{{ t('不在店里收，结账页提示买家：快递可能在派送时收取关税和税费。') }}</template>
            <template v-else>{{ t('结账页不显示任何关税信息。') }}</template>
          </NAlert>
          <div v-if="duties.mode === 'checkout'" class="two">
            <NFormItem :label="t('税率（%）')"><NInputNumber v-model:value="duties.rate" :min="0" :max="100" :precision="2" style="width: 100%" /></NFormItem>
            <NFormItem :label="t('免税额（{0}，商品金额不超过就不收）', [market.currency])"><NInputNumber v-model:value="duties.threshold" :min="0" :precision="2" style="width: 100%" /></NFormItem>
          </div>
          <p class="muted">{{ t('各国关税政策变化很快（例如美国已取消小额包裹免税，欧盟也在调整 150 欧元以下包裹的规则），税率请以当地海关和物流商的最新规定为准。') }}</p>
        </NTabPane>

        <NTabPane name="payments" :tab="t('当地支付')">
          <p class="muted">{{ t('通过 Stripe 收款时，买家能用这些 {0} 支付方式。在收款设置里勾选后生效。', [market.currency]) }}</p>
          <div class="pay-list">
            <div v-for="p in market.payments" :key="p.key" class="pay">
              <span>{{ p.name }}</span>
              <NTag size="small" :type="p.enabled ? 'success' : 'default'" :bordered="false">{{ p.enabled ? t('已开启') : t('未开启') }}</NTag>
            </div>
            <p v-if="!market.payments.length" class="muted">{{ t('没有专门针对 {0} 的支付方式，买家可以用银行卡和 PayPal。', [market.currency]) }}</p>
          </div>
          <RouterLink :to="{ name: 'settings-payments' }" class="link">{{ t('去收款设置') }}</RouterLink>

          <div class="cod">
            <div class="cod-head">
              <div>
                <strong>{{ t('货到付款') }}</strong>
                <div class="muted small">{{ t('买家收货时把现金交给快递员。订单先是「待付款」，收到货款后在订单页标记为已付款。只对寄到这个市场国家、并填写了电话的订单开放。') }}</div>
              </div>
              <NSwitch v-model:value="cod.enabled" />
            </div>
            <div v-if="cod.enabled" class="two">
              <NFormItem :label="t('手续费（{0}，0 = 不收）', [market.currency])"><NInputNumber v-model:value="cod.fee" :min="0" :precision="2" style="width: 100%" /></NFormItem>
              <NFormItem :label="t('订单金额上限（{0}，0 = 不限）', [market.currency])"><NInputNumber v-model:value="cod.max" :min="0" :precision="2" style="width: 100%" /></NFormItem>
            </div>
            <p v-if="cod.enabled" class="muted small">{{ t('货到付款的拒收率通常比预付高，建议设置金额上限，并在发货前电话或短信确认。') }}</p>
          </div>

          <div v-if="market.settings.installments.available" class="cod">
            <div class="cod-head">
              <div>
                <strong>{{ market.currency === 'BRL' ? t('分期付款（parcelamento）') : t('分期付款（MSI）') }}</strong>
                <div class="muted small">{{ t('通过 Stripe 收款时，买家可以在付款页选择银行卡分期；商品页显示“或分 N 期，每期 X”。需要这个国家的 Stripe 账户，并在 Stripe 后台开通分期。') }}</div>
              </div>
              <NSwitch v-model:value="installments.enabled" />
            </div>
            <div v-if="installments.enabled" class="two">
              <NFormItem :label="t('商品页显示的期数')"><NSelect v-model:value="installments.months" :options="[3, 6, 9, 12, 18, 24].map((n) => ({ label: t('{0} 期', [n]), value: n }))" /></NFormItem>
              <NFormItem :label="t('最低金额（{0}）', [market.currency])"><NInputNumber v-model:value="installments.min" :min="0" :precision="2" style="width: 100%" /></NFormItem>
            </div>
            <p v-if="installments.enabled" class="muted small" style="margin: -8px 0 0">{{ t('低于最低金额的商品不显示分期，订单也不分期。') }}</p>
            <NFormItem v-if="installments.enabled" :label="t('免息（商家承担分期手续费）')"><NSwitch v-model:value="installments.interest_free" /></NFormItem>
          </div>
        </NTabPane>

        <NTabPane name="presets" :tab="t('区域预设')">
          <p class="muted">{{ t('按地区一键套用：价格尾数、当地语言的公告和购买保障、关税处理方式。套用后可以继续修改。') }}</p>
          <div class="presets">
            <div v-for="p in presets" :key="p.key" class="preset" :class="{ on: market.settings.preset === p.key }">
              <div class="preset-head">
                <strong>{{ p.name }}</strong>
                <NButton size="small" :type="market.settings.preset === p.key ? 'default' : 'primary'" secondary @click="applyPreset(p.key)">{{ market.settings.preset === p.key ? t('重新套用') : t('套用') }}</NButton>
              </div>
              <div class="muted small">{{ t('价格尾数 {0} · 关税：{1} · 推荐支付：{2}', [p.rounding, modeLabels[p.duty_mode], p.payments.join('、')]) }}</div>
              <ul class="tips"><li v-for="(tip, i) in p.tips" :key="i">{{ tip }}</li></ul>
            </div>
          </div>
        </NTabPane>

        <NTabPane name="calendar" :tab="t('活动日历')">
          <p class="muted">{{ t('这个市场接下来的购物节点。标绿的已经到了准备期：该选品、做活动页、备货和准备广告了。') }}</p>
          <div v-for="e in events" :key="e.key" class="event" :class="{ prep: e.preparing }">
            <div class="ev-date"><strong>{{ e.start.slice(5) }}</strong><span class="muted small">{{ e.start.slice(0, 4) }}</span></div>
            <div class="ev-body">
              <div class="ev-title">{{ e.name }} <NTag v-if="e.preparing" size="small" type="success" :bordered="false">{{ t('准备中') }}</NTag></div>
              <div class="muted small">{{ e.days_left > 0 ? t('还有 {0} 天 · {1} 起开始准备', [e.days_left, e.prep_from]) : t('进行中') }}</div>
              <div class="small">{{ e.tip }}</div>
            </div>
          </div>
          <p v-if="!events.length" class="muted">{{ t('接下来半年没有特别的节点。') }}</p>
        </NTabPane>
      </NTabs>
      <template #footer>
        <div class="foot"><NButton @click="emit('close')">{{ t('关闭') }}</NButton><NButton type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton></div>
      </template>
    </NDrawerContent>
  </NDrawer>
</template>

<style scoped>
.design-links { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.cod { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border, #E5E7EB); display: grid; gap: 12px; }
.cod-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 8px 0 12px; }
.small { font-size: 12px; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
.radios { display: grid; gap: 8px; }
.link { color: var(--brand-700); font-size: 13px; }
.pay-list { display: grid; gap: 6px; margin-bottom: 12px; }
.pay { display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; }
.presets { display: grid; gap: 10px; }
.preset { border: 1px solid var(--border); border-radius: 10px; padding: 12px; }
.preset.on { border-color: var(--brand-600); }
.preset-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.tips { margin: 8px 0 0; padding-left: 18px; font-size: 13px; line-height: 1.6; }
.event { display: flex; gap: 14px; padding: 10px 0; border-bottom: 1px solid var(--border); }
.event.prep .ev-date strong { color: var(--brand-700); }
.ev-date { display: grid; width: 52px; flex-shrink: 0; }
.ev-date strong { font-size: 16px; }
.ev-body { display: grid; gap: 2px; }
.ev-title { font-weight: 600; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
@media (max-width: 640px) { .two { grid-template-columns: 1fr; } }
</style>
