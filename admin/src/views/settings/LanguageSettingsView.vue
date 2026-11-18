<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NCard, NButton, NSpin, NSelect, NCheckbox, NAlert, NTag, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import SettingsNav from '@/components/SettingsNav.vue'
import { t } from '@/i18n'

interface Lang { code: string; name: string; translations: number; url: string }

const router = useRouter()
const message = useMessage()
const loading = ref(true)
const saving = ref(false)
const languages = ref<Lang[]>([])
const def = ref('en')
const published = ref<string[]>([])
const saved = ref({ def: 'en', published: [] as string[] })

const dirty = computed(() => def.value !== saved.value.def || [...published.value].sort().join() !== [...saved.value.published].sort().join())
const options = computed(() => languages.value.map((l) => ({ value: l.code, label: l.name })))

function apply(d: { default: string; published: string[]; languages: Lang[] }) {
  languages.value = d.languages
  def.value = d.default
  published.value = [...d.published]
  saved.value = { def: d.default, published: [...d.published] }
}

async function load() {
  try {
    apply((await http.get('/settings/languages')).data)
  } finally {
    loading.value = false
  }
}

function toggle(code: string, on: boolean) {
  published.value = on ? [...published.value, code] : published.value.filter((c) => c !== code)
}

async function save() {
  saving.value = true
  try {
    apply((await http.put('/settings/languages', { default: def.value, published: published.value.filter((c) => c !== def.value) })).data)
    message.success(t('语言设置已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
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
      <NCard :title="t('店铺默认语言')" size="small">
        <p class="muted">{{ t('买家打开店铺首页（不带语言前缀的网址）时看到的语言。商品、页面等内容默认用这种语言填写。') }}</p>
        <NSelect v-model:value="def" :options="options" style="max-width: 280px" />
        <NAlert v-if="def !== saved.def" type="warning" :bordered="false" style="margin-top: 12px">
          {{ t('更换默认语言后，原来的默认语言会移到带前缀的网址下（例如 /en）。已有的翻译不受影响。') }}
        </NAlert>
      </NCard>

      <NCard :title="t('其他语言')" size="small">
        <p class="muted">{{ t('发布后，买家可以在店铺页脚切换语言，每种语言有独立网址（例如 /fr/products/…），搜索引擎也会收录。按钮、结账、邮件等系统文字已经翻译好；商品、页面、菜单等你自己的内容，可以在「在线商店 → 翻译」中翻译，没有翻译的内容显示默认语言。') }}</p>
        <div v-for="l in languages.filter((x) => x.code !== def)" :key="l.code" class="lang">
          <NCheckbox :checked="published.includes(l.code)" @update:checked="(v: boolean) => toggle(l.code, v)">
            <span class="lang-name">{{ l.name }}</span>
          </NCheckbox>
          <NTag v-if="saved.published.includes(l.code)" size="tiny" :bordered="false" type="success">{{ t('已发布') }}</NTag>
          <span class="muted small">{{ l.translations ? t('已翻译 {0} 处内容', [l.translations]) : t('尚未翻译内容') }}</span>
          <span class="spacer" />
          <NButton size="tiny" quaternary @click="router.push({ name: 'translations', query: { locale: l.code } })">{{ t('翻译内容') }}</NButton>
          <a v-if="saved.published.includes(l.code)" :href="l.url" target="_blank" rel="noopener"><NButton size="tiny" quaternary>{{ t('预览') }}</NButton></a>
        </div>
      </NCard>

      <div><NButton type="primary" :loading="saving" :disabled="!dirty" @click="save">{{ t('保存') }}</NButton></div>
    </template>
  </div>
</template>

<style scoped>
.page { max-width: 760px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0 0 12px; }
.small { font-size: 12px; margin: 0; }
.lang { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-top: 1px solid var(--border); }
.lang:first-of-type { border-top: 0; }
.lang-name { font-size: 14px; }
.spacer { flex: 1; }
.lang a { text-decoration: none; }
</style>
