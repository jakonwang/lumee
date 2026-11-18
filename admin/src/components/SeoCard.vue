<script setup lang="ts">
import { computed, ref } from 'vue'
import { NCard, NInput, NButton, NFormItem } from 'naive-ui'
import { useAuth } from '@/stores/auth'

/** Search engine listing: custom title/description with a Google-style preview. */
const props = defineProps<{
  path: string
  fallbackTitle: string
  fallbackDescription: string
}>()
const seo = defineModel<{ title: string | null; description: string | null }>({ required: true })

const auth = useAuth()
const editing = ref(false)

const shownTitle = computed(() => seo.value.title?.trim() || props.fallbackTitle || '—')
const shownDescription = computed(() => {
  // Fallback: page/product text with HTML tags and Markdown syntax removed
  const d = seo.value.description?.trim() || props.fallbackDescription
    .replace(/<[^>]*>/g, ' ')
    .replace(/!\[[^\]]*]\([^)]*\)/g, ' ')
    .replace(/\[([^\]]*)]\([^)]*\)/g, '$1')
    .replace(/[#*_>`~|]+/g, ' ')
    .replace(/^\s*[-+]\s+/gm, ' ')
    .replace(/\s+/g, ' ')
    .trim()
  return d.length > 160 ? `${d.slice(0, 157)}…` : d
})
const url = computed(() => `${(auth.currentStore?.storefront_url ?? '').replace(/^https?:\/\//, '')}${props.path}`)

function set(key: 'title' | 'description', v: string) {
  seo.value = { ...seo.value, [key]: v || null }
}
</script>

<template>
  <NCard :title="t('搜索引擎优化（SEO）')" size="small">
    <template #header-extra>
      <NButton text type="primary" size="small" @click="editing = !editing">{{ editing ? t('收起') : t('编辑') }}</NButton>
    </template>
    <div class="preview" :aria-label="t('Google 搜索结果预览')">
      <div class="url">{{ url }}</div>
      <div class="title">{{ shownTitle }}</div>
      <div class="desc">{{ shownDescription || t('添加描述，让买家在搜索结果里了解这个页面。') }}</div>
    </div>
    <template v-if="editing">
      <NFormItem :label="t('标题')" :show-feedback="false" style="margin-top: 14px">
        <NInput :value="seo.title ?? ''" :placeholder="fallbackTitle" maxlength="70" show-count @update:value="(v) => set('title', v)" />
      </NFormItem>
      <NFormItem :label="t('描述')" :show-feedback="false" style="margin-top: 12px">
        <NInput :value="seo.description ?? ''" type="textarea" :autosize="{ minRows: 2, maxRows: 5 }" maxlength="320" show-count
          :placeholder="t('不填写时使用正文开头')" @update:value="(v) => set('description', v)" />
      </NFormItem>
      <p class="hint">{{ t('建议标题 60 字符以内、描述 160 字符以内，太长会被搜索引擎截断。') }}</p>
    </template>
  </NCard>
</template>

<style scoped>
.preview { font-family: Arial, sans-serif; }
.url { font-size: 12px; color: var(--ink-muted); word-break: break-all; }
.title { font-size: 17px; color: #1a0dab; margin: 2px 0; line-height: 1.3; }
.desc { font-size: 13px; color: var(--ink-muted); line-height: 1.5; }
.hint { font-size: 12px; color: var(--ink-muted); margin: 10px 0 0; }
</style>
