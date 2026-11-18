<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { NButton, NSpin, NSelect, NAlert } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

/** OAuth consent screen: an app asks to be installed on one of the merchant's stores. */
interface Info {
  app: { name: string; icon_url: string | null; developer: string | null; tagline: string | null }
  scopes: { key: string; label: string; description: string }[]
  store: { id: number; name: string }
  reinstall: boolean
  listed: boolean
}

const route = useRoute()
const auth = useAuth()
const loading = ref(true)
const approving = ref(false)
const info = ref<Info | null>(null)
const error = ref('')
const params = computed(() => Object.fromEntries(Object.entries(route.query).map(([k, v]) => [k, String(Array.isArray(v) ? v[0] : v ?? '')])))
// Only stores this member may manage settings for
const storeOptions = computed(() => auth.stores.filter((s) => s.role === 'owner' || (s.permissions ?? []).includes('settings')).map((s) => ({ value: s.id, label: s.name })))
const storeId = ref<number | null>(auth.currentStore?.id ?? null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    info.value = (await http.get('/oauth/authorize', { params: params.value })).data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

function switchStore(id: number) {
  auth.switchStore(id)
  load()
}

async function approve() {
  approving.value = true
  try {
    const { data } = await http.post('/oauth/authorize', params.value)
    window.location.href = data.redirect_url
  } catch (e) {
    error.value = errorMessage(e)
    approving.value = false
  }
}

/** Tell the app the merchant said no (RFC 6749 §4.1.2.1) — only to a redirect URI the API accepted. */
function deny() {
  const redirect = params.value.redirect_uri
  if (info.value && redirect) {
    const q = new URLSearchParams({ error: 'access_denied', ...(params.value.state ? { state: params.value.state } : {}) })
    window.location.href = `${redirect}${redirect.includes('?') ? '&' : '?'}${q}`
  } else {
    window.location.href = '/'
  }
}

onMounted(load)
</script>

<template>
  <div class="wrap">
    <div class="card">
      <div class="brand"><img src="/platform-mark.svg" alt="" /> Lumée</div>
      <div v-if="loading" class="center"><NSpin /></div>
      <template v-else-if="info">
        <div class="app">
          <div class="icon"><img v-if="info.app.icon_url" :src="info.app.icon_url" alt=""><span v-else>{{ info.app.name.slice(0, 1) }}</span></div>
          <div>
            <h1>{{ t('安装「{0}」', [info.app.name]) }}</h1>
            <div class="muted">{{ t('开发者：{0}', [info.app.developer ?? '—']) }}</div>
          </div>
        </div>
        <NAlert v-if="!info.listed" type="warning" :bordered="false" style="margin-bottom: 14px">{{ t('这个应用还没有上架，只有开发者本人可以安装测试。') }}</NAlert>
        <div class="label">{{ t('安装到店铺') }}</div>
        <NSelect v-model:value="storeId" :options="storeOptions" @update:value="switchStore" />
        <div class="label" style="margin-top: 16px">{{ t('应用将获得以下权限：') }}</div>
        <ul class="scopes">
          <li v-for="s in info.scopes" :key="s.key"><strong>{{ s.label }}</strong><span>{{ s.description }}</span></li>
        </ul>
        <p class="muted small">{{ t('你可以随时在「应用」页面卸载，卸载后应用立即失去访问权限。') }}</p>
        <NAlert v-if="error" type="error" :bordered="false" style="margin-bottom: 12px">{{ error }}</NAlert>
        <div class="actions">
          <NButton @click="deny">{{ t('取消') }}</NButton>
          <NButton type="primary" :loading="approving" @click="approve">{{ info.reinstall ? t('重新授权') : t('授权并安装') }}</NButton>
        </div>
      </template>
      <template v-else>
        <h1>{{ t('无法安装应用') }}</h1>
        <p class="muted">{{ error }}</p>
        <div class="actions"><NButton @click="$router.push('/')">{{ t('返回后台') }}</NButton></div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.wrap { min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--surface-muted, #F7F9F8); }
.card { width: 100%; max-width: 460px; background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 28px; }
.brand { display: flex; align-items: center; gap: 8px; font-weight: 600; margin-bottom: 22px; }
.brand img { width: 26px; height: 26px; }
.center { display: flex; justify-content: center; padding: 32px; }
.app { display: flex; gap: 14px; align-items: center; margin-bottom: 18px; }
.app h1, h1 { font-size: 20px; margin: 0 0 4px; }
.icon { width: 52px; height: 52px; border-radius: 12px; overflow: hidden; background: var(--brand-50, #EAF7F1); color: var(--brand-700, #0A7350); display: grid; place-items: center; font-weight: 600; font-size: 20px; flex: none; }
.icon img { width: 100%; height: 100%; object-fit: cover; }
.label { font-size: 13px; margin-bottom: 6px; }
.scopes { list-style: none; padding: 0; margin: 0 0 12px; display: grid; gap: 8px; }
.scopes li { display: grid; gap: 2px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; font-size: 14px; }
.scopes span { color: var(--ink-muted); font-size: 12px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; }
.small { font-size: 12px; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
</style>
