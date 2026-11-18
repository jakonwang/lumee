<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { NCard, NInput, NButton, NIcon, NSkeleton, NSelect, NDatePicker, NModal, NRadioGroup, NRadio, NAlert, useMessage, useDialog } from 'naive-ui'
import { ArrowBackOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import ProductPicker from '@/components/ProductPicker.vue'
import type { CollectionProduct } from '@/api/collections'
import { t } from '@/i18n'

interface Picked { id: number; title: string; featured_image: string | null }
interface Audience { key: string; label: string; count: number }
interface Stats { status: string; scheduled_at: string | null; sent_at: string | null; recipients_count: number; sent_count: number; failed_count: number; opened_count: number; clicked_count: number; unsubscribed_count: number; open_rate: number; click_rate: number }

const route = useRoute()
const router = useRouter()
const message = useMessage()
const dialog = useDialog()

const id = computed(() => (route.params.id ? Number(route.params.id) : null))
const loading = ref(false)
const saving = ref(false)
const dirty = ref(false)
const pickerOpen = ref(false)
const previewHtml = ref('')
const audiences = ref<Audience[]>([])
const products = ref<Picked[]>([])
const stats = ref<Stats | null>(null)
const sendOpen = ref(false)
const sendMode = ref<'now' | 'later'>('now')
const sendAt = ref<number | null>(null)
const sending = ref(false)
const testing = ref(false)

const form = reactive({ subject: '', preheader: '', heading: '', body: '', button_label: '', button_url: '', audience: 'subscribers' })
const editable = computed(() => !stats.value || stats.value.status === 'draft' || stats.value.status === 'scheduled')
const audienceOptions = computed(() => audiences.value.map((a) => ({ value: a.key, label: t('{0}（{1} 人）', [a.label, a.count]) })))
const audienceCount = computed(() => audiences.value.find((a) => a.key === form.audience)?.count ?? 0)

async function load() {
  audiences.value = (await http.get('/campaigns')).data.audiences
  if (!id.value) return
  loading.value = true
  try {
    const c = (await http.get(`/campaigns/${id.value}`)).data.data
    Object.assign(form, {
      subject: c.subject, preheader: c.preheader ?? '', heading: c.heading ?? '', body: c.body, button_label: c.button_label ?? '',
      button_url: c.button_url ?? '', audience: c.audience,
    })
    products.value = c.products
    stats.value = c
    await refreshPreview()
  } finally {
    loading.value = false
    setTimeout(() => { dirty.value = false })
  }
}

async function refreshPreview() {
  if (!id.value) return
  previewHtml.value = (await http.get(`/campaigns/${id.value}/preview`, { responseType: 'text' })).data
}

watch([form, products], () => { dirty.value = true }, { deep: true })

function addProducts(picked: CollectionProduct[]) {
  const have = new Set(products.value.map((p) => p.id))
  products.value.push(...picked.filter((p) => !have.has(p.id)).map((p) => ({ id: p.id, title: p.title, featured_image: p.featured_image })))
  if (products.value.length > 4) {
    products.value = products.value.slice(0, 4)
    message.info(t('最多放 4 件商品'))
  }
}

async function save(quiet = false): Promise<boolean> {
  if (!form.subject.trim()) {
    message.error(t('请填写邮件主题'))
    return false
  }
  saving.value = true
  try {
    const body = { ...form, product_ids: products.value.map((p) => p.id) }
    const { data } = id.value ? await http.put(`/campaigns/${id.value}`, body) : await http.post('/campaigns', body)
    stats.value = data.data
    setTimeout(() => { dirty.value = false })
    if (!quiet) message.success(t('已保存'))
    if (!id.value) {
      await router.replace({ name: 'campaign-edit', params: { id: data.data.id } })
    }
    await refreshPreview()
    return true
  } catch (e) {
    message.error(errorMessage(e))
    return false
  } finally {
    saving.value = false
  }
}

async function sendTest() {
  if (dirty.value && !(await save(true))) return
  testing.value = true
  try {
    message.success((await http.post(`/campaigns/${id.value}/test`)).data.message)
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    testing.value = false
  }
}

async function confirmSend() {
  if (dirty.value && !(await save(true))) return
  if (sendMode.value === 'later' && (!sendAt.value || sendAt.value <= Date.now())) {
    message.error(t('请选择一个以后的时间'))
    return
  }
  sending.value = true
  try {
    const { data } = await http.post(`/campaigns/${id.value}/send`, { scheduled_at: sendMode.value === 'later' ? new Date(sendAt.value!).toISOString() : null })
    stats.value = data.data
    sendOpen.value = false
    message.success(data.data.status === 'scheduled' ? t('已定时，将于 {0} 发送', [formatDate(data.data.scheduled_at)]) : t('已开始发送'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    sending.value = false
  }
}

async function unschedule() {
  stats.value = (await http.post(`/campaigns/${id.value}/unschedule`)).data.data
  message.success(t('已取消定时，回到草稿'))
}

function remove() {
  dialog.warning({
    title: t('删除这封邮件？'), positiveText: t('删除'), negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.delete(`/campaigns/${id.value}`)
      dirty.value = false
      router.push({ name: 'campaigns' })
    },
  })
}

onBeforeRouteLeave(() => (dirty.value && editable.value ? window.confirm(t('有未保存的修改，确定离开吗？')) : true))
onMounted(load)
</script>

<template>
  <div class="editor">
    <NButton text class="back" @click="router.push({ name: 'campaigns' })">
      <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
      {{ t('邮件营销') }}
    </NButton>

    <NSkeleton v-if="loading" text :repeat="6" />
    <template v-else>
      <div class="head">
        <h1 class="page-title">{{ form.subject || t('写邮件') }}</h1>
        <div class="actions">
          <template v-if="editable">
            <NButton v-if="id" quaternary @click="remove">{{ t('删除') }}</NButton>
            <NButton :loading="saving" :disabled="id !== null && !dirty" @click="save()">{{ t('保存草稿') }}</NButton>
            <NButton v-if="id" :loading="testing" @click="sendTest">{{ t('发测试邮件给我') }}</NButton>
            <NButton v-if="stats?.status === 'scheduled'" @click="unschedule">{{ t('取消定时') }}</NButton>
            <NButton v-else-if="id" type="primary" @click="sendOpen = true">{{ t('发送') }}</NButton>
          </template>
        </div>
      </div>

      <NAlert v-if="stats?.status === 'scheduled'" type="info" :bordered="false" style="margin-bottom: 16px">
        {{ t('将于 {0} 自动发送。发送前仍可修改。', [formatDate(stats.scheduled_at)]) }}
      </NAlert>
      <div v-if="stats && (stats.status === 'sent' || stats.status === 'sending')" class="stats">
        <div><span>{{ t('送达') }}</span><strong>{{ stats.sent_count }}</strong><small v-if="stats.failed_count">{{ t('{0} 封未送达', [stats.failed_count]) }}</small></div>
        <div><span>{{ t('打开') }}</span><strong>{{ stats.open_rate }}%</strong><small>{{ t('{0} 人', [stats.opened_count]) }}</small></div>
        <div><span>{{ t('点击') }}</span><strong>{{ stats.click_rate }}%</strong><small>{{ t('{0} 人', [stats.clicked_count]) }}</small></div>
        <div><span>{{ t('退订') }}</span><strong>{{ stats.unsubscribed_count }}</strong><small>{{ stats.sent_at ? formatDate(stats.sent_at) : t('发送中…') }}</small></div>
      </div>

      <div class="grid">
        <div class="main">
          <NCard size="small">
            <div class="label">{{ t('收件人') }}</div>
            <NSelect v-model:value="form.audience" :options="audienceOptions" :disabled="!editable" />
            <div class="label" style="margin-top: 14px">{{ t('邮件主题') }}</div>
            <NInput v-model:value="form.subject" :disabled="!editable" maxlength="150" :placeholder="t('例如：New arrivals are here ✨')" />
            <div class="label" style="margin-top: 14px">{{ t('预览文字（收件箱里主题后面的那行灰字，选填）') }}</div>
            <NInput v-model:value="form.preheader" :disabled="!editable" maxlength="150" />
          </NCard>

          <NCard :title="t('内容')" size="small">
            <div class="label">{{ t('大标题') }}</div>
            <NInput v-model:value="form.heading" :disabled="!editable" maxlength="150" />
            <div class="label" style="margin-top: 14px">{{ t('正文（Markdown）') }}</div>
            <NInput v-model:value="form.body" :disabled="!editable" type="textarea" :autosize="{ minRows: 8, maxRows: 24 }" maxlength="50000" class="md"
              :placeholder="t('## 小标题\n\n正文段落……\n\n- 列表项\n- **加粗** [链接](https://example.com)')" />
            <div class="row2">
              <div>
                <div class="label">{{ t('按钮文字') }}</div>
                <NInput v-model:value="form.button_label" :disabled="!editable" maxlength="60" placeholder="Shop now" />
              </div>
              <div>
                <div class="label">{{ t('按钮链接') }}</div>
                <NInput v-model:value="form.button_url" :disabled="!editable" maxlength="500" placeholder="/collections/all" />
              </div>
            </div>
          </NCard>

          <NCard :title="t('推荐商品（最多 4 件）')" size="small">
            <template v-if="editable" #header-extra><NButton size="small" :disabled="products.length >= 4" @click="pickerOpen = true">+ {{ t('添加商品') }}</NButton></template>
            <div v-if="products.length" class="plist">
              <div v-for="(p, i) in products" :key="p.id" class="prow">
                <img :src="p.featured_image || '/placeholder.svg'" alt="" :class="{ ph: !p.featured_image }">
                <span class="pname">{{ p.title }}</span>
                <NButton v-if="editable" size="tiny" quaternary @click="products.splice(i, 1)">{{ t('移除') }}</NButton>
              </div>
            </div>
            <p v-else class="hint">{{ t('会显示商品图片、名称和价格（含限时折扣价），点击进入商品页。') }}</p>
          </NCard>
        </div>

        <div class="side">
          <NCard :title="t('预览')" size="small" content-style="padding: 0">
            <iframe v-if="previewHtml" class="preview" :srcdoc="previewHtml" sandbox="" title="preview" />
            <p v-else class="hint pad">{{ t('保存草稿后显示预览') }}</p>
          </NCard>
        </div>
      </div>
    </template>

    <NModal v-model:show="sendOpen" preset="card" :title="t('发送邮件')" style="max-width: 460px">
      <p class="confirm">{{ t('将发给 {0} 位订阅者。发送后不能撤回或修改。', [audienceCount]) }}</p>
      <NRadioGroup v-model:value="sendMode">
        <div class="radios">
          <NRadio value="now">{{ t('立即发送') }}</NRadio>
          <NRadio value="later">{{ t('定时发送') }}</NRadio>
        </div>
      </NRadioGroup>
      <NDatePicker v-if="sendMode === 'later'" v-model:value="sendAt" type="datetime" style="width: 100%; margin-top: 12px" />
      <template #footer>
        <div class="foot">
          <NButton @click="sendOpen = false">{{ t('取消') }}</NButton>
          <NButton type="primary" :loading="sending" :disabled="!audienceCount" @click="confirmSend">{{ sendMode === 'now' ? t('立即发送') : t('定时发送') }}</NButton>
        </div>
      </template>
    </NModal>

    <ProductPicker v-model:show="pickerOpen" :exclude="products.map((p) => p.id)" endpoint="/promotions/products" @pick="addProducts" />
  </div>
</template>

<style scoped>
.editor { container-type: inline-size; }
.back { margin-bottom: 12px; color: var(--ink-muted); }
.head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.head .page-title { margin: 0; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; }
.main, .side { display: grid; gap: 16px; min-width: 0; }
.side { position: sticky; top: 0; }
.label { font-size: 13px; margin-bottom: 6px; }
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 14px; }
.md :deep(textarea) { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; line-height: 1.6; }
.hint { font-size: 12px; color: var(--ink-muted); margin: 0; line-height: 1.6; }
.hint.pad { padding: 16px; }
.plist { display: grid; border: 1px solid var(--border); border-radius: 10px; }
.prow { display: flex; align-items: center; gap: 10px; padding: 8px 12px; }
.prow + .prow { border-top: 1px solid var(--border); }
.prow img { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; background: var(--surface-muted); }
.prow img.ph { object-fit: contain; padding: 6px; opacity: .5; }
.pname { flex: 1; font-size: 14px; }
.preview { width: 100%; height: 640px; border: 0; display: block; border-radius: 0 0 12px 12px; }
.stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.stats > div { background: var(--surface, #fff); border: 1px solid var(--border); border-radius: 12px; padding: 12px 14px; display: grid; gap: 2px; }
.stats span { font-size: 12px; color: var(--ink-muted); }
.stats strong { font-size: 22px; }
.stats small { font-size: 12px; color: var(--ink-muted); }
.confirm { margin: 0 0 14px; line-height: 1.6; }
.radios { display: flex; gap: 16px; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
@container (max-width: 900px) { .grid { grid-template-columns: 1fr; } .side { position: static; } .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
