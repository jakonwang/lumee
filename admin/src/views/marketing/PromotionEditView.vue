<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import {
  NCard, NInput, NInputNumber, NButton, NSwitch, NIcon, NSkeleton, NRadioGroup, NRadio, NSelect, NDatePicker, NCheckbox, NAlert,
  useMessage, useDialog,
} from 'naive-ui'
import { ArrowBackOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import ProductPicker from '@/components/ProductPicker.vue'
import type { CollectionProduct } from '@/api/collections'
import { t } from '@/i18n'

type PType = 'sale' | 'tiered' | 'bundle'
interface PickedProduct { id: number; title: string; featured_image: string | null; status?: string }

const route = useRoute()
const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const auth = useAuth()

const id = computed(() => (route.params.id ? Number(route.params.id) : null))
const loading = ref(false)
const saving = ref(false)
const dirty = ref(false)
const pickerOpen = ref(false)
const stats = ref<{ orders_count: number; discount_total: string } | null>(null)
const collections = ref<{ value: number; label: string }[]>([])
const products = ref<PickedProduct[]>([])
const currency = computed(() => auth.currentStore?.currency ?? '')

const form = reactive({
  type: ((route.query.type as string) || 'sale') as PType,
  title: '',
  value_type: 'percentage' as 'percentage' | 'fixed',
  value: null as number | null,
  applies_to: 'all' as 'all' | 'products' | 'collections',
  collection_ids: [] as number[],
  countdown: true,
  tiers: [{ min: null as number | null, value: null as number | null }],
  combines_with_codes: true,
  enabled: true,
  starts_at: null as number | null,
  ends_at: null as number | null,
})

const typeTitle = computed(() => ({ sale: t('限时折扣'), tiered: t('满减'), bundle: t('捆绑优惠') })[form.type])
const unit = computed(() => (form.value_type === 'percentage' ? '%' : currency.value))
const needsProducts = computed(() => form.type === 'bundle' || (form.type === 'sale' && form.applies_to === 'products'))

/** Plain-language summary of what shoppers get. */
const preview = computed(() => {
  const amt = (v: number | null) => (v == null ? '…' : form.value_type === 'percentage' ? `${v}%` : `${currency.value} ${v.toFixed(2)}`)
  if (form.type === 'tiered') {
    return form.tiers.map((it) => t('订单满 {0}，减 {1}', [it.min == null ? '…' : `${currency.value} ${it.min.toFixed(2)}`, amt(it.value)])).join('；')
  }
  if (form.type === 'bundle') return t('{0} 件商品各买 1 件为一套，每套减 {1}', [products.value.length, amt(form.value)])
  const scope = form.applies_to === 'all' ? t('全部商品') : form.applies_to === 'products' ? t('选中的 {0} 件商品', [products.value.length]) : t('选中分类里的商品')
  return t('{0}减 {1}，商品页和列表显示原价划线和折后价', [scope, amt(form.value)])
})

async function load() {
  collections.value = ((await http.get('/collections-list')).data.data ?? []).map((c: { id: number; title: string }) => ({ value: c.id, label: c.title }))
  if (!id.value) return
  loading.value = true
  try {
    const p = (await http.get(`/promotions/${id.value}`)).data.data
    Object.assign(form, {
      type: p.type, title: p.title, value_type: p.value_type, value: p.value, applies_to: p.applies_to ?? 'all',
      collection_ids: p.collection_ids, countdown: p.countdown,
      tiers: p.tiers.length ? p.tiers.map((it: { min: string; value: number }) => ({ min: Number(it.min), value: it.value })) : [{ min: null, value: null }],
      combines_with_codes: p.combines_with_codes, enabled: p.enabled,
      starts_at: p.starts_at ? Date.parse(p.starts_at) : null, ends_at: p.ends_at ? Date.parse(p.ends_at) : null,
    })
    products.value = p.products
    stats.value = { orders_count: p.orders_count, discount_total: p.discount_total }
  } finally {
    loading.value = false
    setTimeout(() => { dirty.value = false })
  }
}

watch([form, products], () => { dirty.value = true }, { deep: true })

function addProducts(picked: CollectionProduct[]) {
  const have = new Set(products.value.map((p) => p.id))
  products.value.push(...picked.filter((p) => !have.has(p.id)).map((p) => ({ id: p.id, title: p.title, featured_image: p.featured_image, status: p.status })))
}

function addTier() {
  const last = form.tiers[form.tiers.length - 1]
  form.tiers.push({ min: last?.min ? last.min * 2 : null, value: null })
}

async function save() {
  saving.value = true
  try {
    const body = {
      type: id.value ? undefined : form.type,
      title: form.title,
      value_type: form.value_type,
      value: form.type === 'tiered' ? null : form.value,
      applies_to: form.type === 'sale' ? form.applies_to : null,
      product_ids: needsProducts.value ? products.value.map((p) => p.id) : [],
      collection_ids: form.type === 'sale' && form.applies_to === 'collections' ? form.collection_ids : [],
      countdown: form.countdown,
      tiers: form.type === 'tiered' ? form.tiers : null,
      combines_with_codes: form.combines_with_codes,
      enabled: form.enabled,
      starts_at: form.starts_at ? new Date(form.starts_at).toISOString() : null,
      ends_at: form.ends_at ? new Date(form.ends_at).toISOString() : null,
    }
    const { data } = id.value ? await http.put(`/promotions/${id.value}`, body) : await http.post('/promotions', body)
    setTimeout(() => { dirty.value = false })
    message.success(t('已保存'))
    if (!id.value) router.replace({ name: 'promotion-edit', params: { id: data.data.id } })
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

function remove() {
  dialog.warning({
    title: t('删除活动「{0}」？', [form.title]),
    content: t('删除后立即失效，已下单的订单不受影响。'),
    positiveText: t('删除'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.delete(`/promotions/${id.value}`)
      dirty.value = false
      message.success(t('已删除'))
      router.push({ name: 'promotions' })
    },
  })
}

onBeforeRouteLeave(() => (dirty.value ? window.confirm(t('有未保存的修改，确定离开吗？')) : true))
onMounted(load)
</script>

<template>
  <div class="editor">
    <NButton text class="back" @click="router.push({ name: 'promotions' })">
      <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
      {{ t('自动折扣') }}
    </NButton>

    <NSkeleton v-if="loading" text :repeat="6" />
    <template v-else>
      <div class="head">
        <div>
          <div class="eyebrow">{{ typeTitle }}</div>
          <h1 class="page-title">{{ id ? form.title || typeTitle : t('创建{0}', [typeTitle]) }}</h1>
        </div>
        <div class="actions">
          <NButton v-if="id" @click="remove">{{ t('删除') }}</NButton>
          <NButton type="primary" :loading="saving" :disabled="id !== null && !dirty" @click="save">{{ t('保存') }}</NButton>
        </div>
      </div>

      <div class="grid">
        <div class="main">
          <NCard size="small">
            <div class="label">{{ t('活动名称（买家会看到）') }}</div>
            <NInput v-model:value="form.title" :placeholder="form.type === 'sale' ? 'Summer Sale' : form.type === 'tiered' ? 'Spend more, save more' : 'Better together'" maxlength="120" />
          </NCard>

          <NCard :title="t('优惠方式')" size="small">
            <NRadioGroup v-model:value="form.value_type">
              <NRadio value="percentage">{{ t('打折（百分比）') }}</NRadio>
              <NRadio value="fixed">{{ form.type === 'sale' ? t('每件减固定金额') : form.type === 'bundle' ? t('每套减固定金额') : t('减固定金额') }}</NRadio>
            </NRadioGroup>

            <div v-if="form.type !== 'tiered'" class="field">
              <span>{{ form.value_type === 'percentage' ? t('优惠') : t('减') }}</span>
              <NInputNumber v-model:value="form.value" :min="0" :precision="2" :show-button="false" style="width: 160px">
                <template #suffix>{{ form.value_type === 'percentage' ? t('% off') : unit }}</template>
              </NInputNumber>
            </div>

            <div v-else class="tiers">
              <div v-for="(tier, i) in form.tiers" :key="i" class="tier">
                <span>{{ t('满') }}</span>
                <NInputNumber v-model:value="tier.min" :min="0" :precision="2" :show-button="false" style="width: 140px">
                  <template #suffix>{{ currency }}</template>
                </NInputNumber>
                <span>{{ form.value_type === 'percentage' ? t('打折优惠') : t('减') }}</span>
                <NInputNumber v-model:value="tier.value" :min="0" :precision="2" :show-button="false" style="width: 130px">
                  <template #suffix>{{ unit }}</template>
                </NInputNumber>
                <NButton v-if="form.tiers.length > 1" size="small" quaternary @click="form.tiers.splice(i, 1)">{{ t('删除') }}</NButton>
              </div>
              <NButton v-if="form.tiers.length < 5" size="small" dashed @click="addTier">+ {{ t('再加一档') }}</NButton>
              <p class="hint">{{ t('按商品金额（限时折扣后）计算，自动使用满足条件的最高一档。') }}</p>
            </div>
          </NCard>

          <NCard v-if="form.type === 'sale'" :title="t('参加活动的商品')" size="small">
            <NRadioGroup v-model:value="form.applies_to">
              <div class="radios">
                <NRadio value="all">{{ t('全部商品') }}</NRadio>
                <NRadio value="collections">{{ t('指定分类') }}</NRadio>
                <NRadio value="products">{{ t('指定商品') }}</NRadio>
              </div>
            </NRadioGroup>
            <NSelect v-if="form.applies_to === 'collections'" v-model:value="form.collection_ids" :options="collections" multiple filterable
              :placeholder="t('选择分类')" style="margin-top: 12px" />
          </NCard>

          <NCard v-if="needsProducts" :title="form.type === 'bundle' ? t('捆绑的商品') : t('选择商品')" size="small">
            <template #header-extra><NButton size="small" @click="pickerOpen = true">+ {{ t('添加商品') }}</NButton></template>
            <p v-if="form.type === 'bundle'" class="hint top">{{ t('买家购物车里每件商品都至少有 1 件时，算作一套；买两套就优惠两次。同一商品的不同款式都算。') }}</p>
            <div v-if="products.length" class="plist">
              <div v-for="(p, i) in products" :key="p.id" class="prow">
                <img :src="p.featured_image || '/placeholder.svg'" alt="" :class="{ ph: !p.featured_image }">
                <span class="pname">{{ p.title }}</span>
                <NButton size="tiny" quaternary @click="products.splice(i, 1)">{{ t('移除') }}</NButton>
              </div>
            </div>
            <p v-else class="hint">{{ t('还没有选择商品') }}</p>
          </NCard>

          <NAlert type="info" :bordered="false" :title="t('买家看到的效果')">{{ preview }}</NAlert>
        </div>

        <div class="side">
          <NCard :title="t('状态')" size="small">
            <div class="row"><span>{{ form.enabled ? t('启用') : t('停用') }}</span><NSwitch v-model:value="form.enabled" /></div>
            <div v-if="stats && form.type !== 'sale'" class="stats">{{ t('{0} 单 · 共优惠 {1}', [stats.orders_count, stats.discount_total]) }}</div>
          </NCard>
          <NCard :title="t('活动时间')" size="small">
            <div class="label">{{ t('开始') }}</div>
            <NDatePicker v-model:value="form.starts_at" type="datetime" clearable :placeholder="t('立即开始')" style="width: 100%" />
            <div class="label" style="margin-top: 12px">{{ t('结束') }}</div>
            <NDatePicker v-model:value="form.ends_at" type="datetime" clearable :placeholder="t('长期有效')" style="width: 100%" />
            <NCheckbox v-if="form.type === 'sale'" v-model:checked="form.countdown" :disabled="!form.ends_at" style="margin-top: 12px">{{ t('商品页显示倒计时') }}</NCheckbox>
          </NCard>
          <NCard v-if="form.type !== 'sale'" :title="t('和折扣码一起用')" size="small">
            <NCheckbox v-model:checked="form.combines_with_codes">{{ t('允许同时使用折扣码') }}</NCheckbox>
            <p class="hint">{{ t('不允许时，买家输入折扣码后会自动选择更划算的一种。') }}</p>
          </NCard>
          <NCard v-else :title="t('和折扣码一起用')" size="small">
            <p class="hint top">{{ t('限时折扣直接降低商品价格，折扣码按折后价计算，可以一起使用。') }}</p>
          </NCard>
        </div>
      </div>
    </template>

    <ProductPicker v-model:show="pickerOpen" :exclude="products.map((p) => p.id)" endpoint="/promotions/products" @pick="addProducts" />
  </div>
</template>

<style scoped>
.editor { container-type: inline-size; }
.back { margin-bottom: 12px; color: var(--ink-muted); }
.head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.head .page-title { margin: 0; }
.actions { display: flex; gap: 8px; }
.grid { display: grid; grid-template-columns: 1fr 300px; gap: 16px; align-items: start; }
.main, .side { display: grid; gap: 16px; min-width: 0; }
.label { font-size: 13px; margin-bottom: 6px; }
.field { display: flex; align-items: center; gap: 10px; margin-top: 14px; font-size: 14px; }
.tiers { display: grid; gap: 10px; margin-top: 14px; }
.tier { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 14px; }
.radios { display: flex; gap: 16px; flex-wrap: wrap; }
.hint { font-size: 12px; color: var(--ink-muted); margin: 8px 0 0; line-height: 1.6; }
.hint.top { margin: 0 0 10px; }
.plist { display: grid; border: 1px solid var(--border); border-radius: 10px; }
.prow { display: flex; align-items: center; gap: 10px; padding: 8px 12px; }
.prow + .prow { border-top: 1px solid var(--border); }
.prow img { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; background: var(--surface-muted); }
.prow img.ph { object-fit: contain; padding: 6px; opacity: .5; }
.pname { flex: 1; font-size: 14px; }
.row { display: flex; justify-content: space-between; align-items: center; font-size: 14px; }
.stats { margin-top: 10px; font-size: 12px; color: var(--ink-muted); }
@container (max-width: 820px) { .grid { grid-template-columns: 1fr; } }
</style>
