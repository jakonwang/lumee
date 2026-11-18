<script setup lang="ts">
/** Ops: review marketplace themes (download the package, then list or reject). */
import { onMounted, ref, watch } from 'vue'
import { NCard, NButton, NTag, NTabs, NTab, NEmpty, NSpin, NInput, NModal, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Row {
  id: number; name: string; slug: string; tagline: string | null; description: string | null; preview_url: string | null; demo_url: string | null
  template: string; version: number; has_package: boolean; swatches: string[]; status: string; review_note: string | null
  installs: number; updated_at: string; developer: { name: string; email: string } | null
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
    rows.value = (await http.get('/ops/themes', { params: { status: tab.value === 'all' ? undefined : tab.value } })).data.data
  } finally {
    loading.value = false
  }
}

async function decide(r: Row, decision: 'listed' | 'rejected' | 'draft', reason?: string) {
  try {
    await http.post(`/ops/themes/${r.id}/review`, { decision, note: reason })
    message.success(t('已更新'))
    rejecting.value = null
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  }
}

async function download(r: Row) {
  try {
    const res = await http.get(`/ops/themes/${r.id}/package`, { responseType: 'blob' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(res.data)
    a.download = `${r.slug}-v${r.version}.zip`
    a.click()
    URL.revokeObjectURL(a.href)
  } catch (e) {
    message.error(errorMessage(e))
  }
}

watch(tab, load)
onMounted(load)
</script>

<template>
  <div class="page">
    <h1 class="page-title">{{ t('主题审核') }}</h1>
    <NTabs v-model:value="tab" type="line" size="small">
      <NTab name="review">{{ t('待审核') }}</NTab>
      <NTab name="listed">{{ t('已上架') }}</NTab>
      <NTab name="rejected">{{ t('未通过') }}</NTab>
      <NTab name="all">{{ t('全部') }}</NTab>
    </NTabs>
    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else-if="rows.length">
      <NCard v-for="r in rows" :key="r.id" size="small">
        <div class="body">
          <img v-if="r.preview_url" :src="r.preview_url" class="shot" alt="" />
          <div class="info">
            <div class="head">
              <div>
                <strong>{{ r.name }}</strong> <NTag size="small" :bordered="false">{{ r.status }}</NTag> <span class="muted">v{{ r.version }}</span>
                <div class="muted">{{ r.developer?.name }} · {{ r.developer?.email }} · {{ t('基于 {0} 模板', [r.template]) }} · {{ t('{0} 家店铺安装', [r.installs]) }} · {{ formatDate(r.updated_at) }}</div>
              </div>
              <div class="btns">
                <NButton v-if="r.has_package" size="small" quaternary @click="download(r)">{{ t('下载主题包') }}</NButton>
                <NButton v-if="r.status !== 'listed'" size="small" type="primary" :disabled="!r.has_package" @click="decide(r, 'listed')">{{ t('通过并上架') }}</NButton>
                <NButton v-if="r.status !== 'rejected'" size="small" @click="rejecting = r; note = ''">{{ t('拒绝') }}</NButton>
                <NButton v-if="r.status === 'listed'" size="small" quaternary @click="decide(r, 'draft')">{{ t('下架') }}</NButton>
              </div>
            </div>
            <p v-if="r.tagline" class="desc"><b>{{ r.tagline }}</b></p>
            <p v-if="r.description" class="desc">{{ r.description }}</p>
            <a v-if="r.demo_url" :href="r.demo_url" target="_blank" rel="noopener" class="muted">{{ r.demo_url }}</a>
          </div>
        </div>
      </NCard>
    </template>
    <NEmpty v-else :description="t('没有主题')" style="padding: 32px 0" />

    <NModal :show="!!rejecting" preset="card" :title="t('拒绝原因')" style="max-width: 460px" @update:show="(v: boolean) => { if (!v) rejecting = null }">
      <NInput v-model:value="note" type="textarea" maxlength="300" :placeholder="t('开发者会看到这段说明')" />
      <template #footer><div class="foot"><NButton type="primary" @click="rejecting && decide(rejecting, 'rejected', note)">{{ t('确定拒绝') }}</NButton></div></template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 12px; max-width: 960px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 40px; }
.body { display: flex; gap: 14px; }
.shot { width: 180px; aspect-ratio: 16 / 10; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); flex: none; }
.info { flex: 1; min-width: 0; }
.head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.btns { display: flex; gap: 6px; flex-wrap: wrap; }
.muted { color: var(--ink-muted); font-size: 12px; line-height: 1.7; }
.desc { font-size: 13px; line-height: 1.6; margin: 8px 0 4px; white-space: pre-line; }
.foot { display: flex; justify-content: flex-end; }
@media (max-width: 640px) { .body { flex-direction: column; } .shot { width: 100%; } }
</style>
