<script setup lang="ts">
/**
 * App Bridge: an embedded app runs in an iframe inside the admin. It gets a short-lived session token
 * in its URL and can ask for a fresh one, show a toast, set the page title or navigate the admin,
 * via postMessage (see backend/public/app-bridge.js). Messages are only accepted from the app's origin.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NAlert, NButton, NSpin, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

const route = useRoute()
const router = useRouter()
const message = useMessage()
const frame = ref<HTMLIFrameElement | null>(null)
const url = ref('')
const origin = ref('')
const title = ref('')
const appName = ref('')
const error = ref('')
const id = Number(route.params.id)

async function session() {
  return (await http.post(`/apps/installations/${id}/session`)).data as { token: string; url: string; origin: string; app: { name: string } }
}

function reply(msg: Record<string, unknown>) {
  frame.value?.contentWindow?.postMessage(msg, origin.value)
}

async function onMessage(ev: MessageEvent) {
  if (ev.origin !== origin.value || ev.source !== frame.value?.contentWindow) return
  const d = ev.data as { type?: string; id?: string; message?: string; error?: boolean; title?: string; path?: string }
  switch (d?.type) {
    case 'lumee:session':
      try {
        reply({ type: 'lumee:session', id: d.id, token: (await session()).token })
      } catch {
        reply({ type: 'lumee:session', id: d.id, error: 'unavailable' })
      }
      break
    case 'lumee:toast':
      if (typeof d.message === 'string') (d.error ? message.error : message.success)(d.message.slice(0, 200))
      break
    case 'lumee:title':
      if (typeof d.title === 'string') title.value = d.title.slice(0, 80)
      break
    case 'lumee:redirect':
      // Only admin paths (never another site)
      if (typeof d.path === 'string' && d.path.startsWith('/') && !d.path.startsWith('//')) router.push(d.path)
      break
  }
}

onMounted(async () => {
  window.addEventListener('message', onMessage)
  try {
    const s = await session()
    url.value = s.url
    origin.value = s.origin
    appName.value = s.app.name
  } catch (e) {
    error.value = errorMessage(e)
  }
})
onBeforeUnmount(() => window.removeEventListener('message', onMessage))
</script>

<template>
  <div class="page">
    <div class="bar">
      <NButton size="small" quaternary @click="router.push({ name: 'apps' })">← {{ t('应用') }}</NButton>
      <strong>{{ appName }}</strong><span v-if="title" class="muted">/ {{ title }}</span>
    </div>
    <NAlert v-if="error" type="warning" :bordered="false">{{ error }}</NAlert>
    <div v-else-if="!url" class="center"><NSpin /></div>
    <iframe v-else ref="frame" :src="url" class="frame" :title="appName"
      sandbox="allow-scripts allow-forms allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-downloads" referrerpolicy="strict-origin" />
  </div>
</template>

<style scoped>
.page { display: grid; grid-template-rows: auto 1fr; gap: 10px; height: calc(100vh - 120px); }
.bar { display: flex; align-items: center; gap: 8px; }
.muted { color: var(--ink-muted); }
.center { display: flex; justify-content: center; padding: 48px; }
.frame { width: 100%; height: 100%; border: 1px solid var(--border); border-radius: 12px; background: #fff; }
</style>
