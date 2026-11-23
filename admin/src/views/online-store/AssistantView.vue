<script setup lang="ts">
/** Online store › AI 导购: the storefront shopping assistant — on/off, greeting, monthly allowance, what shoppers ask. */
import { onMounted, ref } from 'vue'
import { NAlert, NButton, NCard, NEmpty, NForm, NFormItem, NInput, NInputNumber, NSpin, NSwitch, NTag, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Status { enabled: boolean; ready: boolean; used: number; quota: number; greeting: string }
interface Question { id: number; question: string; reply: string; locale: string | null; created_at: string; products: string[] }

const message = useMessage()
const loading = ref(false)
const saving = ref(false)
const status = ref<Status | null>(null)
const questions = ref<Question[]>([])
const week = ref(0)
const form = ref({ enabled: false, greeting: '', quota: 1000 })

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/assistant')
    status.value = data.status
    questions.value = data.questions
    week.value = data.week
    form.value = { enabled: data.status.enabled, greeting: data.status.greeting, quota: data.status.quota }
  } catch (e) { message.error(errorMessage(e)) } finally { loading.value = false }
}
onMounted(load)

async function save() {
  saving.value = true
  try {
    const { data } = await http.put('/assistant', form.value)
    status.value = data.status
    message.success(t('已保存'))
  } catch (e) { message.error(errorMessage(e)) } finally { saving.value = false }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <div class="eyebrow">Online Store</div>
        <h1 class="page-title">{{ t('AI 导购') }}</h1>
        <p class="page-sub">{{ t('店铺右下角的聊天助手：用买家的语言回答尺码、材质、运费、退货等问题，并推荐店里的商品。只会推荐在售商品，价格和政策都来自店铺设置，不会编造。') }}</p>
      </div>
    </div>

    <NSpin :show="loading && !status">
      <template v-if="status">
        <NAlert v-if="!status.ready" type="warning" :bordered="false" class="block">
          {{ t('还没有配置 Claude API Key。请先在「获客雷达 › 设置」里填写，AI 导购才会在店铺里出现。') }}
          <RouterLink :to="{ name: 'leads' }">{{ t('去设置') }}</RouterLink>
        </NAlert>

        <NCard :title="t('设置')" class="block">
          <NForm label-placement="left" label-width="auto" style="max-width: 640px" @submit.prevent="save">
            <NFormItem :label="t('在店铺显示')">
              <NSwitch v-model:value="form.enabled" />
              <span class="muted" style="margin-inline-start: 10px">{{ status.enabled && status.ready ? t('买家现在可以看到') : t('未显示') }}</span>
            </NFormItem>
            <NFormItem :label="t('开场白')">
              <NInput v-model:value="form.greeting" type="textarea" :maxlength="300" show-count :autosize="{ minRows: 2, maxRows: 4 }"
                :placeholder="t('留空则使用默认开场白（按买家语言显示）')" />
            </NFormItem>
            <NFormItem :label="t('每月回答上限')">
              <NInputNumber v-model:value="form.quota" :min="0" :max="20000" :step="100" style="width: 160px" />
              <span class="muted" style="margin-inline-start: 10px">{{ t('本月已回答 {0} 次', [status.used]) }}</span>
            </NFormItem>
            <p class="muted">{{ t('每次回答都会调用一次 Claude，费用计入你的 Claude 账户。达到上限后聊天按钮自动隐藏，下个月恢复。') }}</p>
            <NButton type="primary" attr-type="submit" :loading="saving">{{ t('保存') }}</NButton>
          </NForm>
        </NCard>

        <NCard :title="t('买家在问什么')" class="block">
          <template #header-extra><NTag size="small" :bordered="false">{{ t('最近 7 天 {0} 个问题', [week]) }}</NTag></template>
          <NEmpty v-if="!questions.length" :description="t('还没有买家提问。')" />
          <div v-for="q in questions" :key="q.id" class="qa">
            <div class="q"><strong>{{ q.question }}</strong><span class="muted">{{ formatDate(q.created_at) }}<template v-if="q.locale"> · {{ q.locale }}</template></span></div>
            <p class="a">{{ q.reply }}</p>
            <div v-if="q.products.length" class="p">
              <span class="muted">{{ t('推荐了：') }}</span>
              <NTag v-for="p in q.products" :key="p" size="small" :bordered="false">{{ p }}</NTag>
            </div>
          </div>
        </NCard>
      </template>
    </NSpin>
  </div>
</template>

<style scoped>
.block { margin-bottom: 16px; }
.muted { color: #888; font-size: 12px; }
.qa { padding: 12px 0; border-top: 1px solid #f0f0f0; }
.qa:first-of-type { border-top: 0; padding-top: 0; }
.q { display: flex; justify-content: space-between; gap: 12px; }
.a { margin: 6px 0; color: #444; font-size: 13px; line-height: 1.6; white-space: pre-line; }
.p { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
</style>
