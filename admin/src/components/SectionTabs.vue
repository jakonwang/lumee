<script setup lang="ts">
/** The current section's pages as tabs above the page (the sidebar only lists sections). Hidden for one-page sections. */
import { computed } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuth } from '@/stores/auth'
import { pageOf, sectionOf } from '@/navigation'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const routePerm = new Map(router.getRoutes().map((r) => [String(r.name ?? ''), r.meta.perm as string | string[] | undefined]))

const current = computed(() => String(route.meta.menu ?? route.name ?? ''))
const section = computed(() => sectionOf(current.value))
const tabs = computed(() => (section.value?.pages ?? []).filter((p) => router.hasRoute(p.name) && auth.can(routePerm.get(p.name))))
const active = computed(() => pageOf(current.value)?.name)
/** Only on a section's own pages, not on detail / edit pages (a product, an order…) */
const onTabPage = computed(() => pageOf(String(route.name ?? '')) !== null)
</script>

<template>
  <nav v-if="section && onTabPage && tabs.length > 1" class="section-tabs" :aria-label="section.label">
    <RouterLink v-for="p in tabs" :key="p.name" :to="{ name: p.name }" class="tab" :class="{ on: active === p.name }"
      :aria-current="active === p.name ? 'page' : undefined">{{ p.label }}</RouterLink>
  </nav>
</template>

<style scoped>
.section-tabs { display: flex; gap: 2px; margin: -12px 0 24px; border-bottom: 1px solid var(--border); overflow-x: auto; scrollbar-width: none; }
.tab { padding: 10px 14px; white-space: nowrap; font-size: 14px; color: var(--ink-muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab:hover { color: var(--ink); }
.tab.on { color: var(--ink); font-weight: 600; border-bottom-color: var(--brand-600, #0E8A5F); }
</style>
