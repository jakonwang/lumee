<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import {
  NCard, NInput, NInputNumber, NButton, NSelect, NSwitch, NIcon, NSkeleton, NEmpty, NTag, NRadioGroup, NRadio, useMessage, useDialog,
} from 'naive-ui'
import { ArrowBackOutline, ReorderTwoOutline, CloseOutline, AddOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import FieldInput from '@/components/theme/FieldInput.vue'
import ProductPicker from '@/components/ProductPicker.vue'
import SeoCard from '@/components/SeoCard.vue'
import { invalidateCollections, type CollectionDetail, type CollectionProduct } from '@/api/collections'
import { t } from '@/i18n'

const route = useRoute()
const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const auth = useAuth()

const id = computed(() => (route.params.id ? Number(route.params.id) : null))
const loading = ref(false)
const saving = ref(false)
const pickerOpen = ref(false)
const form = reactive({
  title: '',
  handle: '',
  description: '',
  image_id: null as number | null,
  sort_order: 'manual',
  published: true,
})
const products = ref<CollectionProduct[]>([])

// Smart collections: products join automatically when they match the conditions
interface Rule { field: string; op: string; value: string | number | null }
const kind = ref<'manual' | 'smart'>('manual')
const wasSmart = ref(false)
const rules = ref<Rule[]>([])
const rulesMatch = ref<'all' | 'any'>('all')
const fieldOptions = [
  { value: 'tag', label: t('商品标签') }, { value: 'type', label: t('商品类型') }, { value: 'vendor', label: t('品牌') },
  { value: 'title', label: t('商品名称') }, { value: 'price', label: t('价格') }, { value: 'stock', label: t('库存') },
]
const opOptions: Record<string, { value: string; label: string }[]> = {
  tag: [{ value: 'equals', label: t('等于') }], type: [{ value: 'equals', label: t('等于') }], vendor: [{ value: 'equals', label: t('等于') }],
  title: [{ value: 'contains', label: t('包含') }], price: [{ value: 'gt', label: t('大于') }, { value: 'lt', label: t('小于') }],
  stock: [{ value: 'in_stock', label: t('有货') }, { value: 'out_of_stock', label: t('缺货') }],
}
function addRule() {
  rules.value.push({ field: 'tag', op: 'equals', value: '' })
}
function onField(r: Rule) {
  r.op = opOptions[r.field]![0]!.value
  r.value = r.field === 'price' ? 0 : r.field === 'stock' ? null : ''
}
const seo = ref<{ title: string | null; description: string | null }>({ title: null, description: null })
const sorts = ref<Record<string, string>>({ manual: t('手动排序'), newest: t('最新上架'), price_asc: t('价格从低到高'), price_desc: t('价格从高到低'), title: t('名称 A–Z') })
const mediaUrls = ref<Record<number, string>>({})
const sortOptions = computed(() => Object.entries(sorts.value).map(([value, label]) => ({ value, label })))

async function load() {
  if (!id.value) return
  loading.value = true
  try {
    const { data } = await http.get<{ data: CollectionDetail }>(`/collections/${id.value}`)
    const c = data.data
    Object.assign(form, {
      title: c.title, handle: c.handle, description: c.description ?? '',
      image_id: c.image_id, sort_order: c.sort_order, published: c.published,
    })
    seo.value = { title: c.seo?.title ?? null, description: c.seo?.description ?? null }
    if (c.image_id && c.image_url) mediaUrls.value[c.image_id] = c.image_url
    products.value = c.products
    sorts.value = c.sorts
    rules.value = (c.rules ?? []).map((r) => ({ ...r }))
    rulesMatch.value = c.rules_match ?? 'all'
    kind.value = c.smart ? 'smart' : 'manual'
    wasSmart.value = !!c.smart
  } finally {
    loading.value = false
    setTimeout(() => { dirty.value = false })   // filling the form isn't an edit
  }
}

// Leaving with unsaved edits asks first (like the product editor)
const dirty = ref(false)
watch([form, seo, products, rules, rulesMatch, kind], () => { dirty.value = true }, { deep: true })
onBeforeRouteLeave(() => !dirty.value || saving.value || window.confirm(t('有未保存的修改，确定离开吗？')))

async function save() {
  if (!form.title.trim()) {
    message.error(t('请填写分类名称'))
    return
  }
  if (kind.value === 'smart' && (!rules.value.length || rules.value.some((r) => r.field !== 'stock' && (r.value === '' || r.value === null)))) {
    message.error(t('请完整填写每一条条件'))
    return
  }
  saving.value = true
  try {
    const body = kind.value === 'smart'
      ? { ...form, handle: form.handle || undefined, seo: seo.value, rules: rules.value, rules_match: rulesMatch.value }
      : { ...form, handle: form.handle || undefined, seo: seo.value, product_ids: products.value.map((p) => p.id), ...(wasSmart.value ? { rules: [] } : {}) }
    const { data } = id.value
      ? await http.put(`/collections/${id.value}`, body)
      : await http.post('/collections', body)
    invalidateCollections()
    message.success(t('已保存'))
    dirty.value = false
    if (!id.value) router.replace({ name: 'collection-edit', params: { id: data.data.id } })
    else form.handle = data.data.handle
    if (data.data.products) products.value = data.data.products
    wasSmart.value = kind.value === 'smart'
    setTimeout(() => { dirty.value = false })
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

function destroy() {
  dialog.warning({
    title: t('删除分类？'),
    content: t('「{0}」会被删除，其中的商品不受影响。导航菜单里指向它的链接会自动隐藏。', [form.title]),
    positiveText: t('删除'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.delete(`/collections/${id.value}`)
      dirty.value = false
      invalidateCollections()
      message.success(t('已删除'))
      router.push({ name: 'collections' })
    },
  })
}

function addProducts(list: CollectionProduct[]) {
  products.value = [...products.value, ...list]
}

function removeProduct(pid: number) {
  products.value = products.value.filter((p) => p.id !== pid)
}

// Native drag to reorder (manual sort)
const dragIndex = ref<number | null>(null)
function onDragEnter(i: number) {
  const from = dragIndex.value
  if (from === null || from === i) return
  const list = [...products.value]
  const [moved] = list.splice(from, 1)
  list.splice(i, 0, moved!)
  products.value = list
  dragIndex.value = i
}

onMounted(() => { load(); if (!id.value) setTimeout(() => { dirty.value = false }) })
</script>

<template>
  <div class="editor">
    <NButton text class="back" @click="router.push({ name: 'collections' })">
      <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
      {{ t('商品分类') }}
    </NButton>

    <div class="head">
      <h1 class="page-title">{{ id ? form.title || t('编辑分类') : t('新建分类') }}</h1>
      <div class="head-actions">
        <NButton v-if="id" quaternary type="error" @click="destroy">{{ t('删除') }}</NButton>
        <a v-if="id && form.published && auth.currentStore" :href="`${auth.currentStore.storefront_url}/collections/${form.handle}`" target="_blank">
          <NButton secondary>{{ t('在店铺查看') }}</NButton>
        </a>
        <NButton type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton>
      </div>
    </div>

    <NSkeleton v-if="loading" text :repeat="6" />

    <div v-else class="grid">
      <div class="main">
        <NCard :title="t('基本信息')" size="small">
          <label class="label">{{ t('分类名称') }}</label>
          <NInput v-model:value="form.title" :placeholder="t('例如：项链、新品上市、节日礼物')" maxlength="255" />
          <label class="label" style="margin-top: 16px">{{ t('描述（可选，显示在分类页顶部）') }}</label>
          <NInput v-model:value="form.description" type="textarea" :autosize="{ minRows: 3, maxRows: 8 }" maxlength="5000" />
        </NCard>

        <NCard :title="t('收录方式')" size="small">
          <NRadioGroup v-model:value="kind">
            <NRadio value="manual">{{ t('手动选择商品') }}</NRadio>
            <NRadio value="smart">{{ t('按条件自动收录') }}</NRadio>
          </NRadioGroup>
          <template v-if="kind === 'smart'">
            <div class="match">
              <span>{{ t('商品需要满足') }}</span>
              <NSelect v-model:value="rulesMatch" :options="[{ value: 'all', label: t('全部条件') }, { value: 'any', label: t('任一条件') }]" size="small" style="width: 120px" />
            </div>
            <div v-for="(r, i) in rules" :key="i" class="rule">
              <NSelect v-model:value="r.field" :options="fieldOptions" size="small" style="width: 120px" @update:value="onField(r)" />
              <NSelect v-model:value="r.op" :options="opOptions[r.field]" size="small" style="width: 100px" />
              <NInputNumber v-if="r.field === 'price'" v-model:value="(r.value as number)" :min="0" :precision="2" size="small" style="width: 140px" />
              <NInput v-else-if="r.field !== 'stock'" v-model:value="(r.value as string)" size="small" maxlength="100" style="flex: 1; min-width: 140px" />
              <button type="button" class="icon-btn" :aria-label="t('删除条件')" @click="rules.splice(i, 1)"><NIcon size="16"><CloseOutline /></NIcon></button>
            </div>
            <NButton size="small" dashed @click="addRule">
              <template #icon><NIcon><AddOutline /></NIcon></template>{{ t('添加条件') }}
            </NButton>
            <p class="hint">{{ t('保存后，符合条件的商品会自动加入；以后新建或修改商品、库存变化时也会自动更新。') }}</p>
          </template>
        </NCard>

        <NCard size="small">
          <template #header>{{ t('商品') }} <span class="count">{{ products.length }}</span></template>
          <template #header-extra>
            <NButton v-if="kind === 'manual'" size="small" secondary @click="pickerOpen = true">
              <template #icon><NIcon><AddOutline /></NIcon></template>
              {{ t('添加商品') }}
            </NButton>
          </template>
          <NEmpty v-if="!products.length" :description="t('这个分类里还没有商品')" style="padding: 24px 0" />
          <p v-else-if="form.sort_order === 'manual'" class="hint">{{ t('拖动调整商品在分类页中的顺序') }}</p>
          <p v-else class="hint">{{ t('当前按「{0}」自动排序，切换到「手动排序」后可拖动调整', [sorts[form.sort_order]]) }}</p>
          <ul class="plist">
            <li
              v-for="(p, i) in products"
              :key="p.id"
              :draggable="form.sort_order === 'manual'"
              :class="{ dragging: dragIndex === i, manual: form.sort_order === 'manual' }"
              @dragstart="dragIndex = i"
              @dragenter.prevent="onDragEnter(i)"
              @dragover.prevent
              @dragend="dragIndex = null"
            >
              <NIcon v-if="form.sort_order === 'manual'" class="grip" size="16"><ReorderTwoOutline /></NIcon>
              <img :src="p.featured_image || '/placeholder.svg'" :class="{ ph: !p.featured_image }" alt="" />
              <span class="pname">{{ p.title }}</span>
              <NTag v-if="p.status !== 'active'" size="tiny" :bordered="false">{{ p.status === 'draft' ? t('草稿') : t('已归档') }}</NTag>
              <span class="muted">{{ auth.currentStore?.currency }} {{ p.price }}</span>
              <button v-if="kind === 'manual'" type="button" class="icon-btn" :aria-label="t('从分类中移除')" @click="removeProduct(p.id)">
                <NIcon size="16"><CloseOutline /></NIcon>
              </button>
            </li>
          </ul>
        </NCard>

        <SeoCard v-model="seo" :path="`/collections/${form.handle || '…'}`" :fallback-title="form.title" :fallback-description="form.description" />
      </div>

      <div class="side">
        <NCard :title="t('显示')" size="small">
          <div class="row"><span>{{ t('在店铺中显示') }}</span><NSwitch v-model:value="form.published" /></div>
          <label class="label" style="margin-top: 14px">{{ t('商品排序') }}</label>
          <NSelect v-model:value="form.sort_order" :options="sortOptions" />
        </NCard>
        <NCard :title="t('分类图片')" size="small">
          <FieldInput v-model="form.image_id" :field="{ type: 'image', label: t('不上传则使用第一个商品的图片') }" :media-urls="mediaUrls"
            @uploaded="(mid: number, url: string) => (mediaUrls[mid] = url)" />
        </NCard>
        <NCard v-if="id" :title="t('网址')" size="small">
          <NInput v-model:value="form.handle">
            <template #prefix><span class="muted">/collections/</span></template>
          </NInput>
        </NCard>
      </div>
    </div>

    <ProductPicker v-model:show="pickerOpen" :exclude="products.map((p) => p.id)" @pick="addProducts" />
  </div>
</template>

<style scoped>
.editor { container-type: inline-size; }
.back { margin-bottom: 12px; color: var(--ink-muted); }
.head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
.head .page-title { margin: 0; }
.head-actions { display: flex; gap: 8px; }
.head-actions a { text-decoration: none; }
.grid { display: grid; grid-template-columns: 1fr 300px; gap: 16px; align-items: start; }
.main, .side { display: grid; gap: 16px; min-width: 0; }
.label { display: block; font-size: 13px; margin-bottom: 6px; }
.count { font-weight: 400; color: var(--ink-muted); font-size: 13px; margin-left: 4px; }
.hint { font-size: 12px; color: var(--ink-muted); margin: 0 0 10px; }
.muted { color: var(--ink-muted); font-size: 13px; }
.row { display: flex; justify-content: space-between; align-items: center; font-size: 14px; }
.plist { list-style: none; margin: 0; padding: 0; }
.plist li { display: flex; align-items: center; gap: 10px; padding: 8px 4px; border-top: 1px solid var(--border); }
.plist li.manual { cursor: grab; }
.plist li.dragging { opacity: .4; }
.plist img { width: 40px; height: 40px; border-radius: 6px; object-fit: cover; background: var(--surface-muted); flex: none; }
.plist img.ph { object-fit: contain; padding: 6px; }
.pname { flex: 1; font-size: 14px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.grip { color: var(--ink-muted); }
.icon-btn { border: none; background: none; padding: 4px; border-radius: 6px; cursor: pointer; color: var(--ink-muted); display: flex; }
.icon-btn:hover { background: var(--surface-muted); color: var(--danger); }
@container (max-width: 760px) { .grid { grid-template-columns: 1fr; } }
.match { display: flex; align-items: center; gap: 8px; margin: 14px 0 8px; font-size: 13px; }
.rule { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
</style>
