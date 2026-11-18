<script setup lang="ts">
/** Developers: publish themes to the marketplace (upload an exported theme package, then submit for review). */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NAlert, NButton, NCard, NEmpty, NInput, NModal, NSelect, NSpin, NTag, useDialog, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Listing {
  id: number; name: string; tagline: string | null; description: string | null; preview_url: string | null; demo_url: string | null
  template: string; version: number; has_package: boolean; swatches: string[]; status: 'draft' | 'review' | 'listed' | 'rejected'
  review_note: string | null; installs: number; updated_at: string; price: string; sales?: number; earnings?: string
}

const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const rows = ref<Listing[]>([])
const editOpen = ref(false)
const saving = ref(false)
const form = reactive({ id: null as number | null, name: '', tagline: '', description: '', preview_url: '', demo_url: '', price: 0 })
const prices = ref<number[]>([0])
const priceOptions = computed(() => prices.value.map((p) => ({ value: p, label: p ? `US$${p}` : t('免费') })))
const uploading = ref<number | null>(null)
const statusMeta = {
  draft: { label: t('草稿'), type: 'default' }, review: { label: t('审核中'), type: 'info' },
  listed: { label: t('已上架'), type: 'success' }, rejected: { label: t('未通过'), type: 'error' },
} as const

async function load() {
  try {
    const { data } = await http.get('/partner/themes')
    rows.value = data.data
    prices.value = data.prices ?? [0]
  } finally {
    loading.value = false
  }
}

function edit(l?: Listing) {
  Object.assign(form, l
    ? { id: l.id, name: l.name, tagline: l.tagline ?? '', description: l.description ?? '', preview_url: l.preview_url ?? '', demo_url: l.demo_url ?? '', price: Number(l.price) }
    : { id: null, name: '', tagline: '', description: '', preview_url: '', demo_url: '', price: 0 })
  editOpen.value = true
}

async function save() {
  saving.value = true
  const body = { name: form.name, tagline: form.tagline || null, description: form.description || null, preview_url: form.preview_url || null, demo_url: form.demo_url || null, price: form.price }
  try {
    if (form.id) await http.put(`/partner/themes/${form.id}`, body)
    else await http.post('/partner/themes', body)
    editOpen.value = false
    message.success(t('已保存'))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

async function upload(l: Listing, ev: Event) {
  const file = (ev.target as HTMLInputElement).files?.[0]
  ;(ev.target as HTMLInputElement).value = ''
  if (!file) return
  uploading.value = l.id
  try {
    const fd = new FormData()
    fd.append('file', file)
    await http.post(`/partner/themes/${l.id}/package`, fd)
    message.success(l.status === 'listed' ? t('新版本已上传，重新审核通过后对商家生效') : t('主题包已上传'))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    uploading.value = null
  }
}

async function submit(l: Listing) {
  try {
    await http.post(`/partner/themes/${l.id}/submit`)
    message.success(t('已提交审核'))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  }
}

function remove(l: Listing) {
  dialog.warning({
    title: t('删除「{0}」？', [l.name]), content: t('主题会从市场下架。已经安装的店铺不受影响。'),
    positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.delete(`/partner/themes/${l.id}`)
      await load()
    },
  })
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Partners</div>
        <h1 class="page-title">{{ t('我的主题（开发者）') }}</h1>
        <p class="page-sub">{{ t('把你设计的主题发布到主题市场。在任意店铺的主题库里「导出」主题包，上传到这里，审核通过后所有商家都能一键安装。') }}</p>
      </div>
      <div class="btns">
        <NButton quaternary @click="router.push({ name: 'partner-apps' })">{{ t('我的应用') }}</NButton>
        <NButton quaternary @click="router.push({ name: 'partner-payouts' })">{{ t('收入与结算') }}</NButton>
        <NButton type="primary" @click="edit()">{{ t('新建主题') }}</NButton>
      </div>
    </div>

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else-if="rows.length">
      <NCard v-for="l in rows" :key="l.id" size="small">
        <div class="body">
          <div class="shot">
            <img v-if="l.preview_url" :src="l.preview_url" alt="" />
            <div v-else class="sw"><span v-for="c in l.swatches" :key="c" :style="{ background: c }" /></div>
          </div>
          <div class="info">
            <div class="row-head">
              <div>
                <strong>{{ l.name }}</strong> <NTag size="small" :bordered="false" :type="statusMeta[l.status].type">{{ statusMeta[l.status].label }}</NTag>
                <div class="muted">
                  <template v-if="l.has_package">{{ t('版本 {0}', [l.version]) }} · {{ t('基于 {0} 模板', [l.template]) }} · </template>
                  {{ t('{0} 家店铺安装', [l.installs]) }} · {{ formatDate(l.updated_at) }}
                  · {{ Number(l.price) ? `US$${l.price}` : t('免费') }}<template v-if="l.sales"> · {{ t('售出 {0} 份，收入 US${1}', [l.sales, l.earnings]) }}</template>
                </div>
              </div>
              <div class="btns">
                <label class="upload">
                  <input type="file" accept=".zip" class="sr" @change="upload(l, $event)" />
                  <NButton size="small" :loading="uploading === l.id" tag="span">{{ l.has_package ? t('上传新版本') : t('上传主题包') }}</NButton>
                </label>
                <NButton v-if="l.status === 'draft' || l.status === 'rejected'" size="small" type="primary" @click="submit(l)">{{ t('提交审核') }}</NButton>
                <NButton size="small" quaternary @click="edit(l)">{{ t('编辑') }}</NButton>
                <NButton size="small" quaternary type="error" @click="remove(l)">{{ t('删除') }}</NButton>
              </div>
            </div>
            <p v-if="l.tagline" class="desc">{{ l.tagline }}</p>
            <NAlert v-if="l.status === 'rejected' && l.review_note" type="error" :bordered="false" style="margin-top: 8px">{{ t('审核意见：{0}', [l.review_note]) }}</NAlert>
          </div>
        </div>
      </NCard>
    </template>
    <NEmpty v-else :description="t('还没有主题')" style="padding: 32px 0" />

    <NModal v-model:show="editOpen" preset="card" :title="form.id ? t('编辑主题') : t('新建主题')" style="max-width: 560px">
      <div class="form">
        <label>{{ t('主题名称') }}<NInput v-model:value="form.name" maxlength="80" /></label>
        <label>{{ t('一句话介绍') }}<NInput v-model:value="form.tagline" maxlength="160" /></label>
        <label>{{ t('详细介绍') }}<NInput v-model:value="form.description" type="textarea" :autosize="{ minRows: 3, maxRows: 8 }" maxlength="5000" /></label>
        <label>{{ t('截图地址（https）') }}<NInput v-model:value="form.preview_url" placeholder="https://…/preview.png" /></label>
        <label>{{ t('演示店铺地址（选填）') }}<NInput v-model:value="form.demo_url" placeholder="https://…" /></label>
        <label>{{ t('价格（一次性，商家购买后可永久使用）') }}<NSelect v-model:value="form.price" :options="priceOptions" /></label>
        <p class="hint">{{ t('你获得售价的 80%，平台收取 20%。') }}</p>
      </div>
      <template #footer>
        <div class="foot"><NButton @click="editOpen = false">{{ t('取消') }}</NButton><NButton type="primary" :loading="saving" :disabled="!form.name.trim()" @click="save">{{ t('保存') }}</NButton></div>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; max-width: 640px; }
.center { display: flex; justify-content: center; padding: 48px; }
.btns { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.body { display: flex; gap: 14px; }
.shot { width: 160px; aspect-ratio: 16 / 10; border-radius: 8px; overflow: hidden; border: 1px solid var(--border); flex: none; background: var(--surface-muted); }
.shot img { width: 100%; height: 100%; object-fit: cover; }
.sw { display: grid; grid-template-columns: repeat(4, 1fr); height: 100%; }
.info { flex: 1; min-width: 0; }
.row-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.muted { color: var(--ink-muted); font-size: 12px; line-height: 1.7; }
.desc { font-size: 13px; margin: 6px 0 0; }
.upload { cursor: pointer; }
.sr { position: absolute; width: 1px; height: 1px; opacity: 0; }
.form { display: grid; gap: 12px; }
.form label { display: grid; gap: 4px; font-size: 13px; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
@media (max-width: 640px) { .body { flex-direction: column; } .shot { width: 100%; } }
.hint { margin: -6px 0 0; font-size: 12px; color: var(--ink-muted); }
</style>
