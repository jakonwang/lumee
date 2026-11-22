<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { listCollections } from '@/api/collections'
import { NInput, NSelect, NSlider, NInputNumber, NSwitch, NColorPicker, NButton, NSpin, NDatePicker, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import type { Field } from '@/api/theme'

const props = defineProps<{ field: Field; mediaUrls: Record<number, string> }>()
const value = defineModel<unknown>({ required: true })
const emit = defineEmits<{ uploaded: [id: number, url: string] }>()
const message = useMessage()

// "collection" fields pick one of the store's collections
const collectionOptions = ref<{ value: number; label: string }[]>([])
const collectionId = computed({
  get: () => (typeof value.value === 'number' ? value.value : null),
  set: (v: number | null) => { value.value = v },
})
onMounted(async () => {
  if (props.field.type === 'collection') {
    collectionOptions.value = (await listCollections()).map((c) => ({ value: c.id, label: c.title }))
  }
})

// "product" fields: search the store's products by title
const productOptions = ref<{ value: number; label: string }[]>([])
const productLoading = ref(false)
const productId = computed({
  get: () => (typeof value.value === 'number' ? value.value : null),
  set: (v: number | null) => { value.value = v },
})
async function searchProducts(q: string) {
  productLoading.value = true
  try {
    const rows = (await http.get('/products', { params: { q: q || undefined, per_page: 20 } })).data.data as { id: number; title: string }[]
    const keep = productOptions.value.filter((o) => o.value === productId.value)
    productOptions.value = [...keep, ...rows.filter((r) => r.id !== productId.value).map((r) => ({ value: r.id, label: r.title }))]
  } finally {
    productLoading.value = false
  }
}
onMounted(async () => {
  if (props.field.type !== 'product') return
  if (productId.value) {
    try {
      const p = (await http.get(`/products/${productId.value}`)).data.data as { id: number; title: string }
      productOptions.value = [{ value: p.id, label: p.title }]
    } catch { /* deleted product: shown as empty */ }
  }
  await searchProducts('')
})
// "datetime" fields: "2026-11-28T23:59" (store time zone) ⇄ the date picker's timestamp
const dateTs = computed({
  get: () => (typeof value.value === 'string' && value.value ? new Date(value.value).getTime() : null),
  set: (ts: number | null) => {
    if (!ts) { value.value = null; return }
    const d = new Date(ts)
    const pad = (n: number) => String(n).padStart(2, '0')
    value.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
  },
})

const selectOptions = computed(() =>
  Object.entries(props.field.options ?? {}).map(([value, label]) => ({ value, label })),
)
const str = computed({ get: () => (value.value as string) ?? '', set: (v: string) => { value.value = v } })
const num = computed({ get: () => Number(value.value ?? props.field.min ?? 0), set: (v: number | null) => { value.value = v ?? props.field.min } })
const bool = computed({ get: () => Boolean(value.value), set: (v: boolean) => { value.value = v } })
const imageUrl = computed(() => (typeof value.value === 'number' ? props.mediaUrls[value.value] : undefined))

const input = ref<HTMLInputElement>()
const uploading = ref(false)
async function onFile(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  ;(e.target as HTMLInputElement).value = ''
  if (!file) return
  uploading.value = true
  try {
    const body = new FormData()
    body.append('file', file)
    const { data } = await http.post('/uploads/media', body)
    emit('uploaded', data.data.id, data.data.url)
    value.value = data.data.id
  } catch (err) {
    message.error(errorMessage(err))
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <div class="field">
    <label class="label">{{ field.label }}</label>

    <NInput v-if="field.type === 'text'" v-model:value="str" :maxlength="field.max" />

    <NInput v-else-if="field.type === 'textarea'" v-model:value="str" type="textarea" :autosize="{ minRows: 2, maxRows: 8 }" :maxlength="field.max" show-count />

    <NInput v-else-if="field.type === 'url'" v-model:value="str" :placeholder="t('/products/xxx、#shop 或 https://…')" />

    <NColorPicker v-else-if="field.type === 'color'" v-model:value="str" :modes="['hex']" :show-alpha="false"
      :swatches="['#0E8A5F', '#14A06E', '#B08A78', '#8A6655', '#C9B4E8', '#F2B5BC', '#9FD8E0', '#F2E19B', '#FDFBF9', '#3E3330', '#1F2933', '#FFFFFF']" />

    <NSelect v-else-if="field.type === 'select'" v-model:value="str" :options="selectOptions" />

    <NSelect v-else-if="field.type === 'collection'" v-model:value="collectionId" :options="collectionOptions"
      clearable filterable :placeholder="t('不选')" />
    <p v-if="field.type === 'collection' && !collectionOptions.length" class="hint-empty">{{ t('还没有分类，先在「商品 → 商品分类」中创建') }}</p>

    <NSelect v-else-if="field.type === 'product'" v-model:value="productId" :options="productOptions" :loading="productLoading"
      clearable filterable remote :placeholder="t('搜索商品')" @search="searchProducts" />

    <NDatePicker v-else-if="field.type === 'datetime'" v-model:value="dateTs" type="datetime" clearable format="yyyy-MM-dd HH:mm" style="width: 100%" />

    <div v-else-if="field.type === 'range'" class="range">
      <NSlider v-model:value="num" :min="field.min" :max="field.max" :step="field.step ?? 1" style="flex: 1" />
      <NInputNumber v-model:value="num" :min="field.min" :max="field.max" :step="field.step ?? 1" size="small" style="width: 92px">
        <template v-if="field.unit" #suffix>{{ field.unit }}</template>
      </NInputNumber>
    </div>

    <NSwitch v-else-if="field.type === 'checkbox'" v-model:value="bool" />

    <div v-else-if="field.type === 'image'" class="image">
      <div v-if="imageUrl" class="preview"><img :src="imageUrl" alt="" /></div>
      <div class="image-actions">
        <NButton size="small" :disabled="uploading" @click="input?.click()">
          <template v-if="uploading" #icon><NSpin :size="14" /></template>
          {{ imageUrl ? t('更换图片') : t('上传图片') }}
        </NButton>
        <NButton v-if="imageUrl" size="small" quaternary type="error" @click="value = null">{{ t('移除') }}</NButton>
      </div>
      <input ref="input" type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden @change="onFile" />
    </div>
  </div>
</template>

<style scoped>
.field { margin-bottom: 18px; }
.label { display: block; font-size: 13px; color: var(--ink); margin-bottom: 6px; }
.range { display: flex; gap: 12px; align-items: center; }
.hint-empty { font-size: 12px; color: var(--ink-muted); margin: 6px 0 0; }
.image .preview {
  border-radius: 10px; overflow: hidden; border: 1px solid var(--border); margin-bottom: 8px;
  background: repeating-conic-gradient(#f3eeea 0% 25%, #fff 0% 50%) 50% / 16px 16px;
}
.image .preview img { display: block; width: 100%; max-height: 160px; object-fit: contain; }
.image-actions { display: flex; gap: 6px; }
</style>
