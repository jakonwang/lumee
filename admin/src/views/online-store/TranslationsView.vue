<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NCard, NButton, NSelect, NInput, NTabs, NTab, NEmpty, NSpin, NPagination, NProgress, NAlert, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

interface Field { key: string; label: string; source: string; multiline: boolean; value: string | null }
interface Item { id: number; label: string; fields: Field[]; done: number; total: number }
interface Lang { code: string; name: string }

const route = useRoute()
const router = useRouter()
const message = useMessage()

const langs = ref<Lang[]>([])
const defaultLang = ref('en')
const locale = ref(String(route.query.locale ?? ''))
const type = ref(String(route.query.type ?? 'product'))
const types = ref<Record<string, string>>({ product: t('商品') })
const q = ref('')
const page = ref(1)
const total = ref(0)
const items = ref<Item[]>([])
const loading = ref(false)
const selectedId = ref<number | null>(null)
const draft = reactive<Record<string, string>>({})
const saving = ref(false)

const langOptions = computed(() => langs.value.filter((l) => l.code !== defaultLang.value).map((l) => ({ value: l.code, label: l.name })))
const selected = computed(() => items.value.find((i) => i.id === selectedId.value) ?? null)
const dirty = computed(() => !!selected.value && selected.value.fields.some((f) => (draft[f.key] ?? '') !== (f.value ?? '')))
const langName = computed(() => langs.value.find((l) => l.code === locale.value)?.name ?? '')

async function loadLanguages() {
  const { data } = await http.get('/settings/languages')
  langs.value = data.languages
  defaultLang.value = data.default
  if (!locale.value || locale.value === data.default) locale.value = data.published[0] ?? langOptions.value[0]?.value ?? ''
}

async function load() {
  if (!locale.value) return
  loading.value = true
  try {
    const { data } = await http.get('/translations', { params: { type: type.value, locale: locale.value, q: q.value || undefined, page: page.value } })
    items.value = data.data
    total.value = data.total
    types.value = data.types
    if (!items.value.some((i) => i.id === selectedId.value)) select(items.value[0]?.id ?? null)
    else select(selectedId.value)
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    loading.value = false
  }
}

function select(id: number | null) {
  selectedId.value = id
  for (const k of Object.keys(draft)) delete draft[k]
  for (const f of selected.value?.fields ?? []) draft[f.key] = f.value ?? ''
}

function trySelect(id: number) {
  if (dirty.value && !window.confirm(t('当前内容有未保存的翻译，确定切换吗？'))) return
  select(id)
}

async function save() {
  if (!selected.value) return
  saving.value = true
  try {
    const fields = Object.fromEntries(selected.value.fields.map((f) => [f.key, draft[f.key] ?? '']))
    await http.put(`/translations/${type.value}/${selected.value.id}`, { locale: locale.value, fields })
    // Reflect the saved values locally
    for (const f of selected.value.fields) f.value = (draft[f.key] ?? '').trim() ? draft[f.key] : null
    selected.value.done = selected.value.fields.filter((f) => f.value).length
    message.success(t('翻译已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

function copySource() {
  for (const f of selected.value?.fields ?? []) if (!draft[f.key]) draft[f.key] = f.source
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { page.value = 1; load() }, 300)
})
watch([type, locale], () => {
  page.value = 1
  q.value = ''
  router.replace({ query: { type: type.value, locale: locale.value } })
  load()
})

onMounted(async () => {
  await loadLanguages()
  await load()
})
</script>

<template>
  <div>
    <div class="head">
      <div>
        <div class="eyebrow">Online store</div>
        <h1 class="page-title">{{ t('翻译') }}</h1>
        <p class="page-sub">{{ t('把商品、页面、菜单等内容翻译成其他语言。没有翻译的内容会显示默认语言。') }}</p>
      </div>
      <NSelect v-if="langOptions.length" v-model:value="locale" :options="langOptions" style="width: 180px" />
    </div>

    <NAlert v-if="!langOptions.length" type="info" :bordered="false">
      {{ t('还没有可翻译的语言。请先到「设置 → 语言」选择要发布的语言。') }}
    </NAlert>

    <template v-else>
      <NTabs v-model:value="type" type="line" size="small" style="margin-bottom: 12px">
        <NTab v-for="(label, key) in types" :key="key" :name="key">{{ label }}</NTab>
      </NTabs>

      <div class="layout">
        <NCard size="small" class="list" content-style="padding: 0">
          <div v-if="['product', 'collection', 'page'].includes(type)" class="search">
            <NInput v-model:value="q" size="small" :placeholder="t('搜索')" clearable />
          </div>
          <NSpin :show="loading">
            <NEmpty v-if="!items.length && !loading" :description="t('没有可翻译的内容')" style="padding: 32px 0" />
            <button v-for="i in items" :key="i.id" type="button" class="row" :class="{ on: i.id === selectedId }" @click="trySelect(i.id)">
              <span class="row-label">{{ i.label }}</span>
              <span class="row-progress" :class="{ full: i.done === i.total && i.total > 0 }">{{ i.done }}/{{ i.total }}</span>
            </button>
          </NSpin>
          <div v-if="total > 20" class="pager">
            <NPagination v-model:page="page" :page-size="20" :item-count="total" size="small" @update:page="load" />
          </div>
        </NCard>

        <NCard v-if="selected" size="small" class="editor">
          <template #header>
            <div class="ed-title">{{ selected.label }}</div>
            <NProgress type="line" :percentage="selected.total ? Math.round((selected.done / selected.total) * 100) : 0" :show-indicator="false" style="max-width: 200px; margin-top: 6px" />
          </template>
          <template #header-extra>
            <NButton size="small" quaternary @click="copySource">{{ t('复制原文到空白处') }}</NButton>
          </template>
          <div v-for="f in selected.fields" :key="f.key" class="field">
            <div class="f-label">{{ f.label }}</div>
            <div class="pair">
              <div class="source" :class="{ multi: f.multiline }">{{ f.source }}</div>
              <NInput v-model:value="draft[f.key]" :type="f.multiline ? 'textarea' : 'text'" :autosize="f.multiline ? { minRows: 3, maxRows: 14 } : undefined"
                :placeholder="t('{0}（留空则显示原文）', [langName])" />
            </div>
          </div>
          <div class="foot">
            <span v-if="dirty" class="muted">{{ t('有未保存的修改') }}</span>
            <NButton type="primary" :loading="saving" :disabled="!dirty" @click="save">{{ t('保存') }}</NButton>
          </div>
        </NCard>
      </div>
    </template>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.layout { display: grid; grid-template-columns: 280px 1fr; gap: 16px; align-items: start; }
.search { padding: 10px; border-bottom: 1px solid var(--border); }
.row { display: flex; justify-content: space-between; gap: 8px; width: 100%; padding: 10px 14px; border: 0; border-top: 1px solid var(--border); background: none; font: inherit; font-size: 13px; color: inherit; text-align: left; cursor: pointer; }
.row:first-of-type { border-top: 0; }
.row:hover { background: var(--surface-muted); }
.row.on { background: var(--brand-50); color: var(--brand-700); }
.row-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.row-progress { color: var(--ink-muted); font-size: 12px; font-variant-numeric: tabular-nums; flex: none; }
.row-progress.full { color: var(--brand-700); }
.pager { padding: 10px; display: flex; justify-content: center; border-top: 1px solid var(--border); }
.ed-title { font-weight: 600; }
.field { padding: 12px 0; border-top: 1px solid var(--border); }
.field:first-of-type { border-top: 0; padding-top: 0; }
.f-label { font-size: 12px; color: var(--ink-muted); margin-bottom: 6px; }
.pair { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.source { font-size: 13px; line-height: 1.6; padding: 6px 10px; background: var(--surface-muted); border-radius: 6px; word-break: break-word; }
.source.multi { white-space: pre-line; max-height: 280px; overflow: auto; }
.foot { display: flex; justify-content: flex-end; align-items: center; gap: 12px; margin-top: 12px; }
.muted { color: var(--ink-muted); font-size: 13px; }
@media (max-width: 960px) { .layout { grid-template-columns: 1fr; } .pair { grid-template-columns: 1fr; } }
</style>
