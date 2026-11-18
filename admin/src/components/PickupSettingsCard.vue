<script setup lang="ts">
/** Local pickup locations shown as free "Pick up at …" options at checkout. */
import { onMounted, ref } from 'vue'
import { NButton, NCard, NInput, NSwitch, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

interface Location { name: string; address: string; hours: string | null; instructions: string | null }

const message = useMessage()
const enabled = ref(false)
const locations = ref<Location[]>([])
const saving = ref(false)

async function load() {
  const { data } = await http.get('/settings/pickup')
  enabled.value = data.data.enabled
  locations.value = data.data.locations.map((l: Location) => ({ ...l, hours: l.hours ?? '', instructions: l.instructions ?? '' }))
}

function add() {
  locations.value.push({ name: '', address: '', hours: '', instructions: '' })
}

async function save() {
  saving.value = true
  try {
    const { data } = await http.put('/settings/pickup', { enabled: enabled.value, locations: locations.value })
    enabled.value = data.data.enabled
    message.success(t('自提设置已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <NCard :title="t('到店自提')" size="small">
    <template #header-extra><NSwitch v-model:value="enabled" /></template>
    <p class="muted">{{ t('开启后，结账页会多出免运费的「到店自提」选项，买家可以选择到你的门店或仓库取货。') }}</p>
    <div v-for="(l, i) in locations" :key="i" class="loc">
      <div class="row2">
        <NInput v-model:value="l.name" maxlength="80" :placeholder="t('自提点名称，例如：上海门店')" />
        <NInput v-model:value="(l.hours as string)" maxlength="120" :placeholder="t('营业时间（选填）')" />
      </div>
      <NInput v-model:value="l.address" maxlength="200" :placeholder="t('详细地址')" />
      <NInput v-model:value="(l.instructions as string)" maxlength="300" :placeholder="t('取货说明（选填，例如：请带上订单号）')" />
      <div class="actions"><NButton size="tiny" quaternary type="error" @click="locations.splice(i, 1)">{{ t('删除') }}</NButton></div>
    </div>
    <div class="foot">
      <NButton size="small" dashed :disabled="locations.length >= 10" @click="add">{{ t('添加自提点') }}</NButton>
      <NButton size="small" type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton>
    </div>
  </NCard>
</template>

<style scoped>
.muted { color: var(--ink-muted); font-size: 12px; margin: 0 0 10px; }
.loc { display: grid; gap: 8px; padding: 10px 0; border-top: 1px solid var(--border); }
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.actions { display: flex; justify-content: flex-end; }
.foot { display: flex; justify-content: space-between; margin-top: 10px; }
@media (max-width: 640px) { .row2 { grid-template-columns: 1fr; } }
</style>
