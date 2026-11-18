<script setup lang="ts">
import { computed, h, type Component } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { NLayout, NLayoutSider, NLayoutHeader, NLayoutContent, NMenu, NIcon, NButton, type MenuOption } from 'naive-ui'
import { GridOutline, StorefrontOutline, DocumentTextOutline, ArrowBackOutline } from '@vicons/ionicons5'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

const route = useRoute()
const router = useRouter()
const auth = useAuth()

const icon = (c: Component) => () => h(NIcon, null, { default: () => h(c) })
const link = (name: string, label: string) => () => h(RouterLink, { to: { name } }, { default: () => label })
const menu: MenuOption[] = [
  { key: 'ops', label: link('ops', t('平台概览')), icon: icon(GridOutline) },
  { key: 'ops-stores', label: link('ops-stores', t('店铺')), icon: icon(StorefrontOutline) },
  { key: 'ops-invoices', label: link('ops-invoices', t('平台账单')), icon: icon(DocumentTextOutline) },
  { key: 'ops-apps', label: link('ops-apps', t('应用审核')), icon: icon(GridOutline) },
  { key: 'ops-themes', label: link('ops-themes', t('主题审核')), icon: icon(GridOutline) },
  { key: 'ops-payouts', label: link('ops-payouts', t('开发者结算')), icon: icon(DocumentTextOutline) },
]
const active = computed(() => String(route.meta.menu ?? route.name))
</script>

<template>
  <NLayout has-sider class="shell">
    <NLayoutSider bordered :width="220" content-style="padding: 20px 12px;">
      <div class="brand">
        <img src="/platform-mark.svg" alt="" />
        <div>
          <div class="display brand-name">Lumée</div>
          <div class="eyebrow">Ops console</div>
        </div>
      </div>
      <NMenu :options="menu" :value="active" :indent="18" />
    </NLayoutSider>
    <NLayout>
      <NLayoutHeader bordered class="header">
        <div class="crumb">{{ t('平台运营 · {0}', [route.meta.title]) }}</div>
        <div class="header-right">
          <span class="muted">{{ auth.user?.email }}</span>
          <NButton v-if="auth.stores.length" size="small" quaternary @click="router.push('/')">
            <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
            {{ t('返回店铺后台') }}
          </NButton>
        </div>
      </NLayoutHeader>
      <NLayoutContent content-style="padding: 32px 40px;" class="content">
        <RouterView />
      </NLayoutContent>
    </NLayout>
  </NLayout>
</template>

<style scoped>
.shell { height: 100vh; }
.content { height: calc(100vh - 60px); }
.brand { display: flex; align-items: center; gap: 10px; padding: 0 8px 22px; }
.brand img { width: 34px; height: 34px; }
.brand-name { font-size: 20px; line-height: 1; color: var(--ink); }
.brand .eyebrow { font-size: 9px; margin-top: 4px; }
.header { height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 40px; }
.crumb { font-size: 14px; color: var(--ink-muted); }
.header-right { display: flex; align-items: center; gap: 12px; }
.muted { color: var(--ink-muted); font-size: 13px; }
</style>
