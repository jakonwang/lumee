<script setup lang="ts">
import { computed, type Component } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { NIcon } from 'naive-ui'
import {
  HomeOutline, ReceiptOutline, PricetagOutline, PeopleOutline, TicketOutline, MegaphoneOutline,
  BarChartOutline, StorefrontOutline, SettingsOutline, AppsOutline,
} from '@vicons/ionicons5'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

/**
 * Main navigation, SaaS-style: one line per section; a section's sub-pages only appear
 * while you are inside it. Settings sits at the bottom.
 */
interface Item { name: string; label: string }
interface Section { key: string; label: string; icon: Component; to: string; perm?: string | string[]; children?: Item[]; also?: string[] }

const route = useRoute()
const router = useRouter()
const auth = useAuth()

const main: Section[] = [
  { key: 'home', label: t('首页'), icon: HomeOutline, to: 'dashboard' },
  { key: 'orders', label: t('订单'), icon: ReceiptOutline, to: 'orders', perm: 'orders', children: [{ name: 'abandoned-checkouts', label: t('弃单') }, { name: 'subscriptions', label: t('订阅') }, { name: 'returns', label: t('退货') }] },
  {
    key: 'products', label: t('商品'), icon: PricetagOutline, to: 'products', perm: 'products',
    children: [{ name: 'collections', label: t('商品分类') }, { name: 'reviews', label: t('评论') }, { name: 'gift-cards', label: t('礼品卡') }, { name: 'price-lists', label: t('批发价') }],
  },
  { key: 'customers', label: t('客户'), icon: PeopleOutline, to: 'customers', perm: 'customers', children: [{ name: 'companies', label: t('公司') }, { name: 'quotes', label: t('询价单') }, { name: 'privacy-requests', label: t('隐私请求') }] },
  { key: 'discounts', label: t('折扣'), icon: TicketOutline, to: 'marketing', perm: 'marketing', children: [{ name: 'promotions', label: t('自动折扣') }] },
  { key: 'marketing', label: t('营销'), icon: MegaphoneOutline, to: 'campaigns', perm: 'marketing', children: [{ name: 'flows', label: t('自动化') }, { name: 'affiliates', label: t('联盟营销') }, { name: 'loyalty', label: t('积分') }, { name: 'settings-tracking', label: t('追踪代码') }] },
  { key: 'analytics', label: t('数据分析'), icon: BarChartOutline, to: 'analytics', perm: 'analytics' },
  { key: 'apps', label: t('应用'), icon: AppsOutline, to: 'apps', perm: 'settings', also: ['app-charge', 'app-embedded'] },
]
const channels: Section[] = [
  {
    key: 'online-store', label: t('在线商店'), icon: StorefrontOutline, to: 'online-store', perm: 'online_store',
    children: [
      { name: 'pages', label: t('页面') }, { name: 'blog', label: t('博客') },
      { name: 'navigation', label: t('导航菜单') }, { name: 'redirects', label: t('网址重定向') }, { name: 'translations', label: t('翻译') },
    ],
  },
]
const settings: Section = { key: 'settings', label: t('设置'), icon: SettingsOutline, to: 'settings', perm: ['settings', 'staff'] }

const visible = (list: Section[]) => list.filter((s) => auth.can(s.perm) && router.hasRoute(s.to))
const mainItems = computed(() => visible(main))
const channelItems = computed(() => visible(channels))

/** The page the user is on, as the menu sees it (detail pages map to their list via meta.menu). */
const current = computed(() => String(route.meta.menu ?? route.name ?? ''))
/** Pages listed under another section (e.g. tracking codes under Marketing) don't light up Settings. */
const claimed = computed(() => new Set([...main, ...channels].flatMap((s) => (s.children ?? []).map((c) => c.name))))
function inSection(s: Section): boolean {
  if (s.key === 'settings') return current.value === 'settings' || (current.value.startsWith('settings-') && !claimed.value.has(current.value))
  return current.value === s.to || (s.children ?? []).some((c) => c.name === current.value) || (s.also ?? []).includes(current.value)
}
</script>

<template>
  <nav class="nav" :aria-label="t('主菜单')">
    <div class="group">
      <template v-for="s in mainItems" :key="s.key">
        <RouterLink :to="{ name: s.to }" class="item" :class="{ on: inSection(s), exact: current === s.to }">
          <NIcon size="18" class="ico"><component :is="s.icon" /></NIcon>
          <span>{{ s.label }}</span>
        </RouterLink>
        <div v-if="s.children && inSection(s)" class="subs">
          <RouterLink v-for="c in s.children.filter((x) => router.hasRoute(x.name))" :key="c.name" :to="{ name: c.name }" class="sub" :class="{ on: current === c.name }">{{ c.label }}</RouterLink>
        </div>
      </template>
    </div>

    <div v-if="channelItems.length" class="group">
      <div class="group-label">{{ t('销售渠道') }}</div>
      <template v-for="s in channelItems" :key="s.key">
        <RouterLink :to="{ name: s.to }" class="item" :class="{ on: inSection(s), exact: current === s.to }">
          <NIcon size="18" class="ico"><component :is="s.icon" /></NIcon>
          <span>{{ s.label }}</span>
        </RouterLink>
        <div v-if="s.children && inSection(s)" class="subs">
          <RouterLink v-for="c in s.children.filter((x) => router.hasRoute(x.name))" :key="c.name" :to="{ name: c.name }" class="sub" :class="{ on: current === c.name }">{{ c.label }}</RouterLink>
        </div>
      </template>
    </div>

    <div class="spacer" />
    <RouterLink v-if="auth.can(settings.perm)" :to="{ name: settings.to }" class="item" :class="{ on: inSection(settings), exact: inSection(settings) }">
      <NIcon size="18" class="ico"><component :is="settings.icon" /></NIcon>
      <span>{{ settings.label }}</span>
    </RouterLink>
  </nav>
</template>

<style scoped>
.nav { display: flex; flex-direction: column; flex: 1; min-height: 0; overflow-y: auto; gap: 14px; }
.group { display: grid; gap: 1px; }
.group-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-muted); padding: 0 10px 6px; }
.spacer { flex: 1; }
.item {
  position: relative; display: flex; align-items: center; gap: 10px;
  height: 34px; padding: 0 10px; border-radius: 8px;
  color: #3D4943; font-size: 13.5px; font-weight: 500; text-decoration: none;
  transition: background-color .12s, color .12s;
}
.item .ico { color: #7A8680; transition: color .12s; }
.item:hover { background: #F1F4F2; color: var(--ink); }
.item.on { color: var(--ink); }
.item.on .ico { color: var(--brand-600, #0E8A5F); }
.item.exact { background: #EEF2EF; }
.subs { display: grid; gap: 1px; padding: 2px 0 6px 38px; }
.sub {
  position: relative; display: flex; align-items: center; height: 30px; padding: 0 10px; border-radius: 7px;
  color: #5C6963; font-size: 13px; text-decoration: none;
}
.sub:hover { background: #F1F4F2; color: var(--ink); }
.sub.on { background: #EEF2EF; color: var(--ink); font-weight: 500; }
</style>
