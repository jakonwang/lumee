<script setup lang="ts">
import { computed, type Component } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { NIcon } from 'naive-ui'
import {
  HomeOutline, ReceiptOutline, PricetagOutline, PeopleOutline, TicketOutline, MegaphoneOutline,
  BarChartOutline, StorefrontOutline, SettingsOutline, AppsOutline, BulbOutline, CubeOutline, BriefcaseOutline,
} from '@vicons/ionicons5'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

/**
 * Main navigation, SaaS-style: sections grouped by job (sales, catalog & sourcing, growth,
 * sales channels), one line per section; a section's sub-pages only appear while you are inside
 * it. Apps and Settings sit at the bottom.
 */
interface Item { name: string; label: string }
interface Section { key: string; label: string; icon: Component; to: string; children?: Item[]; also?: string[] }
interface Group { key: string; label?: string; sections: Section[] }

const route = useRoute()
const router = useRouter()
const auth = useAuth()

const groups: Group[] = [
  { key: 'home', sections: [{ key: 'home', label: t('首页'), icon: HomeOutline, to: 'dashboard' }] },
  {
    key: 'sales', label: t('销售'),
    sections: [
      { key: 'orders', label: t('订单'), icon: ReceiptOutline, to: 'orders', children: [{ name: 'abandoned-checkouts', label: t('弃单') }, { name: 'subscriptions', label: t('订阅') }, { name: 'returns', label: t('退货') }] },
      { key: 'customers', label: t('客户'), icon: PeopleOutline, to: 'customers', children: [{ name: 'privacy-requests', label: t('隐私请求') }] },
      {
        key: 'b2b', label: t('B2B 批发'), icon: BriefcaseOutline, to: 'companies',
        children: [{ name: 'companies', label: t('公司') }, { name: 'quotes', label: t('询价单') }, { name: 'price-lists', label: t('批发价') }, { name: 'wholesale-catalogs', label: t('批发目录') }, { name: 'leads', label: t('获客雷达') }],
      },
    ],
  },
  {
    key: 'catalog', label: t('商品与货源'),
    sections: [
      { key: 'products', label: t('商品'), icon: PricetagOutline, to: 'products', children: [{ name: 'collections', label: t('商品分类') }, { name: 'reviews', label: t('评论') }, { name: 'gift-cards', label: t('礼品卡') }] },
      {
        key: 'sourcing', label: t('选品上新'), icon: BulbOutline, to: 'research',
        children: [{ name: 'research', label: t('选品雷达') }, { name: 'collect', label: t('采集') }, { name: 'content', label: t('内容工坊') }, { name: 'testing', label: t('测款') }],
      },
      { key: 'purchasing', label: t('采购'), icon: CubeOutline, to: 'purchasing' },
    ],
  },
  {
    key: 'growth', label: t('增长'),
    sections: [
      { key: 'marketing', label: t('营销'), icon: MegaphoneOutline, to: 'campaigns', children: [{ name: 'flows', label: t('自动化') }, { name: 'affiliates', label: t('联盟营销') }, { name: 'loyalty', label: t('积分') }, { name: 'settings-tracking', label: t('追踪代码') }] },
      { key: 'discounts', label: t('折扣'), icon: TicketOutline, to: 'marketing', children: [{ name: 'promotions', label: t('自动折扣') }] },
      { key: 'analytics', label: t('数据分析'), icon: BarChartOutline, to: 'analytics' },
    ],
  },
  {
    key: 'channels', label: t('销售渠道'),
    sections: [{
      key: 'online-store', label: t('在线商店'), icon: StorefrontOutline, to: 'online-store',
      children: [
        { name: 'design-performance', label: t('装修效果') },
        { name: 'pages', label: t('页面') }, { name: 'blog', label: t('博客') },
        { name: 'navigation', label: t('导航菜单') }, { name: 'redirects', label: t('网址重定向') }, { name: 'translations', label: t('翻译') },
      ],
    }],
  },
]
const apps: Section = { key: 'apps', label: t('应用'), icon: AppsOutline, to: 'apps', also: ['app-charge', 'app-embedded'] }
const settings: Section = { key: 'settings', label: t('设置'), icon: SettingsOutline, to: 'settings' }

/** Each page's permission comes from its route, so a section only lists what this staff member may open. */
const routePerm = new Map(router.getRoutes().map((r) => [String(r.name ?? ''), r.meta.perm as string | string[] | undefined]))
const allowed = (name: string) => router.hasRoute(name) && auth.can(routePerm.get(name))
const subs = (s: Section) => (s.children ?? []).filter((c) => allowed(c.name))
/** Where the section link goes: its landing page, or the first sub-page the user may open. */
const landing = (s: Section) => (allowed(s.to) ? s.to : subs(s)[0]?.name)

const visibleGroups = computed(() => groups
  .map((g) => ({ ...g, sections: g.sections.filter((s) => landing(s)) }))
  .filter((g) => g.sections.length))
const canSettings = computed(() => auth.can(['settings', 'staff']))

/** The page the user is on, as the menu sees it (detail pages map to their list via meta.menu). */
const current = computed(() => String(route.meta.menu ?? route.name ?? ''))
/** Pages listed under another section (e.g. tracking codes under Marketing) don't light up Settings. */
const claimed = new Set(groups.flatMap((g) => g.sections).flatMap((s) => (s.children ?? []).map((c) => c.name)))
function inSection(s: Section): boolean {
  if (s.key === 'settings') return current.value === 'settings' || (current.value.startsWith('settings-') && !claimed.has(current.value))
  const page = landing(s)
  return current.value === s.to || current.value === page || subs(s).some((c) => c.name === current.value) || (s.also ?? []).includes(current.value)
}
const exact = (s: Section) => current.value === landing(s) && !subs(s).some((c) => c.name === current.value)
</script>

<template>
  <nav class="nav" :aria-label="t('主菜单')">
    <div v-for="g in visibleGroups" :key="g.key" class="group">
      <div v-if="g.label" class="group-label">{{ g.label }}</div>
      <template v-for="s in g.sections" :key="s.key">
        <RouterLink :to="{ name: landing(s) }" class="item" :class="{ on: inSection(s), exact: exact(s) }">
          <NIcon size="18" class="ico"><component :is="s.icon" /></NIcon>
          <span>{{ s.label }}</span>
        </RouterLink>
        <div v-if="s.children && inSection(s)" class="subs">
          <RouterLink v-for="c in subs(s)" :key="c.name" :to="{ name: c.name }" class="sub" :class="{ on: current === c.name }">{{ c.label }}</RouterLink>
        </div>
      </template>
    </div>

    <div class="spacer" />
    <div class="group">
      <RouterLink v-if="allowed(apps.to)" :to="{ name: apps.to }" class="item" :class="{ on: inSection(apps), exact: inSection(apps) }">
        <NIcon size="18" class="ico"><component :is="apps.icon" /></NIcon>
        <span>{{ apps.label }}</span>
      </RouterLink>
      <RouterLink v-if="canSettings" :to="{ name: settings.to }" class="item" :class="{ on: inSection(settings), exact: inSection(settings) }">
        <NIcon size="18" class="ico"><component :is="settings.icon" /></NIcon>
        <span>{{ settings.label }}</span>
      </RouterLink>
    </div>
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
