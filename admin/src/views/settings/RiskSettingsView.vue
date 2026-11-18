<script setup lang="ts">
/** Fraud protection: risk scoring switches and the checkout blocklist (emails, email domains, IPs). */
import { onMounted, reactive, ref } from 'vue'
import { NAlert, NButton, NCard, NEmpty, NInput, NSelect, NSpin, NSwitch, NTag, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'
import SettingsNav from '@/components/SettingsNav.vue'

interface Entry { id: number; type: 'email' | 'domain' | 'ip'; value: string; note: string | null; hits: number; created_at: string }

const message = useMessage()
const loading = ref(true)
const saving = ref(false)
const settings = reactive({ enabled: true, hold_high: true })
const blocklist = ref<Entry[]>([])
const stats = reactive({ held: 0, high_30d: 0 })
const form = reactive({ type: 'email' as Entry['type'], value: '', note: '' })
const typeOptions = [
  { label: t('邮箱'), value: 'email' }, { label: t('邮箱域名'), value: 'domain' }, { label: t('IP 地址'), value: 'ip' },
]
const typeLabel: Record<Entry['type'], string> = { email: t('邮箱'), domain: t('邮箱域名'), ip: t('IP 地址') }

function apply(d: { settings: typeof settings; blocklist: Entry[]; held: number; high_30d: number }) {
  Object.assign(settings, d.settings)
  blocklist.value = d.blocklist
  Object.assign(stats, { held: d.held, high_30d: d.high_30d })
}

async function load() {
  try {
    apply((await http.get('/settings/risk')).data)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    apply((await http.put('/settings/risk', settings)).data)
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

async function add() {
  if (!form.value.trim()) return
  try {
    apply((await http.post('/settings/risk/blocklist', form)).data)
    form.value = ''
    form.note = ''
    message.success(t('已加入黑名单'))
  } catch (e) {
    message.error(errorMessage(e))
  }
}

async function remove(e: Entry) {
  try {
    apply((await http.delete(`/settings/risk/blocklist/${e.id}`)).data)
  } catch (err) {
    message.error(errorMessage(err))
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Settings</div>
      <h1 class="page-title">{{ t('设置') }}</h1>
    </div>
    <SettingsNav />

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else>
      <NAlert v-if="stats.held" type="warning" :bordered="false">{{ t('有 {0} 个高风险订单正在等你核实（已暂停发货）。', [stats.held]) }}</NAlert>

      <NCard :title="t('订单风险分析')" size="small">
        <div class="row"><span>{{ t('为每个新订单计算风险分') }}</span><NSwitch v-model:value="settings.enabled" /></div>
        <div class="row"><span>{{ t('高风险订单自动暂停发货，核实后再发') }}</span><NSwitch v-model:value="settings.hold_high" :disabled="!settings.enabled" /></div>
        <p class="muted">{{ t('参考信号：下单 IP 所在国家和收货国家不同、临时邮箱、首单金额异常高、同一 IP 短时间内多次下单或换多个邮箱、大批量购买、以前发生过拒付、Stripe Radar 的判断。30 分以上为中风险，60 分以上为高风险。风险分只是参考，不会自动取消订单。') }}</p>
        <p class="muted">{{ t('近 30 天高风险订单：{0} 个', [stats.high_30d]) }}</p>
        <div class="actions"><NButton type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton></div>
      </NCard>

      <NCard :title="t('下单黑名单')" size="small">
        <p class="muted" style="margin-top: 0">{{ t('黑名单里的邮箱、邮箱域名或 IP 不能在店铺下单，买家只会看到「无法处理订单，请联系店铺」。') }}</p>
        <div class="add">
          <NSelect v-model:value="form.type" :options="typeOptions" style="width: 130px" />
          <NInput v-model:value="form.value" :placeholder="form.type === 'ip' ? '203.0.113.7' : form.type === 'domain' ? 'example.com' : 'name@example.com'" style="flex: 1; min-width: 180px" @keyup.enter="add" />
          <NInput v-model:value="form.note" :placeholder="t('备注（选填）')" style="flex: 1; min-width: 140px" />
          <NButton @click="add">{{ t('添加') }}</NButton>
        </div>
        <div v-for="e in blocklist" :key="e.id" class="entry">
          <NTag size="small" :bordered="false">{{ typeLabel[e.type] }}</NTag>
          <code>{{ e.value }}</code>
          <span class="muted grow">{{ e.note }}</span>
          <span class="muted">{{ t('拦截 {0} 次', [e.hits]) }} · {{ formatDate(e.created_at) }}</span>
          <NButton size="tiny" quaternary type="error" @click="remove(e)">{{ t('移除') }}</NButton>
        </div>
        <NEmpty v-if="!blocklist.length" :description="t('黑名单是空的')" style="padding: 16px 0" />
      </NCard>
    </template>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 6px 0; }
.muted { color: var(--ink-muted); font-size: 12px; line-height: 1.7; }
.actions { display: flex; justify-content: flex-end; }
.add { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.entry { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-top: 1px solid var(--border); flex-wrap: wrap; }
.entry code { font-size: 13px; }
.grow { flex: 1; }
</style>
