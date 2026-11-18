<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NCard, NTag, NSpin, NProgress } from 'naive-ui'
import { http } from '@/api/http'
import { t } from '@/i18n'

interface Flow {
  id: number
  type: string
  label: string
  description: string
  enabled: boolean
  stats: { active: number; completed: number; sent: number; open_rate: number; click_rate: number }
  steps: { id: number; delay_hours: number; subject: string }[]
}

const router = useRouter()
const loading = ref(true)
const flows = ref<Flow[]>([])
const quota = ref({ used: 0, limit: 0, resets_at: '' })

async function load() {
  try {
    const { data } = await http.get('/flows')
    flows.value = data.data
    quota.value = data.quota
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Marketing</div>
      <h1 class="page-title">{{ t('自动化') }}</h1>
      <p class="page-sub">{{ t('设置一次，系统按时自动发送：欢迎新订阅者、感谢首单顾客、召回很久没来的老顾客。只发给订阅了邮件的顾客。') }}</p>
    </div>

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else>
      <div class="flows">
        <NCard v-for="f in flows" :key="f.id" size="small" class="flow" hoverable @click="router.push({ name: 'flow-edit', params: { id: f.id } })">
          <div class="flow-head">
            <strong>{{ f.label }}</strong>
            <NTag size="small" :bordered="false" :type="f.enabled ? 'success' : 'default'">{{ f.enabled ? t('已开启') : t('未开启') }}</NTag>
          </div>
          <p class="desc">{{ f.description }}</p>
          <div class="steps">{{ t('{0} 封邮件', [f.steps.length]) }} · {{ f.steps.map((s) => s.subject).join(' → ') }}</div>
          <div class="stats">
            <div><span>{{ t('进行中') }}</span><b>{{ f.stats.active }}</b></div>
            <div><span>{{ t('已发送') }}</span><b>{{ f.stats.sent }}</b></div>
            <div><span>{{ t('打开率') }}</span><b>{{ f.stats.sent ? `${f.stats.open_rate}%` : '—' }}</b></div>
            <div><span>{{ t('点击率') }}</span><b>{{ f.stats.sent ? `${f.stats.click_rate}%` : '—' }}</b></div>
          </div>
        </NCard>
      </div>

      <NCard size="small">
        <div class="quota-head">
          <span>{{ t('本月营销邮件额度') }}</span>
          <span class="muted">{{ quota.used }} / {{ quota.limit }} · {{ t('{0} 重置', [quota.resets_at]) }}</span>
        </div>
        <NProgress type="line" :percentage="quota.limit ? Math.min(100, Math.round(quota.used / quota.limit * 100)) : 0" :show-indicator="false" :height="6" style="margin-top: 8px" />
        <p class="muted small">{{ t('群发和自动化邮件共用额度，订单相关邮件（确认、发货等）不计入。额度用完后，自动化邮件会等到下个月再发。') }}</p>
      </NCard>
    </template>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.flows { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.flow { cursor: pointer; }
.flow-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.desc { font-size: 13px; color: var(--ink-muted); line-height: 1.6; margin: 8px 0; min-height: 42px; }
.steps { font-size: 12px; color: var(--ink-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
.stats div { display: grid; gap: 2px; }
.stats span { font-size: 11px; color: var(--ink-muted); }
.stats b { font-size: 16px; font-weight: 600; }
.quota-head { display: flex; justify-content: space-between; font-size: 14px; }
.muted { color: var(--ink-muted); }
.small { font-size: 12px; margin: 8px 0 0; line-height: 1.6; }
@media (max-width: 900px) { .flows { grid-template-columns: 1fr; } }
</style>
