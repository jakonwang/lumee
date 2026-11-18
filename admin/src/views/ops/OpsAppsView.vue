<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { NCard, NButton, NTag, NTabs, NTab, NEmpty, NSpin, NInput, NModal, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Row {
  id: number; name: string; tagline: string | null; description: string | null; icon_url: string | null; app_url: string; redirect_uris: string[]
  scopes: string[]; status: string; review_note: string | null; developer: { name: string; email: string } | null; installs: number; updated_at: string
}

const message = useMessage()
const tab = ref('review')
const rows = ref<Row[]>([])
const loading = ref(true)
const rejecting = ref<Row | null>(null)
const note = ref('')

async function load() {
  loading.value = true
  try {
    rows.value = (await http.get('/ops/apps', { params: { status: tab.value === 'all' ? undefined : tab.value } })).data.data
  } finally {
    loading.value = false
  }
}

async function decide(r: Row, decision: 'listed' | 'rejected' | 'draft', reason?: string) {
  try {
    await http.post(`/ops/apps/${r.id}/review`, { decision, note: reason })
    message.success(t('已更新'))
    rejecting.value = null
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  }
}

watch(tab, load)
onMounted(load)
</script>

<template>
  <div class="page">
    <h1 class="page-title">{{ t('应用审核') }}</h1>
    <NTabs v-model:value="tab" type="line" size="small">
      <NTab name="review">{{ t('待审核') }}</NTab>
      <NTab name="listed">{{ t('已上架') }}</NTab>
      <NTab name="rejected">{{ t('未通过') }}</NTab>
      <NTab name="all">{{ t('全部') }}</NTab>
    </NTabs>
    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else-if="rows.length">
      <NCard v-for="r in rows" :key="r.id" size="small">
        <div class="head">
          <div>
            <strong>{{ r.name }}</strong> <NTag size="small" :bordered="false">{{ r.status }}</NTag>
            <div class="muted">{{ r.developer?.name }} · {{ r.developer?.email }} · {{ t('{0} 家店铺在用', [r.installs]) }} · {{ formatDate(r.updated_at) }}</div>
          </div>
          <div class="btns">
            <NButton v-if="r.status !== 'listed'" size="small" type="primary" @click="decide(r, 'listed')">{{ t('通过并上架') }}</NButton>
            <NButton v-if="r.status !== 'rejected'" size="small" @click="rejecting = r; note = ''">{{ t('拒绝') }}</NButton>
            <NButton v-if="r.status === 'listed'" size="small" quaternary @click="decide(r, 'draft')">{{ t('下架') }}</NButton>
          </div>
        </div>
        <p v-if="r.tagline" class="desc"><b>{{ r.tagline }}</b></p>
        <p v-if="r.description" class="desc">{{ r.description }}</p>
        <div class="muted">{{ t('应用地址') }}：{{ r.app_url }} · {{ t('回调') }}：{{ r.redirect_uris.join(', ') }}</div>
        <div class="muted">{{ t('权限') }}：{{ r.scopes.join(', ') }}</div>
      </NCard>
    </template>
    <NEmpty v-else :description="t('没有应用')" style="padding: 32px 0" />

    <NModal :show="!!rejecting" preset="card" :title="t('拒绝原因')" style="max-width: 460px" @update:show="(v: boolean) => { if (!v) rejecting = null }">
      <NInput v-model:value="note" type="textarea" maxlength="300" :placeholder="t('开发者会看到这段说明')" />
      <template #footer><div class="foot"><NButton type="primary" @click="rejecting && decide(rejecting, 'rejected', note)">{{ t('确定拒绝') }}</NButton></div></template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 12px; max-width: 900px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 40px; }
.head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.btns { display: flex; gap: 6px; }
.muted { color: var(--ink-muted); font-size: 12px; line-height: 1.7; }
.desc { font-size: 13px; line-height: 1.6; margin: 8px 0 4px; white-space: pre-line; }
.foot { display: flex; justify-content: flex-end; }
</style>
