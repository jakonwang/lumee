<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { NCard, NInput, NButton, NSwitch, NIcon, NSkeleton, NTabs, NTab, NDynamicTags, NDatePicker, useMessage, useDialog } from 'naive-ui'
import { ArrowBackOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import SeoCard from '@/components/SeoCard.vue'
import { t } from '@/i18n'

const route = useRoute()
const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const auth = useAuth()

const id = computed(() => (route.params.id ? Number(route.params.id) : null))
const loading = ref(false)
const saving = ref(false)
const dirty = ref(false)
const uploading = ref(false)
const tab = ref<'write' | 'preview'>('write')
const previewHtml = ref('')
const previewing = ref(false)
const form = reactive({
  title: '', handle: '', excerpt: '', body: '', cover_image: null as string | null, author: '',
  tags: [] as string[], published: false, published_at: null as number | null,
})
const seo = ref<{ title: string | null; description: string | null }>({ title: null, description: null })
const scheduled = computed(() => form.published && form.published_at !== null && form.published_at > Date.now())

async function load() {
  if (!id.value) return
  loading.value = true
  try {
    const p = (await http.get(`/blog-posts/${id.value}`)).data.data
    Object.assign(form, {
      title: p.title, handle: p.handle, excerpt: p.excerpt, body: p.body, cover_image: p.cover_image, author: p.author ?? '',
      tags: p.tags ?? [], published: p.published, published_at: p.published_at ? Date.parse(p.published_at) : null,
    })
    seo.value = { title: p.seo?.title ?? null, description: p.seo?.description ?? null }
  } finally {
    loading.value = false
    setTimeout(() => { dirty.value = false })
  }
}

watch([form, seo], () => { dirty.value = true }, { deep: true })

watch(tab, async (it) => {
  if (it !== 'preview') return
  previewing.value = true
  try {
    previewHtml.value = (await http.post('/pages/preview', { body: form.body })).data.html
  } finally {
    previewing.value = false
  }
})

async function onCover(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  ;(e.target as HTMLInputElement).value = ''
  if (!file) return
  uploading.value = true
  try {
    const body = new FormData()
    body.append('file', file)
    form.cover_image = (await http.post('/uploads/media', body)).data.data.url
  } catch (err) {
    message.error(errorMessage(err))
  } finally {
    uploading.value = false
  }
}

async function save() {
  if (!form.title.trim()) {
    message.error(t('请填写文章标题'))
    return
  }
  saving.value = true
  try {
    const body = {
      ...form,
      handle: form.handle || undefined,
      author: form.author || null,
      published_at: form.published_at ? new Date(form.published_at).toISOString() : null,
      seo: seo.value,
    }
    const { data } = id.value ? await http.put(`/blog-posts/${id.value}`, body) : await http.post('/blog-posts', body)
    form.handle = data.data.handle
    form.published_at = data.data.published_at ? Date.parse(data.data.published_at) : null
    setTimeout(() => { dirty.value = false })
    message.success(t('已保存'))
    if (!id.value) router.replace({ name: 'post-edit', params: { id: data.data.id } })
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

function remove() {
  dialog.warning({
    title: t('删除文章「{0}」？', [form.title]),
    content: t('删除后无法恢复。'),
    positiveText: t('删除'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.delete(`/blog-posts/${id.value}`)
      dirty.value = false
      message.success(t('已删除'))
      router.push({ name: 'blog' })
    },
  })
}

onBeforeRouteLeave(() => (dirty.value ? window.confirm(t('有未保存的修改，确定离开吗？')) : true))
onMounted(load)
</script>

<template>
  <div class="editor">
    <NButton text class="back" @click="router.push({ name: 'blog' })">
      <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
      {{ t('博客') }}
    </NButton>

    <NSkeleton v-if="loading" text :repeat="6" />
    <template v-else>
      <div class="head">
        <h1 class="page-title">{{ id ? form.title || t('编辑文章') : t('写文章') }}</h1>
        <div class="actions">
          <a v-if="id && form.published && !scheduled" :href="`${auth.currentStore?.storefront_url}/blog/${form.handle}`" target="_blank" rel="noopener">
            <NButton>{{ t('在店铺中查看') }}</NButton>
          </a>
          <NButton v-if="id" @click="remove">{{ t('删除') }}</NButton>
          <NButton type="primary" :loading="saving" :disabled="id !== null && !dirty" @click="save">{{ t('保存') }}</NButton>
        </div>
      </div>

      <div class="grid">
        <div class="main">
          <NCard size="small">
            <div class="label">{{ t('标题') }}</div>
            <NInput v-model:value="form.title" :placeholder="t('例如：How to Layer Necklaces')" maxlength="255" />
            <div class="label" style="margin-top: 16px">{{ t('内容') }}</div>
            <NTabs v-model:value="tab" type="line" size="small">
              <NTab name="write">{{ t('编辑') }}</NTab>
              <NTab name="preview">{{ t('预览') }}</NTab>
            </NTabs>
            <NInput v-if="tab === 'write'" v-model:value="form.body" type="textarea" :autosize="{ minRows: 16, maxRows: 48 }"
              :placeholder="t('## 小标题\n\n正文段落……\n\n- 列表项\n- **加粗** [链接](https://example.com)')" maxlength="200000" class="md" />
            <div v-else class="rte" :class="{ dim: previewing }" v-html="previewHtml || `<p style='color:#999'>${t('（空）')}</p>`" />
            <p class="hint">
              {{ t('支持 Markdown：') }}<code>{{ t('## 小标题') }}</code>{{ t('、') }}<code>{{ t('**加粗**') }}</code>{{ t('、') }}<code>{{ t('- 列表') }}</code>{{ t('、') }}<code>{{ t('[文字](链接)') }}</code>{{ t('、') }}<code>{{ t('![图片](图片地址)') }}</code>{{ t('。为了安全，HTML 代码会被自动去除。') }}
            </p>
          </NCard>

          <NCard :title="t('摘要')" size="small">
            <NInput v-model:value="form.excerpt" type="textarea" :autosize="{ minRows: 2, maxRows: 5 }" maxlength="1000" show-count
              :placeholder="t('显示在博客列表里，留空则自动截取正文开头')" />
          </NCard>

          <SeoCard v-model="seo" :path="`/blog/${form.handle || '…'}`" :fallback-title="form.title" :fallback-description="form.excerpt || form.body" />
        </div>

        <div class="side">
          <NCard :title="t('发布')" size="small">
            <div class="row">
              <span>{{ scheduled ? t('定时发布') : form.published ? t('已发布') : t('草稿（买家看不到）') }}</span>
              <NSwitch v-model:value="form.published" />
            </div>
            <div class="label" style="margin-top: 14px">{{ t('发布时间') }}</div>
            <NDatePicker v-model:value="form.published_at" type="datetime" clearable style="width: 100%" :placeholder="t('留空则保存时立即发布')" />
            <p v-if="scheduled" class="hint">{{ t('到时间后自动出现在博客里。') }}</p>
          </NCard>
          <NCard :title="t('封面图片')" size="small">
            <div v-if="form.cover_image" class="cover"><img :src="form.cover_image" alt=""></div>
            <div class="cover-actions">
              <label class="upload">
                <input type="file" accept="image/jpeg,image/png,image/webp" @change="onCover">
                <NButton size="small" :loading="uploading" tag="span">{{ form.cover_image ? t('更换图片') : t('上传图片') }}</NButton>
              </label>
              <NButton v-if="form.cover_image" size="small" quaternary @click="form.cover_image = null">{{ t('移除') }}</NButton>
            </div>
          </NCard>
          <NCard :title="t('作者和标签')" size="small">
            <NInput v-model:value="form.author" :placeholder="t('作者（选填）')" maxlength="80" />
            <div class="label" style="margin-top: 12px">{{ t('标签') }}</div>
            <NDynamicTags v-model:value="form.tags" :max="10" />
          </NCard>
          <NCard :title="t('网址')" size="small">
            <NInput v-model:value="form.handle" :placeholder="id ? '' : t('保存时根据标题生成')">
              <template #prefix><span class="hint" style="margin: 0">/blog/</span></template>
            </NInput>
          </NCard>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.editor { container-type: inline-size; }
.back { margin-bottom: 12px; color: var(--ink-muted); }
.head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.head .page-title { margin: 0; }
.actions { display: flex; gap: 8px; }
.actions a { text-decoration: none; }
.grid { display: grid; grid-template-columns: 1fr 300px; gap: 16px; align-items: start; }
.main, .side { display: grid; gap: 16px; min-width: 0; }
.label { font-size: 13px; margin-bottom: 6px; }
.md :deep(textarea) { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; line-height: 1.6; }
.hint { font-size: 12px; color: var(--ink-muted); margin: 10px 0 0; line-height: 1.7; }
.hint code { background: var(--surface-muted); padding: 1px 5px; border-radius: 4px; font-size: 11px; }
.row { display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 14px; }
.rte { min-height: 280px; padding: 8px 4px; line-height: 1.8; font-size: 15px; }
.rte.dim { opacity: .5; }
.rte :deep(h1), .rte :deep(h2) { font-size: 20px; margin: 20px 0 8px; }
.rte :deep(h3) { font-size: 16px; margin: 16px 0 6px; }
.rte :deep(p) { margin: 0 0 12px; }
.rte :deep(a) { color: var(--brand-700); }
.rte :deep(img) { max-width: 100%; }
.cover { border-radius: 8px; overflow: hidden; margin-bottom: 10px; aspect-ratio: 4 / 3; background: var(--surface-muted); }
.cover img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cover-actions { display: flex; gap: 8px; align-items: center; }
.upload input { display: none; }
@container (max-width: 820px) { .grid { grid-template-columns: 1fr; } }
</style>
