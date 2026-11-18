<script setup lang="ts">
/** One customer: stats, order history, contact + address, marketing consent, tags and private note. */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NButton, NCard, NDatePicker, NIcon, NInput, NModal, NSelect, NSkeleton, NSwitch, NTag, NEmpty, useMessage } from 'naive-ui'
import { ArrowBackOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { type Order, financialLabel, fulfillmentLabel, formatDate } from '@/api/orders'
import { t } from '@/i18n'
import CustomerPointsCard from '@/components/CustomerPointsCard.vue'

interface Detail {
  id: number; name: string; email: string; phone: string | null; first_name: string | null; last_name: string | null
  orders_count: number; total_spent: string; aov: string; account: 'none' | 'unverified' | 'active'
  accepts_marketing: boolean; marketing_source: string | null; marketing_updated_at: string | null
  tags: string[]; note: string | null; default_address: Record<string, string | null> | null
  birthday?: string | null
  tax_exempt: boolean; company: { id: number; name: string; payment_terms: number | null; tax_exempt: boolean } | null
  first_order_at: string | null; last_order_at: string | null; last_login_at: string | null; created_at: string
  orders: Order[]
}

const route = useRoute()
const router = useRouter()
const message = useMessage()
const c = ref<Detail | null>(null)
const note = ref('')
const tags = ref<string[]>([])
const allTags = ref<string[]>([])
const saving = ref(false)

async function load() {
  try {
    const { data } = await http.get(`/customers/${route.params.id}`)
    apply(data.data)
  } catch (e) {
    message.error(errorMessage(e))
    router.replace({ name: 'customers' })
  }
  allTags.value = (await http.get('/customers/tags')).data.data.map((x: { tag: string }) => x.tag)
}
function apply(d: Detail) {
  c.value = d
  note.value = d.note ?? ''
  tags.value = [...d.tags]
}
onMounted(load)

async function save(patch: Record<string, unknown>, ok = t('已保存')) {
  saving.value = true
  try {
    const { data } = await http.patch(`/customers/${c.value!.id}`, patch)
    apply(data.data)
    message.success(ok)
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}
// Data & privacy (GDPR)
const eraseOpen = ref(false)
const eraseConfirm = ref('')
async function exportData() {
  try {
    const res = await http.get(`/customers/${c.value!.id}/data`, { responseType: 'blob' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(res.data)
    a.download = `customer-${c.value!.id}-data.json`
    a.click()
    URL.revokeObjectURL(a.href)
  } catch (e) {
    message.error(errorMessage(e))
  }
}
async function eraseData() {
  try {
    await http.post(`/customers/${c.value!.id}/erase`, { confirm: eraseConfirm.value.trim() })
    message.success(t('客户的个人数据已删除'))
    router.push({ name: 'customers' })
  } catch (e) {
    message.error(errorMessage(e))
  }
}

function setBirthday(v: string | null) {
  save({ birthday: v || null })
}
const noteDirty = computed(() => (c.value?.note ?? '') !== note.value)
const tagsDirty = computed(() => JSON.stringify(c.value?.tags ?? []) !== JSON.stringify(tags.value))
const tagOptions = computed(() => allTags.value.map((x) => ({ label: x, value: x })))

const currency = computed(() => c.value?.orders[0]?.currency ?? '')
const address = computed(() => {
  const a = c.value?.default_address
  if (!a) return []
  return [[a.first_name, a.last_name].filter(Boolean).join(' '), a.address1, a.address2, [a.city, a.province, a.zip].filter(Boolean).join(', '), a.country, a.phone]
    .filter((x): x is string => !!x)
})
const accountLabel = { active: t('已注册'), unverified: t('待验证邮箱'), none: t('游客') }
const sourceLabel: Record<string, string> = { checkout: t('结账时勾选'), popup: t('弹窗订阅'), footer: t('页脚订阅'), account: t('账户设置'), admin: t('商家后台'), unsubscribe: t('退订链接') }
</script>

<template>
  <div>
    <NButton text class="back" @click="router.push({ name: 'customers' })">
      <template #icon><NIcon><ArrowBackOutline /></NIcon></template>{{ t('客户') }}
    </NButton>
    <NSkeleton v-if="!c" height="320px" />
    <template v-else>
      <div class="head">
        <div>
          <h1 class="page-title">{{ c.name || c.email }}</h1>
          <p class="page-sub">{{ c.email }} · {{ accountLabel[c.account] }} · {{ t('{0} 成为客户', [formatDate(c.created_at)]) }}</p>
        </div>
        <NButton type="primary" @click="router.push({ name: 'order-new', query: { email: c.email } })">{{ t('创建订单') }}</NButton>
      </div>

      <div class="stats">
        <div class="stat"><div class="label">{{ t('消费金额') }}</div><div class="value">{{ currency }} {{ c.total_spent }}</div></div>
        <div class="stat"><div class="label">{{ t('订单数') }}</div><div class="value">{{ c.orders_count }}</div></div>
        <div class="stat"><div class="label">{{ t('客单价') }}</div><div class="value">{{ currency }} {{ c.aov }}</div></div>
        <div class="stat"><div class="label">{{ t('最近下单') }}</div><div class="value small">{{ c.last_order_at ? formatDate(c.last_order_at) : '—' }}</div></div>
      </div>

      <div class="grid">
        <div class="main">
          <NCard :title="t('订单')" size="small" content-style="padding: 0">
            <NEmpty v-if="!c.orders.length" :description="t('还没有订单')" size="small" style="padding: 24px" />
            <table v-else class="tbl">
              <tbody>
                <tr v-for="o in c.orders" :key="o.id" @click="router.push({ name: 'order', params: { id: o.id } })">
                  <td><strong>{{ o.name }}</strong><div class="muted">{{ formatDate(o.created_at) }}</div></td>
                  <td>
                    <NTag v-if="o.cancelled_at" size="small" :bordered="false">{{ t('已取消') }}</NTag>
                    <template v-else>
                      <NTag size="small" :bordered="false" :type="financialLabel[o.financial_status].type">{{ financialLabel[o.financial_status].label }}</NTag>
                      <NTag size="small" :bordered="false" :type="fulfillmentLabel[o.fulfillment_status].type" style="margin-left: 4px">{{ fulfillmentLabel[o.fulfillment_status].label }}</NTag>
                    </template>
                  </td>
                  <td class="muted">{{ t('{0} 件商品', [o.items_count ?? 0]) }}</td>
                  <td class="num">{{ o.currency }} {{ o.total }}</td>
                </tr>
              </tbody>
            </table>
            <div v-if="c.orders_count > c.orders.length" class="foot">{{ t('显示最近 {0} 笔', [c.orders.length]) }}</div>
          </NCard>

          <NCard :title="t('备注')" size="small">
            <NInput v-model:value="note" type="textarea" :autosize="{ minRows: 3, maxRows: 10 }" maxlength="5000" :placeholder="t('只有店铺员工能看到，例如：偏好的快递、尺码、沟通记录')" />
            <div class="actions"><NButton size="small" type="primary" :disabled="!noteDirty" :loading="saving" @click="save({ note })">{{ t('保存备注') }}</NButton></div>
          </NCard>
        </div>

        <div class="side">
          <NCard :title="t('联系方式')" size="small">
            <div>{{ c.email }}</div>
            <div class="muted">{{ c.phone || t('没有电话') }}</div>
            <div class="row" style="margin-top: 6px"><span>{{ t('生日') }}</span>
              <NDatePicker :formatted-value="c.birthday ?? null" value-format="yyyy-MM-dd" type="date" size="small" clearable style="width: 150px"
                :is-date-disabled="(ts: number) => ts > Date.now()" @update:formatted-value="setBirthday" /></div>
            <div class="sub-title">{{ t('批发 / B2B') }}</div>
            <div v-if="c.company" class="row"><a class="co-link" @click="router.push({ name: 'companies' })">{{ c.company.name }}</a>
              <span class="muted">{{ c.company.payment_terms ? t('账期 {0} 天', [c.company.payment_terms]) : t('下单时付款') }}</span></div>
            <div v-else class="muted">{{ t('不属于任何公司') }}</div>
            <div class="row" style="margin-top: 6px"><span>{{ t('免税') }}</span><NSwitch :value="c.tax_exempt || !!c.company?.tax_exempt" :disabled="!!c.company?.tax_exempt" @update:value="(v: boolean) => save({ tax_exempt: v })" /></div>
            <div class="sub-title">{{ t('默认地址') }}</div>
            <div v-if="address.length" class="addr"><div v-for="l in address" :key="l">{{ l }}</div></div>
            <div v-else class="muted">{{ t('没有保存地址') }}</div>
          </NCard>

          <NCard :title="t('数据与隐私')" size="small">
            <p class="muted" style="margin-top: 0">{{ t('按 GDPR 等法规，客户可以要求导出或删除自己的个人数据。') }}</p>
            <div class="privacy-btns">
              <NButton size="small" @click="exportData">{{ t('导出数据') }}</NButton>
              <NButton size="small" type="error" quaternary @click="eraseOpen = true; eraseConfirm = ''">{{ t('删除个人数据') }}</NButton>
            </div>
          </NCard>
          <NModal v-model:show="eraseOpen" preset="card" :title="t('删除个人数据')" style="max-width: 480px">
            <p>{{ t('客户账户会被删除，订单里的姓名、邮箱、地址、电话和备注会被清除（订单金额和商品保留）。这个操作无法撤销。') }}</p>
            <NInput v-model:value="eraseConfirm" :placeholder="t('输入客户邮箱 {0} 确认', [c.email])" />
            <template #footer><div class="privacy-btns" style="justify-content: flex-end"><NButton type="error" :disabled="eraseConfirm.trim() !== c.email" @click="eraseData">{{ t('删除') }}</NButton></div></template>
          </NModal>

          <NCard :title="t('营销邮件')" size="small">
            <div class="row">
              <span>{{ c.accepts_marketing ? t('已订阅') : t('未订阅') }}</span>
              <NSwitch :value="c.accepts_marketing" :loading="saving" @update:value="(v: boolean) => save({ accepts_marketing: v })" />
            </div>
            <div v-if="c.marketing_updated_at" class="muted">
              {{ t('{0} · 来源：{1}', [formatDate(c.marketing_updated_at), sourceLabel[c.marketing_source ?? ''] ?? c.marketing_source ?? '—']) }}
            </div>
            <div class="muted hint">{{ t('请只在顾客同意后为其订阅。') }}</div>
          </NCard>

          <CustomerPointsCard :customer-id="c.id" />

          <NCard :title="t('标签')" size="small">
            <NSelect v-model:value="tags" multiple filterable tag :options="tagOptions" :max-tag-count="20" :placeholder="t('例如：VIP、批发、复购')" />
            <div class="actions"><NButton size="small" type="primary" :disabled="!tagsDirty" :loading="saving" @click="save({ tags })">{{ t('保存标签') }}</NButton></div>
          </NCard>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.back { margin-bottom: 12px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.stat { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
.label { font-size: 12px; color: var(--ink-muted); }
.value { font-size: 20px; font-weight: 600; margin-top: 4px; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.value.small { font-size: 15px; }
.grid { display: grid; grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr); gap: 16px; align-items: start; }
.main, .side { display: grid; gap: 16px; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl td { padding: 10px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.tbl tr { cursor: pointer; }
.tbl tr:hover td { background: var(--surface-muted); }
.tbl tr:last-child td { border-bottom: 0; }
.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.muted { color: var(--ink-muted); font-size: 12px; }
.foot { padding: 8px 16px; font-size: 12px; color: var(--ink-muted); border-top: 1px solid var(--border); }
.actions { display: flex; justify-content: flex-end; margin-top: 10px; }
.sub-title { font-size: 12px; color: var(--ink-muted); margin: 12px 0 4px; padding-top: 12px; border-top: 1px solid var(--border); }
.addr { font-size: 13px; line-height: 1.6; }
.row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.hint { margin-top: 8px; }
.co-link { color: var(--brand-700); cursor: pointer; font-weight: 500; }
@media (max-width: 900px) { .grid { grid-template-columns: 1fr; } .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.privacy-btns { display: flex; gap: 8px; flex-wrap: wrap; }
</style>
