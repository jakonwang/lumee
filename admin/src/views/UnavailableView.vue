<script setup lang="ts">
/** Shown when the admin can't reach the server at startup (instead of a blank page), with a way to try again. */
import { useRoute } from 'vue-router'
import { NButton } from 'naive-ui'
import { t } from '@/i18n'

const route = useRoute()
const retry = () => { window.location.href = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/' }
</script>

<template>
  <main class="unavailable">
    <img src="/platform-mark.svg" alt="" width="40" height="40">
    <h1>{{ t('暂时连接不上服务器') }}</h1>
    <p>{{ t('可能是网络不稳定，或服务器正在更新。你的数据不会丢失，稍后再试即可。') }}</p>
    <NButton type="primary" @click="retry">{{ t('重新连接') }}</NButton>
  </main>
</template>

<style scoped>
.unavailable { min-height: 100vh; display: grid; place-content: center; justify-items: center; gap: 12px; padding: 24px; text-align: center; background: var(--bg, #F7F9F8); }
h1 { margin: 8px 0 0; font-size: 20px; color: var(--ink); }
p { margin: 0 0 8px; max-width: 380px; color: var(--ink-muted); font-size: 14px; line-height: 1.6; }
</style>
