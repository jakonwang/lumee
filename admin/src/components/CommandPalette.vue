<script setup lang="ts">
/**
 * Ctrl+K (⌘K on Mac) — jump to any admin page by typing part of its name ("运费", "批发价", "AI"…).
 * Lists every page of every section and every settings page this staff member may open (src/navigation.ts).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NModal, NIcon } from 'naive-ui'
import { SearchOutline } from '@vicons/ionicons5'
import { useAuth } from '@/stores/auth'
import { sections, appsSection, settingsGroups, settingsSection } from '@/navigation'
import { t } from '@/i18n'

const router = useRouter()
const auth = useAuth()
const open = ref(false)
const q = ref('')
const index = ref(0)
const input = ref<HTMLInputElement | null>(null)
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform)

const routePerm = new Map(router.getRoutes().map((r) => [String(r.name ?? ''), r.meta.perm as string | string[] | undefined]))
const allowed = (name: string) => router.hasRoute(name) && auth.can(routePerm.get(name))

interface Entry { name: string; label: string; where: string; text: string }
const entries = computed<Entry[]>(() => [
  ...[...sections, appsSection].flatMap((s) => s.pages.map((p) => ({
    name: p.name, label: p.label, where: s.pages.length > 1 ? s.label : '', text: `${p.label} ${s.label} ${p.keywords ?? ''}`,
  }))),
  ...settingsGroups.flatMap((g) => g.pages.map((p) => ({
    name: p.name, label: p.label, where: `${settingsSection.label} · ${g.label}`, text: `${p.label} ${g.label} ${settingsSection.label} ${p.keywords ?? ''}`,
  }))),
].filter((e) => allowed(e.name)))

const results = computed(() => {
  const words = q.value.trim().toLowerCase().split(/\s+/).filter(Boolean)
  const list = words.length ? entries.value.filter((e) => words.every((w) => e.text.toLowerCase().includes(w))) : entries.value
  // Page names that start with what was typed come first
  return [...list].sort((a, b) => Number(!a.label.toLowerCase().startsWith(words[0] ?? '')) - Number(!b.label.toLowerCase().startsWith(words[0] ?? ''))).slice(0, 12)
})
watch(q, () => { index.value = 0 })

function show() {
  open.value = true
  q.value = ''
  nextTick(() => input.value?.focus())
}
function go(e?: Entry) {
  if (!e) return
  open.value = false
  router.push({ name: e.name })
}
function onKey(ev: KeyboardEvent) {
  if (ev.key === 'ArrowDown') { ev.preventDefault(); index.value = Math.min(index.value + 1, results.value.length - 1) }
  else if (ev.key === 'ArrowUp') { ev.preventDefault(); index.value = Math.max(index.value - 1, 0) }
  else if (ev.key === 'Enter') { ev.preventDefault(); go(results.value[index.value]) }
}
function onGlobal(ev: KeyboardEvent) {
  if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') {
    ev.preventDefault()
    open.value ? (open.value = false) : show()
  }
}
onMounted(() => window.addEventListener('keydown', onGlobal))
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobal))
</script>

<template>
  <button type="button" class="trigger" :aria-label="t('搜索页面')" @click="show">
    <NIcon size="15"><SearchOutline /></NIcon>
    <span>{{ t('搜索页面') }}</span>
    <kbd>{{ isMac ? '⌘K' : 'Ctrl K' }}</kbd>
  </button>

  <NModal v-model:show="open" :auto-focus="false" transform-origin="center">
    <div class="palette" role="dialog" :aria-label="t('搜索页面')">
      <div class="search">
        <NIcon size="18"><SearchOutline /></NIcon>
        <input ref="input" v-model="q" type="text" :placeholder="t('输入页面名称，例如 运费、批发价、AI…')" :aria-label="t('搜索页面')"
          role="combobox" aria-controls="cmdk-list" :aria-activedescendant="results[index] ? 'cmdk-' + results[index]!.name : undefined" @keydown="onKey">
      </div>
      <ul id="cmdk-list" role="listbox" class="list">
        <li v-for="(r, i) in results" :id="'cmdk-' + r.name" :key="r.name" role="option" :aria-selected="i === index"
          :class="{ on: i === index }" @mouseenter="index = i" @click="go(r)">
          <span class="label">{{ r.label }}</span>
          <span v-if="r.where" class="where">{{ r.where }}</span>
        </li>
        <li v-if="!results.length" class="empty">{{ t('没有找到页面') }}</li>
      </ul>
      <div class="hint">{{ t('↑↓ 选择 · 回车打开 · Esc 关闭') }}</div>
    </div>
  </NModal>
</template>

<style scoped>
.trigger { display: flex; align-items: center; gap: 8px; height: 32px; padding: 0 10px; border: 1px solid var(--border); border-radius: 8px; background: #fff; color: var(--ink-muted); font: inherit; font-size: 13px; cursor: pointer; min-width: 200px; }
.trigger:hover { border-color: #C9D3CE; color: var(--ink); }
.trigger kbd { margin-left: auto; font-family: inherit; font-size: 11px; padding: 1px 6px; border-radius: 4px; background: #F1F4F2; color: var(--ink-muted); }
.palette { width: min(560px, 92vw); background: #fff; border-radius: 12px; box-shadow: 0 24px 64px rgba(16, 24, 20, .22); overflow: hidden; }
.search { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid var(--border); color: var(--ink-muted); }
.search input { flex: 1; border: 0; outline: none; font: inherit; font-size: 15px; color: var(--ink); background: transparent; }
.list { list-style: none; margin: 0; padding: 6px; max-height: 380px; overflow-y: auto; }
.list li { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 9px 12px; border-radius: 8px; cursor: pointer; }
.list li.on { background: #EAF1ED; }
.label { font-size: 14px; color: var(--ink); }
.where { font-size: 12px; color: var(--ink-muted); white-space: nowrap; }
.empty { color: var(--ink-muted); font-size: 13px; cursor: default !important; }
.hint { padding: 8px 16px; border-top: 1px solid var(--border); font-size: 11.5px; color: var(--ink-muted); }
@media (max-width: 720px) { .trigger span, .trigger kbd { display: none; } .trigger { min-width: 0; } }
</style>
