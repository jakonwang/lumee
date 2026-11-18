<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { NLayout, NLayoutSider, NLayoutHeader, NLayoutContent, NIcon, NButton, NDropdown, NAvatar } from 'naive-ui'
import { OpenOutline, SwapVerticalOutline } from '@vicons/ionicons5'
import SidebarNav from '@/components/SidebarNav.vue'
import CreateStoreModal from '@/components/CreateStoreModal.vue'
import { useAuth } from '@/stores/auth'
import { t, locale, setLocale } from '@/i18n'

const route = useRoute()
const router = useRouter()
const auth = useAuth()

// Store menu: switch between stores (permissions change with the store), see all of them, or open a new one
const storeOptions = computed(() => [
  { type: 'group', key: 'g', label: t('我的店铺'), children: auth.stores.map((s) => ({
    key: s.id,
    label: `${s.name}${s.role === 'owner' ? '' : t('（员工）')}`,
    disabled: s.id === auth.currentStore?.id,
  })) },
  { type: 'divider', key: 'd' },
  { key: 'overview', label: t('所有店铺概览') },
  { key: 'create', label: t('新建店铺') },
])
const createOpen = ref(false)
function onStoreMenu(key: number | string) {
  if (key === 'overview') { router.push({ name: 'stores' }); return }
  if (key === 'create') { createOpen.value = true; return }
  auth.switchStore(Number(key))
  window.location.href = '/'   // reload so every page refetches with the new store + permissions
}

const userOptions = computed(() => [
  ...(auth.user?.is_platform_admin ? [{ label: t('平台运营后台'), key: 'ops' }] : []),
  { label: t('我的应用（开发者）'), key: 'partner' },
  // Language names stay in their own language so people can always find theirs
  { label: locale === 'en' ? '切换到中文' : 'Switch to English', key: 'lang' },
  { label: t('退出登录'), key: 'logout' },
])

// Trial / payment reminders shown on every page (not on the billing page itself)
const banner = computed(() => {
  const b = auth.currentStore?.billing
  if (!b || route.name === 'settings-billing') return null
  if (b.state === 'trialing' && (b.trial_days_left ?? 99) <= 7) {
    return { tone: 'info', text: b.trial_days_left === 0 ? t('免费试用今天结束') : t('免费试用还剩 {0} 天', [b.trial_days_left]), action: t('选择套餐') }
  }
  if (b.state === 'past_due') return { tone: 'warn', text: t('套餐续费扣款失败，请尽快更新付款方式，以免店铺暂停营业'), action: t('查看账单') }
  if (b.state === 'expired') return { tone: 'warn', text: t('店铺已暂停营业：试用已结束或套餐已到期'), action: t('选择套餐') }
  if (b.state === 'suspended') return { tone: 'warn', text: t('店铺已被平台暂停'), action: t('查看详情') }
  return null
})

async function onUser(key: string) {
  if (key === 'lang') {
    setLocale(locale === 'en' ? 'zh-CN' : 'en')
    return
  }
  if (key === 'ops') {
    router.push({ name: 'ops' })
    return
  }
  if (key === 'partner') {
    router.push({ name: 'partner-apps' })
    return
  }
  if (key === 'logout') {
    await auth.logout()
    router.push('/login')
  }
}
</script>

<template>
  <NLayout has-sider class="shell">
    <NLayoutSider bordered :width="236" class="sider" content-style="padding: 14px 12px 16px; display:flex; flex-direction:column; height:100%;">
      <!-- Store switcher doubles as the brand row -->
      <NDropdown v-if="auth.currentStore" trigger="click" :options="storeOptions" @select="onStoreMenu">
        <button type="button" class="store switchable" :aria-label="t('切换店铺')">
          <span class="store-mark">{{ auth.currentStore.name.slice(0, 1).toUpperCase() }}</span>
          <span class="store-text">
            <span class="store-name">{{ auth.currentStore.name }}</span>
            <span class="store-sub">Lumée Commerce</span>
          </span>
          <NIcon size="14" class="chev"><SwapVerticalOutline /></NIcon>
        </button>
      </NDropdown>
      <SidebarNav />
    </NLayoutSider>
    <NLayout>
      <NLayoutHeader bordered class="header">
        <div class="crumb">{{ route.meta.title }}</div>
        <div class="header-right">
          <a v-if="auth.currentStore" :href="auth.currentStore.storefront_url" target="_blank">
            <NButton quaternary size="small">
              <template #icon><NIcon><OpenOutline /></NIcon></template>
              {{ t('查看店铺') }}
            </NButton>
          </a>
          <NDropdown :options="userOptions" @select="onUser">
            <div class="user">
              <NAvatar round size="small" color="#DDF4E9" style="color:#0A7350">{{ auth.user?.name?.[0] ?? 'L' }}</NAvatar>
              <span>{{ auth.user?.name }}</span>
            </div>
          </NDropdown>
        </div>
      </NLayoutHeader>
      <NLayoutContent :content-style="route.meta.fullBleed ? 'padding: 0' : 'padding: 32px 40px;'" class="content">
        <div v-if="banner && !route.meta.fullBleed" class="billing-bar" :class="banner.tone">
          <span>{{ banner.text }}</span>
          <RouterLink :to="{ name: 'settings-billing' }">{{ banner.action }} →</RouterLink>
        </div>
        <RouterView />
      </NLayoutContent>
      <CreateStoreModal v-model:show="createOpen" />
    </NLayout>
  </NLayout>
</template>

<style scoped>
.shell { height: 100vh; }
/* The content area is the single scroll container (needed for sticky page headers) */
.content { height: calc(100vh - 60px); }
.sider :deep(.n-layout-sider-scroll-container) { background: #FAFBFA; }
.store {
  display: flex; align-items: center; gap: 10px; width: 100%; margin-bottom: 18px; padding: 6px 8px;
  border: 0; border-radius: 10px; background: transparent; font: inherit; color: inherit; text-align: left; cursor: default;
}
.store.switchable { cursor: pointer; }
.store.switchable:hover { background: #F1F4F2; }
.store-mark {
  width: 30px; height: 30px; border-radius: 8px; flex: none; display: grid; place-items: center;
  background: var(--brand-600, #0E8A5F); color: #fff; font-weight: 600; font-size: 14px;
}
.store-text { display: grid; min-width: 0; line-height: 1.25; }
.store-name { font-size: 14px; font-weight: 600; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.store-sub { font-size: 11px; color: var(--ink-muted); }
.chev { margin-left: auto; color: var(--ink-muted); }
.billing-bar {
  display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
  margin: -8px 0 24px; padding: 10px 16px; border-radius: 10px; font-size: 14px;
  background: var(--brand-50); border: 1px solid var(--brand-100); color: var(--ink);
}
.billing-bar.warn { background: #FFF8EC; border-color: #F5DFB8; }
.billing-bar a { color: var(--brand-700); font-weight: 500; text-decoration: none; white-space: nowrap; }
.header { height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 40px; }
.crumb { font-size: 14px; color: var(--ink-muted); }
.header-right { display: flex; align-items: center; gap: 16px; }
.header-right a { text-decoration: none; }
.user { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; }
</style>
