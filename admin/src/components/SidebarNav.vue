<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { NIcon } from 'naive-ui'
import { useAuth } from '@/stores/auth'
import { appsSection, sectionOf, sections, settingsSection, type NavSection } from '@/navigation'
import { t } from '@/i18n'

/**
 * Main navigation: one line per section, nothing nested. A section's pages are tabs at the top of the page
 * (SectionTabs), and Ctrl+K finds any page. Sections, pages and their order live in src/navigation.ts.
 */
const route = useRoute()
const router = useRouter()
const auth = useAuth()

/** Each page's permission comes from its route, so a section only shows when this staff member may open one of its pages. */
const routePerm = new Map(router.getRoutes().map((r) => [String(r.name ?? ''), r.meta.perm as string | string[] | undefined]))
const allowed = (name: string) => router.hasRoute(name) && auth.can(routePerm.get(name))
/** Where a section's link goes: the first of its pages this user may open. */
const landing = (s: NavSection) => s.pages.find((p) => allowed(p.name))?.name

const main = computed(() => sections.filter((s) => !s.channel && landing(s)))
const channels = computed(() => sections.filter((s) => s.channel && landing(s)))
const canSettings = computed(() => auth.can(['settings', 'staff']))

/** The page the user is on (detail pages count as their list page through meta.menu). */
const current = computed(() => String(route.meta.menu ?? route.name ?? ''))
const active = computed(() => sectionOf(current.value)?.key)
</script>

<template>
  <nav class="nav" :aria-label="t('主菜单')">
    <div class="group">
      <RouterLink v-for="s in main" :key="s.key" :to="{ name: landing(s) }" class="item" :class="{ on: active === s.key }"
        :aria-current="active === s.key ? 'page' : undefined">
        <NIcon size="18" class="ico"><component :is="s.icon" /></NIcon>
        <span>{{ s.label }}</span>
      </RouterLink>
    </div>

    <div v-if="channels.length" class="group">
      <div class="group-label">{{ t('销售渠道') }}</div>
      <RouterLink v-for="s in channels" :key="s.key" :to="{ name: landing(s) }" class="item" :class="{ on: active === s.key }"
        :aria-current="active === s.key ? 'page' : undefined">
        <NIcon size="18" class="ico"><component :is="s.icon" /></NIcon>
        <span>{{ s.label }}</span>
      </RouterLink>
    </div>

    <div class="spacer" />
    <div class="group">
      <RouterLink v-if="landing(appsSection)" :to="{ name: landing(appsSection) }" class="item" :class="{ on: active === 'apps' }">
        <NIcon size="18" class="ico"><component :is="appsSection.icon" /></NIcon>
        <span>{{ appsSection.label }}</span>
      </RouterLink>
      <RouterLink v-if="canSettings" :to="{ name: 'settings' }" class="item" :class="{ on: active === 'settings' }">
        <NIcon size="18" class="ico"><component :is="settingsSection.icon" /></NIcon>
        <span>{{ settingsSection.label }}</span>
      </RouterLink>
    </div>
  </nav>
</template>

<style scoped>
.nav { display: flex; flex-direction: column; flex: 1; min-height: 0; overflow-y: auto; gap: 18px; }
.group { display: grid; gap: 2px; }
.group-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; color: var(--ink-muted); padding: 0 10px 6px; }
.spacer { flex: 1; }
.item {
  position: relative; display: flex; align-items: center; gap: 10px;
  height: 36px; padding: 0 10px; border-radius: 8px;
  color: #3D4943; font-size: 14px; font-weight: 500; text-decoration: none;
  transition: background-color .12s, color .12s;
}
.item .ico { color: #7A8680; transition: color .12s; }
.item:hover { background: #F1F4F2; color: var(--ink); }
.item.on { background: #EAF1ED; color: var(--ink); font-weight: 600; }
.item.on .ico { color: var(--brand-600, #0E8A5F); }
</style>
