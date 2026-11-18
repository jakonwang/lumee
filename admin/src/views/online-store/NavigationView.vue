<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import {
  NCard, NButton, NInput, NSelect, NIcon, NTooltip, NSpin, NAlert, useMessage, type SelectOption,
} from 'naive-ui'
import {
  AddOutline, ArrowUpOutline, ArrowDownOutline, ArrowForwardOutline, ArrowBackOutline, TrashOutline,
} from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { listCollections } from '@/api/collections'
import { t } from '@/i18n'

interface MenuItem {
  id: string
  title: string
  type: string
  target_id?: number | null
  url?: string
  target_label?: string | null
  broken?: boolean
  children?: MenuItem[]
}
interface Row extends MenuItem { depth: 0 | 1 }
interface MenuState { handle: string; title: string; rows: Row[]; saving: boolean; dirty: boolean }

const message = useMessage()
const loading = ref(true)
const menus = ref<MenuState[]>([])
const typeOptions = ref<SelectOption[]>([])
const collectionOptions = ref<SelectOption[]>([])
const pageOptions = ref<SelectOption[]>([])
const productOptions = reactive<Record<string, SelectOption[]>>({})   // per row id (remote search results)

const HINTS: Record<string, string> = {
  main: t('显示在店铺顶部。把一项「向右缩进」可以放进上一项的下拉菜单里。'),
  footer: t('显示在店铺底部。'),
}

// Tree <-> flat rows: a depth-1 row belongs to the nearest depth-0 row above it
function flatten(items: MenuItem[]): Row[] {
  return items.flatMap((i) => [
    { ...i, depth: 0 as const, children: undefined },
    ...(i.children ?? []).map((c) => ({ ...c, depth: 1 as const, children: undefined })),
  ])
}
function toTree(rows: Row[]): MenuItem[] {
  const out: MenuItem[] = []
  for (const r of rows) {
    const { depth, target_label: _l, broken: _b, ...item } = r
    if (depth === 1 && out.length) out[out.length - 1]!.children!.push(item)
    else out.push({ ...item, children: [] })
  }
  return out
}

async function load() {
  loading.value = true
  try {
    const [{ data }, collections] = await Promise.all([http.get('/menus'), listCollections(true)])
    typeOptions.value = Object.entries(data.types as Record<string, string>).map(([value, label]) => ({ value, label }))
    collectionOptions.value = collections.map((c) => ({ value: c.id, label: c.title }))
    pageOptions.value = (data.pages as { id: number; title: string }[]).map((p) => ({ value: p.id, label: p.title }))
    menus.value = data.data.map((m: { handle: string; title: string; items: MenuItem[] }) => ({
      handle: m.handle, title: m.title, rows: flatten(m.items), saving: false, dirty: false,
    }))
    menus.value.forEach((m) => m.rows.forEach(seedProductOption))
  } finally {
    loading.value = false
  }
}

function seedProductOption(r: Row) {
  if (r.type === 'product' && r.target_id) {
    productOptions[r.id] = [{ value: r.target_id, label: r.target_label ?? t('商品 #{0}', [r.target_id]) }]
  }
}

async function searchProducts(rowId: string, q: string) {
  const { data } = await http.get('/products', { params: { q: q || undefined, per_page: 20 } })
  productOptions[rowId] = data.data.map((p: { id: number; title: string }) => ({ value: p.id, label: p.title }))
}

function touch(m: MenuState) { m.dirty = true }

function addItem(m: MenuState) {
  m.rows.push({ id: Math.random().toString(36).slice(2, 10), title: '', type: 'collection', target_id: null, depth: 0 })
  touch(m)
}

function remove(m: MenuState, i: number) {
  const r = m.rows[i]!
  // Removing a parent also removes its dropdown children
  let n = 1
  if (r.depth === 0) while (m.rows[i + n]?.depth === 1) n++
  m.rows.splice(i, n)
  touch(m)
}

// A "block" = a top-level row plus its children (they move together)
function blockEnd(rows: Row[], i: number) {
  let e = i + 1
  if (rows[i]!.depth === 0) while (rows[e]?.depth === 1) e++
  return e
}

function move(m: MenuState, i: number, dir: -1 | 1) {
  const rows = m.rows
  const r = rows[i]!
  if (r.depth === 1) {
    const j = i + dir
    if (rows[j]?.depth !== 1) return          // children only move within their parent
    ;[rows[i], rows[j]] = [rows[j]!, rows[i]!]
  } else {
    const end = blockEnd(rows, i)
    if (dir === -1) {
      let prev = i - 1
      while (prev > 0 && rows[prev]!.depth === 1) prev--
      if (prev < 0 || i === 0) return
      const block = rows.splice(i, end - i)
      rows.splice(prev, 0, ...block)
    } else {
      if (end >= rows.length) return
      const nextEnd = blockEnd(rows, end)
      const next = rows.splice(end, nextEnd - end)
      rows.splice(i, 0, ...next)
    }
  }
  touch(m)
}

function canIndent(m: MenuState, i: number) {
  const r = m.rows[i]!
  return r.depth === 0 && i > 0 && m.rows[i + 1]?.depth !== 1   // only items without children
}
function indent(m: MenuState, i: number) { m.rows[i]!.depth = 1; touch(m) }
function outdent(m: MenuState, i: number) { m.rows[i]!.depth = 0; touch(m) }

function onTypeChange(m: MenuState, r: Row) {
  r.target_id = null
  r.broken = false
  if (!r.title) r.title = ({ home: 'Home', all_products: 'Shop All', collections: 'Collections', search: 'Search' } as Record<string, string>)[r.type] ?? r.title
  touch(m)
}

function onTargetChange(m: MenuState, r: Row, options: SelectOption[]) {
  r.broken = false
  // Prefill the title from the chosen collection/product
  if (!r.title) r.title = String(options.find((o) => o.value === r.target_id)?.label ?? '')
  touch(m)
}

async function save(m: MenuState) {
  const missing = m.rows.find((r) => !r.title.trim() || (['collection', 'product', 'page'].includes(r.type) && !r.target_id) || (r.type === 'url' && !r.url))
  if (missing) {
    message.error(t('请完整填写「{0}」的名称和链接', [missing.title || t('未命名')]))
    return
  }
  m.saving = true
  try {
    const { data } = await http.put(`/menus/${m.handle}`, { items: toTree(m.rows) })
    const saved = flatten(data.data.items)
    if (saved.length < m.rows.length) message.warning(t('部分链接无效（如不安全的网址），已被移除'))
    m.rows = saved
    m.rows.forEach(seedProductOption)
    m.dirty = false
    message.success(t('{0}已保存，店铺中立即生效', [m.title]))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    m.saving = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="eyebrow">Online Store</div>
    <h1 class="page-title">{{ t('导航菜单') }}</h1>
    <p class="page-sub">{{ t('设置店铺顶部和底部的菜单链接') }}</p>

    <div v-if="loading" class="center"><NSpin /></div>

    <NCard v-for="m in menus" v-else :key="m.handle" size="small" class="menu">
      <template #header>{{ m.title }}</template>
      <template #header-extra>
        <NButton type="primary" size="small" :disabled="!m.dirty" :loading="m.saving" @click="save(m)">{{ t('保存') }}</NButton>
      </template>
      <p class="hint">{{ HINTS[m.handle] }}</p>

      <NAlert v-if="m.rows.some((r) => r.broken)" type="warning" :bordered="false" style="margin-bottom: 12px">
        {{ t('有链接指向的分类或商品已被删除，店铺中已自动隐藏，请重新选择或删除。') }}
      </NAlert>

      <div v-for="(r, i) in m.rows" :key="r.id" class="item" :class="{ child: r.depth === 1, broken: r.broken }">
        <NInput v-model:value="r.title" :placeholder="t('显示名称')" size="small" maxlength="60" class="f-title" @update:value="touch(m)" />
        <NSelect v-model:value="r.type" :options="typeOptions" size="small" class="f-type" @update:value="onTypeChange(m, r)" />
        <NSelect v-if="r.type === 'collection'" v-model:value="r.target_id" :options="collectionOptions" size="small" class="f-target"
          :placeholder="t('选择分类')" filterable @update:value="onTargetChange(m, r, collectionOptions)" />
        <NSelect v-else-if="r.type === 'product'" v-model:value="r.target_id" :options="productOptions[r.id] ?? []" size="small" class="f-target"
          :placeholder="t('搜索商品')" filterable remote @search="(q: string) => searchProducts(r.id, q)" @focus="!productOptions[r.id]?.length && searchProducts(r.id, '')"
          @update:value="onTargetChange(m, r, productOptions[r.id] ?? [])" />
        <NSelect v-else-if="r.type === 'page'" v-model:value="r.target_id" :options="pageOptions" size="small" class="f-target"
          :placeholder="pageOptions.length ? t('选择页面') : t('还没有页面')" filterable @update:value="onTargetChange(m, r, pageOptions)" />
        <NInput v-else-if="r.type === 'url'" v-model:value="r.url" size="small" class="f-target" :placeholder="t('https://… 或 /pages/…')" @update:value="touch(m)" />
        <span v-else class="f-target" />

        <div class="tools">
          <NTooltip><template #trigger><button type="button" :disabled="i === 0" :aria-label="t('上移')" @click="move(m, i, -1)"><NIcon><ArrowUpOutline /></NIcon></button></template>{{ t('上移') }}</NTooltip>
          <NTooltip><template #trigger><button type="button" :disabled="i === m.rows.length - 1" :aria-label="t('下移')" @click="move(m, i, 1)"><NIcon><ArrowDownOutline /></NIcon></button></template>{{ t('下移') }}</NTooltip>
          <NTooltip v-if="r.depth === 0"><template #trigger><button type="button" :disabled="!canIndent(m, i)" :aria-label="t('向右缩进')" @click="indent(m, i)"><NIcon><ArrowForwardOutline /></NIcon></button></template>{{ t('放进上一项的下拉菜单') }}</NTooltip>
          <NTooltip v-else><template #trigger><button type="button" :aria-label="t('移出下拉菜单')" @click="outdent(m, i)"><NIcon><ArrowBackOutline /></NIcon></button></template>{{ t('移出下拉菜单') }}</NTooltip>
          <NTooltip><template #trigger><button type="button" class="danger" :aria-label="t('删除')" @click="remove(m, i)"><NIcon><TrashOutline /></NIcon></button></template>{{ t('删除') }}</NTooltip>
        </div>
      </div>

      <NButton dashed block size="small" style="margin-top: 10px" @click="addItem(m)">
        <template #icon><NIcon><AddOutline /></NIcon></template>
        {{ t('添加菜单项') }}
      </NButton>
    </NCard>
  </div>
</template>

<style scoped>
.page { max-width: 960px; display: grid; gap: 16px; }
.page > .page-sub { margin-top: -12px; }
.center { display: flex; justify-content: center; padding: 48px; }
.hint { font-size: 12px; color: var(--ink-muted); margin: 0 0 12px; }
.item {
  display: grid; grid-template-columns: minmax(120px, 1.2fr) 130px minmax(140px, 1.5fr) auto; gap: 8px; align-items: center;
  padding: 8px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 6px; background: var(--surface);
}
.item.child { margin-left: 32px; background: var(--surface-muted); position: relative; }
.item.child::before { content: ''; position: absolute; left: -20px; top: 50%; width: 14px; border-top: 1px solid var(--brand-300); }
.item.broken { border-color: #F2C4C4; }
.tools { display: flex; gap: 2px; }
.tools button { border: none; background: none; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--ink-muted); }
.tools button:hover:not(:disabled) { background: var(--brand-50); color: var(--brand-700); }
.tools button.danger:hover { background: #FDECEC; color: var(--danger); }
.tools button:disabled { opacity: .3; cursor: not-allowed; }
@media (max-width: 760px) {
  .item { grid-template-columns: 1fr 1fr; }
  .tools { grid-column: 1 / -1; justify-content: flex-end; }
  .item.child { margin-left: 16px; }
}
</style>
