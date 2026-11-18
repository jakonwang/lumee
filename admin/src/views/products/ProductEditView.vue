<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave, RouterLink } from 'vue-router'
import {
  NCard, NCheckbox, NInput, NInputNumber, NButton, NSelect, NSpace, NIcon, NDynamicTags,
  NSkeleton, NForm, NFormItem, NAlert, NDatePicker, useMessage, useDialog, type SelectOption,
} from 'naive-ui'
import { ArrowBackOutline, AddOutline, TrashOutline } from '@vicons/ionicons5'
import ProductPicker from '@/components/ProductPicker.vue'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import ImageManager from '@/components/ImageManager.vue'
import SeoCard from '@/components/SeoCard.vue'
import InventoryHistory from '@/components/InventoryHistory.vue'
import {
  type ProductDetail, type ProductImage, type ProductOption, type Variant,
  combinations, comboKey,
} from '@/api/products'
import { listCollections } from '@/api/collections'
import { t } from '@/i18n'

const route = useRoute()
const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const auth = useAuth()

const id = computed(() => (route.params.id && route.params.id !== 'new' ? Number(route.params.id) : null))
const loading = ref(false)
const saving = ref(false)
const dirty = ref(false)

const intervalOptions = [
  { label: t('每周'), value: 'week:1' }, { label: t('每 2 周'), value: 'week:2' }, { label: t('每 3 周'), value: 'week:3' }, { label: t('每 4 周'), value: 'week:4' },
  { label: t('每月'), value: 'month:1' }, { label: t('每 2 个月'), value: 'month:2' }, { label: t('每 3 个月'), value: 'month:3' }, { label: t('每 6 个月'), value: 'month:6' },
]
const form = reactive({
  title: '',
  description: '',
  status: 'active' as ProductDetail['status'],
  product_type: '',
  taxable: true,
  is_gift_card: false,
  b2b_only: false,
  subscription: { enabled: false, discount: 10, intervals: ['month:1'] as string[], only: false },
  digital: false,
  preorder: { enabled: false, ships_at: null as string | null, note: '' as string | null, limit: null as number | null },
  vendor: '',
  handle: '',
})
const tags = ref<string[]>([])
const seo = ref<{ title: string | null; description: string | null }>({ title: null, description: null })
const images = ref<ProductImage[]>([])
const collectionIds = ref<number[]>([])
const collectionOptions = ref<{ value: number; label: string }[]>([])
const options = ref<ProductOption[]>([])
const variants = ref<Variant[]>([blankVariant({})])
const bulk = reactive({ price: null as number | null, inventory: null as number | null, weight: null as number | null })

const currency = computed(() => auth.currentStore?.currency ?? 'USD')
const hasOptions = computed(() => options.value.some((o) => o.name.trim() && o.values.length))
const activeOptions = computed(() => options.value.filter((o) => o.name.trim() && o.values.length).map((o) => ({ name: o.name.trim(), values: o.values })))
// Stock adjusted from the history card is already saved: update the table without marking the form dirty
function onAdjusted(variantId: number, inventory: number) {
  const was = dirty.value
  const v = variants.value.find((x) => x.id === variantId)
  if (v) v.inventory = inventory
  setTimeout(() => { dirty.value = was })
}
const totalStock = computed(() => variants.value.reduce((s, v) => s + (Number(v.inventory) || 0), 0))

function blankVariant(opts: Record<string, string>, base?: Partial<Variant>): Variant {
  return {
    options: opts,
    price: base?.price ?? 0,
    compare_at_price: base?.compare_at_price ?? null,
    sku: null,
    inventory: base?.inventory ?? 0,
    weight: base?.weight ?? 0,
    image_id: null,
  }
}

// Regenerate the variant matrix whenever options change, keeping what the merchant already typed
function rebuildVariants() {
  const opts = activeOptions.value
  if (!opts.length) {
    const keep = variants.value.find((v) => Object.keys(v.options).length === 0) ?? variants.value[0]
    variants.value = [{ ...(keep ?? blankVariant({})), options: {} }]
    return
  }
  const byKey = new Map(variants.value.map((v) => [comboKey(opts, v.options), v]))
  const template = variants.value[0]
  // Going from "no options" to options: the first combination takes over the existing
  // single variant (keeps its id, stock and SKU, so carts/orders referencing it stay intact)
  const single = variants.value.length === 1 && Object.keys(template?.options ?? {}).length === 0 ? template : null
  let adopted = false
  variants.value = combinations(opts).map((combo) => {
    const existing = byKey.get(comboKey(opts, combo))
    if (existing) return { ...existing, options: combo }
    if (single && !adopted) {
      adopted = true
      return { ...single, options: combo }
    }
    return blankVariant(combo, { price: template?.price, compare_at_price: template?.compare_at_price, weight: template?.weight })
  })
}

watch(options, rebuildVariants, { deep: true })
watch([form, images, options, variants, collectionIds, tags, seo], () => { dirty.value = true }, { deep: true })

function addOption() {
  const suggestions = [t('材质 Material'), t('尺寸 Size'), t('颜色 Color')]
  const used = new Set(options.value.map((o) => o.name))
  options.value.push({ name: suggestions.find((s) => !used.has(s)) ?? '', values: [] })
}

function removeOption(i: number) {
  options.value.splice(i, 1)
}

function applyBulk() {
  variants.value = variants.value.map((v) => ({
    ...v,
    price: bulk.price ?? v.price,
    inventory: bulk.inventory ?? v.inventory,
    weight: bulk.weight ?? v.weight,
  }))
  message.success(t('已批量应用到所有 SKU'))
}

// API sends money as strings ("129.00"); number inputs need numbers or they render empty
function toEditable(v: Variant): Variant {
  return {
    ...v,
    options: { ...v.options },
    price: Number(v.price) || 0,
    compare_at_price: v.compare_at_price === null || v.compare_at_price === '' ? null : Number(v.compare_at_price),
  }
}

// Variant image dropdown: each option shows the thumbnail
const imageOptions = computed(() => images.value.map((img, i) => ({ label: t('图 {0}', [i + 1]), value: img.id, url: img.url })))
const thumb = (url: string, size: number) =>
  h('img', { src: url, alt: '', style: `width:${size}px;height:${size}px;object-fit:cover;border-radius:6px;display:block;flex:none` })
function renderImageOption(o: SelectOption) {
  return h('div', { style: 'display:flex;align-items:center;gap:8px' }, [thumb(String(o.url), 32), String(o.label)])
}
function renderImageTag({ option }: { option: SelectOption }) {
  return h('div', { style: 'display:flex;align-items:center;gap:6px' }, [thumb(String(option.url), 22), String(option.label)])
}

const stockAlerts = ref(0)   // buyers waiting for a restock email

// "Frequently bought together" picks (up to 3; empty = from order history)
interface Upsell { id: number; title: string; featured_image: string | null }
const upsells = ref<Upsell[]>([])
const upsellPicker = ref(false)
function addUpsells(list: Upsell[]) {
  upsells.value = [...upsells.value, ...list].slice(0, 3)
}

// Digital product files (uploaded straight away; only for saved products)
const files = ref<NonNullable<ProductDetail['files']>>([])
const uploadingFile = ref(false)
async function uploadFile(ev: Event) {
  const input = ev.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !id.value) return
  uploadingFile.value = true
  try {
    const fd = new FormData()
    fd.append('file', file)
    const { data } = await http.post(`/products/${id.value}/files`, fd)
    files.value.push(data.data)
    form.digital = true
    message.success(t('文件已上传'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    uploadingFile.value = false
  }
}
async function removeFile(fid: number) {
  try {
    await http.delete(`/products/${id.value}/files/${fid}`)
    files.value = files.value.filter((f) => f.id !== fid)
  } catch (e) {
    message.error(errorMessage(e))
  }
}
const fileSize = (n: number) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)
const tomorrow = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d.getTime() + 86400000 }

async function load() {
  if (!id.value) return
  loading.value = true
  try {
    const { data } = await http.get(`/products/${id.value}`)
    const p: ProductDetail = data.data
    stockAlerts.value = p.stock_alerts ?? 0
    files.value = p.files ?? []
    upsells.value = (p as ProductDetail & { upsells?: Upsell[] }).upsells ?? []
    Object.assign(form, {
      title: p.title,
      description: p.description ?? '',
      status: p.status,
      product_type: p.product_type ?? '',
      taxable: p.taxable ?? true,
      is_gift_card: !!p.is_gift_card,
      b2b_only: !!p.b2b_only,
      subscription: p.subscription?.intervals?.length ? { ...p.subscription } : { enabled: false, discount: 10, intervals: ['month:1'], only: false },
      digital: !!p.digital,
      preorder: p.preorder ? { ...p.preorder, note: p.preorder.note ?? '' } : { enabled: false, ships_at: null, note: '', limit: null },
      vendor: p.vendor ?? '',
      handle: p.handle,
    })
    tags.value = [...(p.tags ?? [])]
    seo.value = { title: p.seo?.title ?? null, description: p.seo?.description ?? null }
    images.value = p.images
    collectionIds.value = [...p.collection_ids]
    // Assign variants before options so the options watcher keeps them (matched by key)
    variants.value = p.variants.map(toEditable)
    options.value = p.options.map((o) => ({ ...o, values: [...o.values] }))
  } finally {
    loading.value = false
    // Let the watchers settle, then start tracking edits
    setTimeout(() => { dirty.value = false })
  }
}

function payload() {
  return {
    ...form,
    handle: form.handle || undefined,
    images: images.value.map((i) => ({ id: i.id, alt: i.alt })),
    collection_ids: collectionIds.value,
    upsell_ids: upsells.value.map((u) => u.id),
    tags: tags.value,
    seo: seo.value,
    options: activeOptions.value,
    variants: variants.value.map((v) => ({
      id: v.id,
      options: v.options,
      price: Number(v.price) || 0,
      compare_at_price: v.compare_at_price === null || v.compare_at_price === '' ? null : Number(v.compare_at_price),
      sku: v.sku || null,
      inventory: Number(v.inventory) || 0,
      weight: Math.max(0, Math.round(Number(v.weight) || 0)),
      image_id: v.image_id,
    })),
  }
}

async function save() {
  if (!form.title.trim()) {
    message.error(t('请填写商品名称'))
    return
  }
  saving.value = true
  try {
    const { data } = id.value
      ? await http.put(`/products/${id.value}`, payload())
      : await http.post('/products', payload())
    const p: ProductDetail = data.data
    message.success(t('已保存'))
    dirty.value = false
    if (!id.value) {
      router.replace({ name: 'product-edit', params: { id: p.id } })
    } else {
      // Pick up server-assigned variant ids
      variants.value = p.variants.map(toEditable)
      form.handle = p.handle
      setTimeout(() => { dirty.value = false })
    }
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

function destroy() {
  dialog.warning({
    title: t('删除商品？'),
    content: t('「{0}」及其图片将被永久删除，无法恢复。历史订单不受影响。', [form.title]),
    positiveText: t('删除'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.delete(`/products/${id.value}`)
      dirty.value = false
      message.success(t('已删除'))
      router.push({ name: 'products' })
    },
  })
}

onBeforeRouteLeave(() => {
  if (!dirty.value || saving.value) return true
  return window.confirm(t('有未保存的修改，确定离开吗？'))
})

onMounted(() => {
  listCollections(true).then((list) => { collectionOptions.value = list.map((c) => ({ value: c.id, label: c.title })) })
  load()
  if (!id.value) setTimeout(() => { dirty.value = false })
})
</script>

<template>
  <div class="editor">
    <NButton text class="back" @click="router.push({ name: 'products' })">
      <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
      {{ t('商品') }}
    </NButton>

    <div class="head">
      <h1 class="page-title">{{ id ? form.title || t('编辑商品') : t('添加商品') }}</h1>
      <NSpace>
        <NButton v-if="id" quaternary type="error" @click="destroy">{{ t('删除') }}</NButton>
        <a v-if="id && form.status === 'active' && auth.currentStore" :href="`${auth.currentStore.storefront_url}/products/${form.handle}`" target="_blank">
          <NButton secondary>{{ t('在店铺查看') }}</NButton>
        </a>
        <NButton type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton>
      </NSpace>
    </div>

    <NSkeleton v-if="loading" text :repeat="8" />

    <div v-else class="grid">
      <div class="main">
        <NCard :title="t('基本信息')" size="small">
          <NForm label-placement="top">
            <NFormItem :label="t('商品名称')" :show-feedback="false" style="margin-bottom: 16px">
              <NInput v-model:value="form.title" :placeholder="t('例如：Aurora 紫水晶蝴蝶项链')" maxlength="255" />
            </NFormItem>
            <NFormItem :label="t('商品描述')" :show-feedback="false">
              <NInput v-model:value="form.description" type="textarea" :autosize="{ minRows: 4, maxRows: 14 }" :placeholder="t('材质、尺寸、佩戴方式、保养说明…')" />
            </NFormItem>
          </NForm>
        </NCard>

        <NCard :title="t('图片')" size="small">
          <ImageManager v-model="images" />
        </NCard>

        <NCard :title="t('规格')" size="small">
          <template #header-extra>
            <NButton v-if="options.length < 3" size="small" secondary @click="addOption">
              <template #icon><NIcon><AddOutline /></NIcon></template>
              {{ t('添加规格') }}
            </NButton>
          </template>

          <p v-if="!options.length" class="hint">{{ t('商品有多种材质、尺寸或颜色？添加规格后，可以为每个组合单独设置价格、库存和图片。') }}</p>

          <div v-for="(opt, i) in options" :key="i" class="option">
            <div class="option-row">
              <NInput v-model:value="opt.name" :placeholder="t('规格名，如 材质')" style="max-width: 200px" maxlength="64" />
              <NButton quaternary circle size="small" :aria-label="t('删除规格')" @click="removeOption(i)">
                <template #icon><NIcon><TrashOutline /></NIcon></template>
              </NButton>
            </div>
            <NDynamicTags v-model:value="opt.values" :max="50" />
            <small class="hint">{{ t('输入规格值后按回车，如 18K Gold、Sterling Silver') }}</small>
          </div>

          <!-- Single SKU -->
          <div v-if="!hasOptions && variants[0]" class="single">
            <NFormItem :label="t('价格')" :show-feedback="false">
              <NInputNumber v-model:value="(variants[0].price as number)" :min="0" :precision="2" style="width: 100%">
                <template #prefix>{{ currency }}</template>
              </NInputNumber>
            </NFormItem>
            <NFormItem :label="t('原价（划线价）')" :show-feedback="false">
              <NInputNumber v-model:value="(variants[0].compare_at_price as number | null)" :min="0" :precision="2" clearable style="width: 100%">
                <template #prefix>{{ currency }}</template>
              </NInputNumber>
            </NFormItem>
            <NFormItem label="SKU" :show-feedback="false">
              <NInput v-model:value="variants[0].sku" :placeholder="t('可选')" />
            </NFormItem>
            <NFormItem :label="t('库存')" :show-feedback="false">
              <NInputNumber v-model:value="variants[0].inventory" style="width: 100%" />
            </NFormItem>
            <NFormItem :label="t('重量（用于按重量计算运费）')" :show-feedback="false">
              <NInputNumber v-model:value="variants[0].weight" :min="0" :precision="0" style="width: 100%">
                <template #suffix>g</template>
              </NInputNumber>
            </NFormItem>
          </div>
        </NCard>

        <!-- Variant matrix -->
        <NCard v-if="hasOptions" size="small">
          <template #header>SKU <span class="count">{{ t('共 {0} 个 · 总库存 {1}', [variants.length, totalStock]) }}</span></template>
          <template #header-extra>
            <div class="bulk">
              <NInputNumber v-model:value="bulk.price" size="small" :min="0" :precision="2" :placeholder="t('价格')" clearable style="width: 110px" />
              <NInputNumber v-model:value="bulk.inventory" size="small" :placeholder="t('库存')" clearable style="width: 90px" />
              <NInputNumber v-model:value="bulk.weight" size="small" :min="0" :precision="0" :placeholder="t('重量 g')" clearable style="width: 100px" />
              <NButton size="small" :disabled="bulk.price === null && bulk.inventory === null && bulk.weight === null" @click="applyBulk">{{ t('批量设置') }}</NButton>
            </div>
          </template>

          <NAlert v-if="variants.length > 100" type="warning" :bordered="false" style="margin-bottom: 12px">
            {{ t('组合数超过 100 个，请减少规格值。') }}
          </NAlert>

          <div class="vtable-wrap">
            <table class="vtable">
              <thead>
                <tr><th style="width: 130px">{{ t('图片') }}</th><th>{{ t('规格') }}</th><th style="width: 130px">{{ t('价格') }}</th><th style="width: 130px">{{ t('原价') }}</th><th style="width: 130px">SKU</th><th style="width: 100px">{{ t('库存') }}</th><th style="width: 100px">{{ t('重量 (g)') }}</th></tr>
              </thead>
              <tbody>
                <tr v-for="v in variants" :key="comboKey(activeOptions, v.options)">
                  <td>
                    <NSelect
                      v-model:value="v.image_id"
                      size="small"
                      clearable
                      :options="imageOptions"
                      :render-label="renderImageOption"
                      :render-tag="renderImageTag"
                      :disabled="!images.length"
                      :placeholder="images.length ? t('选择') : t('先上传')"
                      :consistent-menu-width="false"
                      :aria-label="t('规格图片')"
                    />
                  </td>
                  <td class="vname">{{ Object.values(v.options).join(' / ') }}</td>
                  <td><NInputNumber v-model:value="(v.price as number)" size="small" :min="0" :precision="2" :show-button="false" /></td>
                  <td><NInputNumber v-model:value="(v.compare_at_price as number | null)" size="small" :min="0" :precision="2" :show-button="false" clearable /></td>
                  <td><NInput v-model:value="v.sku" size="small" placeholder="—" /></td>
                  <td><NInputNumber v-model:value="v.inventory" size="small" :show-button="false" /></td>
                  <td><NInputNumber v-model:value="v.weight" size="small" :min="0" :precision="0" :show-button="false" /></td>
                </tr>
              </tbody>
            </table>
          </div>
        </NCard>

        <InventoryHistory v-if="id" :key="id" :product-id="id" :variants="variants" @adjusted="onAdjusted" />

        <SeoCard v-model="seo" :path="`/products/${form.handle || '…'}`" :fallback-title="form.title" :fallback-description="form.description" />
      </div>

      <div class="side">
        <NCard :title="t('状态')" size="small">
          <NSelect
            v-model:value="form.status"
            :options="[{ label: t('在售'), value: 'active' }, { label: t('草稿'), value: 'draft' }, { label: t('已归档'), value: 'archived' }]"
          />
          <p class="hint" style="margin-top: 8px">{{ t('只有「在售」的商品会显示在店铺中。') }}</p>
        </NCard>
        <NCard v-if="stockAlerts" :title="t('到货通知')" size="small">
          <p class="hint">{{ t('有 {0} 位顾客在等这个商品补货。补上库存后，系统会自动给他们发到货邮件。', [stockAlerts]) }}</p>
        </NCard>
        <NCard :title="t('所属分类')" size="small">
          <NSelect v-model:value="collectionIds" :options="collectionOptions" multiple filterable clearable :placeholder="t('选择一个或多个分类')" />
          <p v-if="!collectionOptions.length" class="hint" style="margin-top: 8px">
            {{ t('还没有分类，') }}<RouterLink :to="{ name: 'collection-new' }">{{ t('去新建') }}</RouterLink>
          </p>
        </NCard>
        <NCard :title="t('批发 / B2B')" size="small">
          <NCheckbox v-model:checked="form.b2b_only">{{ t('只对批发客户显示') }}</NCheckbox>
          <p class="hint" style="margin-top: 8px">{{ t('开启后，只有属于公司或有批发价格表的客户登录后才能看到和购买这个商品。') }}</p>
        </NCard>
        <NCard :title="t('订阅购买')" size="small">
          <NCheckbox v-model:checked="form.subscription.enabled">{{ t('允许买家订阅（定期自动购买）') }}</NCheckbox>
          <template v-if="form.subscription.enabled">
            <NFormItem :label="t('订阅优惠')" :show-feedback="false" style="margin-top: 10px">
              <NInputNumber v-model:value="form.subscription.discount" :min="0" :max="90" style="width: 100%"><template #suffix>% off</template></NInputNumber>
            </NFormItem>
            <NFormItem :label="t('配送频率')" :show-feedback="false" style="margin-top: 10px">
              <NSelect v-model:value="form.subscription.intervals" multiple :options="intervalOptions" />
            </NFormItem>
            <NCheckbox v-model:checked="form.subscription.only" style="margin-top: 10px">{{ t('只能订阅，不能单次购买') }}</NCheckbox>
          </template>
          <p class="hint" style="margin-top: 8px">{{ t('订阅订单用 Stripe 保存的银行卡自动续费（或线下付款）。到期自动生成新订单；扣款失败每 3 天重试，失败 3 次自动取消。') }}</p>
        </NCard>
        <NCard :title="t('搭配购买')" size="small">
          <div v-for="(u, i) in upsells" :key="u.id" class="file-row">
            <span class="file-name">{{ u.title }}</span>
            <NButton size="tiny" quaternary @click="upsells.splice(i, 1)">{{ t('移除') }}</NButton>
          </div>
          <NButton v-if="upsells.length < 3" size="small" style="margin-top: 8px" @click="upsellPicker = true">{{ t('选择搭配商品') }}</NButton>
          <p class="hint" style="margin-top: 8px">{{ t('显示在商品页「经常一起购买」和购物车推荐里，最多 3 个。不选的话，系统会根据近半年的订单自动推荐常被一起买的商品。') }}</p>
          <ProductPicker v-model:show="upsellPicker" :exclude="[...(id ? [id] : []), ...upsells.map((u) => u.id)]" @pick="addUpsells" />
        </NCard>
        <NCard :title="t('预售')" size="small">
          <NCheckbox v-model:checked="form.preorder.enabled">{{ t('缺货时继续接受预订') }}</NCheckbox>
          <template v-if="form.preorder.enabled">
            <NFormItem :label="t('预计发货日期')" :show-feedback="false" style="margin-top: 10px">
              <NDatePicker v-model:formatted-value="form.preorder.ships_at" value-format="yyyy-MM-dd" type="date" clearable style="width: 100%" :is-date-disabled="(ts: number) => ts < tomorrow()" />
            </NFormItem>
            <NFormItem :label="t('说明（选填，显示在商品页）')" :show-feedback="false" style="margin-top: 10px">
              <NInput v-model:value="form.preorder.note" maxlength="200" :placeholder="t('例如：手工制作，约 3 周发货')" />
            </NFormItem>
            <NFormItem :label="t('每个规格最多预订（选填）')" :show-feedback="false" style="margin-top: 10px">
              <NInputNumber v-model:value="form.preorder.limit" :min="1" clearable :placeholder="t('不限')" style="width: 100%" />
            </NFormItem>
          </template>
          <p class="hint" style="margin-top: 8px">{{ t('库存为 0 时，商品页显示「预订」按钮和预计发货日期，库存会变成负数（表示待补货的数量）。订单里的预售商品会单独标出。') }}</p>
        </NCard>
        <NCard :title="t('数字商品')" size="small">
          <NCheckbox v-model:checked="form.digital">{{ t('这是数字商品（文件下载，不需要发货）') }}</NCheckbox>
          <template v-if="form.digital">
            <div v-for="f in files" :key="f.id" class="file-row">
              <span class="file-name">{{ f.filename }}</span><span class="hint">{{ fileSize(f.size) }}</span>
              <NButton size="tiny" quaternary type="error" @click="removeFile(f.id)">{{ t('删除') }}</NButton>
            </div>
            <label v-if="id" class="file-upload">
              <input type="file" class="sr" @change="uploadFile" />
              <NButton size="small" :loading="uploadingFile" tag="span" style="margin-top: 8px">{{ t('上传文件') }}</NButton>
            </label>
            <p v-else class="hint" style="margin-top: 8px">{{ t('先保存商品，再上传文件。') }}</p>
          </template>
          <p class="hint" style="margin-top: 8px">{{ t('买家付款后自动收到下载链接（每个链接可下载 5 次，30 天内有效）。只买数字商品时，结账不用选运费，订单自动完成。单个文件不超过 500MB。') }}</p>
        </NCard>
        <NCard :title="t('礼品卡')" size="small">
          <NCheckbox v-model:checked="form.is_gift_card">{{ t('这是礼品卡商品') }}</NCheckbox>
          <p class="hint" style="margin-top: 8px">{{ t('买家付款后，每件自动生成一张面值等于售价的礼品卡，并发邮件给买家。只买礼品卡的订单会自动标记为已发货。可以用规格设置不同面值。') }}</p>
        </NCard>
        <NCard :title="t('税费')" size="small">
          <NCheckbox v-model:checked="form.taxable">{{ t('这个商品收税') }}</NCheckbox>
          <p class="hint" style="margin-top: 8px">{{ t('税率在「设置 → 税费」中按国家设置。') }}</p>
        </NCard>
        <NCard :title="t('商品组织')" size="small">
          <NFormItem :label="t('商品类型')" :show-feedback="false">
            <NInput v-model:value="form.product_type" :placeholder="t('例如：项链、耳饰（显示在商品页标题上方）')" />
          </NFormItem>
          <NFormItem :label="t('品牌')" :show-feedback="false" style="margin-top: 12px">
            <NInput v-model:value="form.vendor" :placeholder="t('例如：Lumée')" maxlength="100" />
          </NFormItem>
          <NFormItem :label="t('标签')" :show-feedback="false" style="margin-top: 12px">
            <NDynamicTags v-model:value="tags" :max="50" />
          </NFormItem>
          <p class="hint" style="margin-top: 8px">{{ t('买家在店铺里搜索时，也会匹配品牌和标签。') }}</p>
        </NCard>
        <NCard v-if="id" :title="t('网址')" size="small">
          <NInput v-model:value="form.handle">
            <template #prefix><span class="hint">/products/</span></template>
          </NInput>
        </NCard>
      </div>
    </div>
  </div>
</template>

<style scoped>
.editor { container-type: inline-size; }
.back { margin-bottom: 12px; color: var(--ink-muted); }
.head {
  display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap;
  position: sticky; top: -32px; z-index: 5; padding: 12px 0; background: var(--bg);
}
.head .page-title { margin: 0; }
.head a { text-decoration: none; }
.grid { display: grid; grid-template-columns: 1fr 280px; gap: 16px; align-items: start; }
.main, .side { display: grid; gap: 16px; min-width: 0; }
.hint { color: var(--ink-muted); font-size: 13px; margin: 0; }
small.hint { font-size: 12px; display: block; margin-top: 6px; }
.option { padding: 14px; border: 1px solid var(--border); border-radius: 10px; margin-bottom: 12px; background: var(--brand-50); }
.option-row { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.single { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; margin-top: 4px; }
.count { font-weight: 400; color: var(--ink-muted); font-size: 13px; margin-left: 6px; }
.bulk { display: flex; gap: 6px; align-items: center; }
.vtable-wrap { overflow-x: auto; margin: 0 -16px; padding: 0 16px; }
.vtable { width: 100%; min-width: 700px; border-collapse: collapse; font-size: 13px; }
.vtable th { text-align: left; font-weight: 500; color: var(--ink-muted); padding: 8px 6px; background: var(--brand-50); }
.vtable td { padding: 6px; border-top: 1px solid var(--border); vertical-align: middle; }
.vname { font-weight: 500; white-space: nowrap; }
@container (max-width: 820px) {
  .grid { grid-template-columns: 1fr; }
  .side { order: -1; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
}
@container (max-width: 520px) { .single { grid-template-columns: 1fr; } }
.file-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.file-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.file-upload { display: inline-block; cursor: pointer; }
.sr { position: absolute; width: 1px; height: 1px; opacity: 0; }
</style>
