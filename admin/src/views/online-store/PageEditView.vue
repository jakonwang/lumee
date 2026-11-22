<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { NCard, NInput, NButton, NSwitch, NIcon, NSkeleton, NTabs, NTab, NRadioGroup, NRadio, useMessage, useDialog } from 'naive-ui'
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
const tab = ref<'write' | 'preview'>('write')
const previewHtml = ref('')
const previewing = ref(false)
const form = reactive({ title: '', handle: '', body: '', layout: 'text' as 'text' | 'blocks', published: true })
/** Live theme to open in the editor for a blocks page (from the API once saved as one) */
const themeId = ref<number | null>(null)
const seo = ref<{ title: string | null; description: string | null }>({ title: null, description: null })

async function load() {
  if (!id.value) return
  loading.value = true
  try {
    const { data } = await http.get(`/pages/${id.value}`)
    const p = data.data
    Object.assign(form, { title: p.title, handle: p.handle, body: p.body, layout: p.layout ?? 'text', published: p.published })
    themeId.value = p.theme_id ?? null
    seo.value = { title: p.seo?.title ?? null, description: p.seo?.description ?? null }
  } finally {
    loading.value = false
    setTimeout(() => { dirty.value = false })
  }
}

watch([form, seo], () => { dirty.value = true }, { deep: true })

// Same renderer as the storefront, so the preview is exactly what shoppers see
watch(tab, async (it) => {
  if (it !== 'preview') return
  previewing.value = true
  try {
    previewHtml.value = (await http.post('/pages/preview', { body: form.body })).data.html
  } finally {
    previewing.value = false
  }
})

async function save() {
  if (!form.title.trim()) {
    message.error(t('请填写页面标题'))
    return
  }
  saving.value = true
  try {
    const body = { ...form, handle: form.handle || undefined, seo: seo.value }
    const { data } = id.value ? await http.put(`/pages/${id.value}`, body) : await http.post('/pages', body)
    form.handle = data.data.handle
    dirty.value = false
    message.success(t('已保存'))
    if (!id.value) router.replace({ name: 'page-edit', params: { id: data.data.id } })
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

function remove() {
  dialog.warning({
    title: t('删除页面「{0}」？', [form.title]),
    content: t('删除后无法恢复，导航菜单里指向它的链接会自动从店铺隐藏。'),
    positiveText: t('删除'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      await http.delete(`/pages/${id.value}`)
      dirty.value = false
      message.success(t('已删除'))
      router.push({ name: 'pages' })
    },
  })
}

onBeforeRouteLeave(() => (dirty.value ? window.confirm(t('有未保存的修改，确定离开吗？')) : true))
onMounted(load)
</script>

<template>
  <div class="editor">
    <NButton text class="back" @click="router.push({ name: 'pages' })">
      <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
      {{ t('页面') }}
    </NButton>

    <NSkeleton v-if="loading" text :repeat="6" />
    <template v-else>
      <div class="head">
        <h1 class="page-title">{{ id ? form.title || t('编辑页面') : t('添加页面') }}</h1>
        <div class="actions">
          <a v-if="id && form.published" :href="`${auth.currentStore?.storefront_url}/pages/${form.handle}`" target="_blank" rel="noopener">
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
            <NInput v-model:value="form.title" :placeholder="t('例如：About Us、Shipping Policy')" maxlength="255" />
            <template v-if="form.layout === 'blocks'">
              <div class="label" style="margin-top: 16px">{{ t('内容') }}</div>
              <p class="hint" style="margin-top: 0">{{ t('这是用模块装修的活动页：在店铺装修里添加轮播、限时抢购、优惠券、商品列表等模块，可以给每个市场单独修改。') }}</p>
              <RouterLink v-if="themeId && !dirty" :to="{ name: 'theme-editor', params: { id: themeId }, query: { place: `page:${id}` } }">
                <NButton type="primary">{{ t('去装修这个页面') }}</NButton>
              </RouterLink>
              <p v-else class="hint">{{ t('先保存，再去装修这个页面。') }}</p>
            </template>
            <template v-else>
            <div class="label" style="margin-top: 16px">{{ t('内容') }}</div>
            <NTabs v-model:value="tab" type="line" size="small">
              <NTab name="write">{{ t('编辑') }}</NTab>
              <NTab name="preview">{{ t('预览') }}</NTab>
            </NTabs>
            <NInput v-if="tab === 'write'" v-model:value="form.body" type="textarea" :autosize="{ minRows: 14, maxRows: 40 }"
              :placeholder="t('## 小标题\n\n正文段落……\n\n- 列表项\n- **加粗** [链接](https://example.com)')" maxlength="100000" class="md" />
            <div v-else class="rte" :class="{ dim: previewing }" v-html="previewHtml || `<p style='color:#999'>${t('（空）')}</p>`" />
            <p class="hint">
              {{ t('支持 Markdown：') }}<code>{{ t('## 小标题') }}</code>{{ t('、') }}<code>{{ t('**加粗**') }}</code>{{ t('、') }}<code>{{ t('- 列表') }}</code>{{ t('、') }}<code>{{ t('[文字](链接)') }}</code>{{ t('、') }}<code>{{ t('![图片](图片地址)') }}</code>{{ t('。为了安全，HTML 代码会被自动去除。') }}
            </p>
            </template>
          </NCard>

          <SeoCard v-model="seo" :path="`/pages/${form.handle || '…'}`" :fallback-title="form.title" :fallback-description="form.body" />
        </div>

        <div class="side">
          <NCard :title="t('页面类型')" size="small">
            <NRadioGroup v-model:value="form.layout">
              <div class="layout-opts">
                <NRadio value="text">{{ t('文字页面（关于我们、政策等）') }}</NRadio>
                <NRadio value="blocks">{{ t('活动页（用模块装修）') }}</NRadio>
              </div>
            </NRadioGroup>
          </NCard>
          <NCard :title="t('显示')" size="small">
            <div class="row">
              <span>{{ form.published ? t('已发布') : t('未发布（买家看不到）') }}</span>
              <NSwitch v-model:value="form.published" />
            </div>
          </NCard>
          <NCard :title="t('网址')" size="small">
            <NInput v-model:value="form.handle" :placeholder="id ? '' : t('保存时根据标题生成')">
              <template #prefix><span class="hint" style="margin: 0">/pages/</span></template>
            </NInput>
          </NCard>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.layout-opts { display: grid; gap: 8px; }
.editor { container-type: inline-size; }
.back { margin-bottom: 12px; color: var(--ink-muted); }
.head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.head .page-title { margin: 0; }
.actions { display: flex; gap: 8px; }
.actions a { text-decoration: none; }
.grid { display: grid; grid-template-columns: 1fr 280px; gap: 16px; align-items: start; }
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
@container (max-width: 820px) { .grid { grid-template-columns: 1fr; } }
</style>
