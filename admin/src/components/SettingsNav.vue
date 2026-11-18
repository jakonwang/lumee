<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

const auth = useAuth()
const links = computed(() => [
  { name: 'settings-general', label: t('店铺信息'), perm: 'settings' },
  { name: 'settings-billing', label: t('套餐与账单'), perm: '' },
  { name: 'settings-payments', label: t('收款'), perm: 'settings' },
  { name: 'settings-shipping', label: t('运费'), perm: 'settings' },
  { name: 'settings-taxes', label: t('税费'), perm: 'settings' },
  { name: 'settings-risk', label: t('风控'), perm: 'settings' },
  { name: 'settings-languages', label: t('语言'), perm: 'settings' },
  { name: 'settings-currencies', label: t('多币种'), perm: 'settings' },
  { name: 'settings-domains', label: t('域名'), perm: 'settings' },
  { name: 'settings-notifications', label: t('邮件通知'), perm: 'settings' },
  { name: 'settings-staff', label: t('员工'), perm: 'staff' },
  { name: 'settings-security', label: t('账户安全'), perm: '' },
  { name: 'settings-activity', label: t('操作日志'), perm: 'settings' },
  { name: 'settings-developers', label: t('开发者'), perm: 'settings' },
].filter((l) => auth.can(l.perm)))
const nav = ref<HTMLElement | null>(null)
// Many tabs scroll sideways on narrow screens: keep the current one visible
onMounted(() => nextTick(() => nav.value?.querySelector('.on')?.scrollIntoView({ block: 'nearest', inline: 'center' })))
</script>

<template>
  <nav ref="nav" class="tabs" :aria-label="t('设置')">
    <RouterLink v-for="l in links" :key="l.name" :to="{ name: l.name }" class="tab" active-class="on">{{ l.label }}</RouterLink>
  </nav>
</template>

<style scoped>
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 4px; overflow-x: auto; overflow-y: hidden; scrollbar-width: none; }
.tab { padding: 10px 14px; white-space: nowrap; flex-shrink: 0; font-size: 14px; color: var(--ink-muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab:hover { color: var(--ink); }
.tab.on { color: var(--brand-700); border-bottom-color: var(--brand-600); font-weight: 500; }
</style>
