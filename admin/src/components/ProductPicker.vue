<script setup lang="ts">
import { ref, watch } from 'vue'
import { NModal, NInput, NCheckbox, NButton, NSpin, NEmpty } from 'naive-ui'
import { http } from '@/api/http'
import type { CollectionProduct } from '@/api/collections'

/** Modal to pick products (search + multi-select). Emits the chosen products. */
const props = withDefaults(defineProps<{ exclude: number[]; endpoint?: string }>(), { endpoint: '/products' })
const show = defineModel<boolean>('show', { required: true })
const emit = defineEmits<{ pick: [products: CollectionProduct[]] }>()

const q = ref('')
const loading = ref(false)
const rows = ref<CollectionProduct[]>([])
const selected = ref<Map<number, CollectionProduct>>(new Map())

async function search() {
  loading.value = true
  try {
    const { data } = await http.get(props.endpoint, { params: { q: q.value || undefined, per_page: 50 } })
    rows.value = data.data.filter((p: CollectionProduct) => !props.exclude.includes(p.id))
  } finally {
    loading.value = false
  }
}

function toggle(p: CollectionProduct, on: boolean) {
  const next = new Map(selected.value)
  if (on) next.set(p.id, p)
  else next.delete(p.id)
  selected.value = next
}

function confirm() {
  emit('pick', [...selected.value.values()])
  show.value = false
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => { clearTimeout(timer); timer = setTimeout(search, 250) })
watch(show, (v) => {
  if (v) {
    selected.value = new Map()
    q.value = ''
    search()
  }
})
</script>

<template>
  <NModal v-model:show="show" preset="card" :title="t('添加商品')" style="width: min(560px, 94vw)">
    <NInput v-model:value="q" :placeholder="t('搜索商品名称')" clearable autofocus />
    <div class="list">
      <div v-if="loading" class="center"><NSpin size="small" /></div>
      <NEmpty v-else-if="!rows.length" :description="t('没有可添加的商品')" style="padding: 32px 0" />
      <label v-for="p in rows" v-else :key="p.id" class="row">
        <NCheckbox :checked="selected.has(p.id)" @update:checked="(v: boolean) => toggle(p, v)" />
        <img :src="p.featured_image || '/placeholder.svg'" :class="{ ph: !p.featured_image }" alt="" />
        <span class="name">{{ p.title }}</span>
        <span v-if="p.status !== 'active'" class="muted">{{ p.status === 'draft' ? t('草稿') : t('已归档') }}</span>
      </label>
    </div>
    <template #footer>
      <div class="foot">
        <span class="muted">{{ t('已选 {0} 件', [selected.size]) }}</span>
        <NButton type="primary" :disabled="!selected.size" @click="confirm">{{ t('添加') }}</NButton>
      </div>
    </template>
  </NModal>
</template>

<style scoped>
.list { margin-top: 12px; max-height: 50vh; overflow-y: auto; border: 1px solid var(--border); border-radius: 10px; }
.center { display: flex; justify-content: center; padding: 32px; }
.row { display: flex; align-items: center; gap: 12px; padding: 8px 12px; cursor: pointer; }
.row + .row { border-top: 1px solid var(--border); }
.row:hover { background: var(--brand-50); }
.row img { width: 40px; height: 40px; border-radius: 6px; object-fit: cover; background: var(--surface-muted); }
.row img.ph { object-fit: contain; padding: 6px; opacity: .5; }
.name { flex: 1; font-size: 14px; }
.muted { color: var(--ink-muted); font-size: 12px; }
.foot { display: flex; justify-content: space-between; align-items: center; }
</style>
