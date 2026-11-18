<script setup lang="ts">
/** Approve or decline a charge an app asked for. The buyer is then sent back to the app. */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NAlert, NButton, NCard, NSpin, NTag, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

interface Charge {
  id: number; name: string; price: string; interval: 'every_30_days' | 'one_time'; trial_days: number; test: boolean; trial_available: boolean
  app: { name: string; icon_url: string | null; developer: string | null; tagline: string | null }
}

const route = useRoute()
const router = useRouter()
const message = useMessage()
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const charge = ref<Charge | null>(null)
const token = computed(() => String(route.params.token))

async function load() {
  try {
    charge.value = (await http.get(`/apps/charges/${token.value}`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

async function decide(action: 'approve' | 'decline') {
  busy.value = true
  try {
    const { data } = await http.post(`/apps/charges/${token.value}/${action}`)
    message.success(action === 'approve' ? t('已批准，正在返回应用…') : t('已拒绝，正在返回应用…'))
    window.location.href = data.redirect_url
  } catch (e) {
    message.error(errorMessage(e))
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div v-if="loading" class="center"><NSpin /></div>
    <NAlert v-else-if="error" type="warning" :bordered="false" :title="t('无法打开这笔收费')">
      {{ error }}
      <div style="margin-top: 10px"><NButton size="small" @click="router.push({ name: 'apps' })">{{ t('返回应用') }}</NButton></div>
    </NAlert>
    <NCard v-else-if="charge" size="small">
      <div class="app">
        <div class="icon"><img v-if="charge.app.icon_url" :src="charge.app.icon_url" alt=""><span v-else>{{ charge.app.name.slice(0, 1) }}</span></div>
        <div>
          <div class="eyebrow">{{ t('应用收费') }}</div>
          <h1 class="title">{{ charge.app.name }}</h1>
          <div class="muted">{{ charge.app.developer }}</div>
        </div>
      </div>
      <div class="box">
        <div class="plan">{{ charge.name }} <NTag v-if="charge.test" size="small" :bordered="false" type="warning">{{ t('测试收费，不会扣款') }}</NTag></div>
        <div class="price">US${{ charge.price }} <span class="muted">{{ charge.interval === 'one_time' ? t('一次性') : t('每 30 天') }}</span></div>
        <div v-if="charge.interval !== 'one_time' && charge.trial_days" class="muted">
          {{ charge.trial_available ? t('先免费试用 {0} 天，试用结束后开始收费。', [charge.trial_days]) : t('你之前已经试用过这个应用，批准后立即开始收费。') }}
        </div>
      </div>
      <p class="muted">{{ t('费用会出现在你的 Lumée 平台账单里（和套餐费一起收取）。随时可以在「应用」里卸载应用，卸载后不再续费。') }}</p>
      <div class="actions">
        <NButton :disabled="busy" @click="decide('decline')">{{ t('拒绝') }}</NButton>
        <NButton type="primary" :loading="busy" @click="decide('approve')">{{ t('批准收费') }}</NButton>
      </div>
    </NCard>
  </div>
</template>

<style scoped>
.page { max-width: 560px; margin: 24px auto; display: grid; gap: 16px; }
.center { display: flex; justify-content: center; padding: 48px; }
.app { display: flex; gap: 14px; align-items: center; }
.icon { width: 56px; height: 56px; border-radius: 14px; overflow: hidden; background: var(--brand-50, #EAF7F1); color: var(--brand-700, #0A7350); display: grid; place-items: center; font-weight: 600; font-size: 22px; flex: none; }
.icon img { width: 100%; height: 100%; object-fit: cover; }
.title { margin: 2px 0; font-size: 22px; }
.box { margin: 18px 0 12px; padding: 16px; border: 1px solid var(--border); border-radius: 12px; display: grid; gap: 6px; }
.plan { font-weight: 600; display: flex; gap: 8px; align-items: center; }
.price { font-size: 26px; font-weight: 600; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
</style>
