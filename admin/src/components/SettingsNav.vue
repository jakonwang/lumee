<script setup lang="ts">
/** Settings menu at the top of every settings page: the groups, then the pages of the current group (src/navigation.ts). */
import { computed } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuth } from '@/stores/auth'
import { settingsGroups } from '@/navigation'
import { t } from '@/i18n'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const routePerm = new Map(router.getRoutes().map((r) => [String(r.name ?? ''), r.meta.perm as string | string[] | undefined]))
const allowed = (name: string) => router.hasRoute(name) && auth.can(routePerm.get(name))

const groups = computed(() => settingsGroups.map((g) => ({ ...g, pages: g.pages.filter((p) => allowed(p.name)) })).filter((g) => g.pages.length))
const current = computed(() => String(route.name ?? ''))
const group = computed(() => groups.value.find((g) => g.pages.some((p) => p.name === current.value)) ?? groups.value[0])
</script>

<template>
  <nav class="settings-nav" :aria-label="t('设置')">
    <div class="groups">
      <RouterLink v-for="g in groups" :key="g.label" :to="{ name: g.pages[0]!.name }" class="grp" :class="{ on: g === group }">{{ g.label }}</RouterLink>
    </div>
    <div v-if="group && group.pages.length > 1" class="pages">
      <RouterLink v-for="p in group.pages" :key="p.name" :to="{ name: p.name }" class="pg" :class="{ on: p.name === current }"
        :aria-current="p.name === current ? 'page' : undefined">{{ p.label }}</RouterLink>
    </div>
  </nav>
</template>

<style scoped>
.settings-nav { margin-bottom: 8px; }
.groups { display: flex; gap: 2px; border-bottom: 1px solid var(--border); overflow-x: auto; scrollbar-width: none; }
.grp { padding: 10px 14px; white-space: nowrap; font-size: 14px; color: var(--ink-muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.grp:hover { color: var(--ink); }
.grp.on { color: var(--ink); font-weight: 600; border-bottom-color: var(--brand-600, #0E8A5F); }
.pages { display: flex; flex-wrap: wrap; gap: 6px; padding: 12px 0 4px; }
.pg { padding: 5px 12px; border-radius: 999px; font-size: 13px; color: #5C6963; text-decoration: none; background: transparent; border: 1px solid transparent; }
.pg:hover { background: #F1F4F2; color: var(--ink); }
.pg.on { background: #EAF1ED; color: var(--ink); font-weight: 500; border-color: #D6E5DC; }
</style>
