<script setup lang="ts">
/**
 * Products › Content workshop (内容工坊): every product's content score against the brand kit
 * (lowest first, with what to fix), bulk image standardizing, and the brand kit itself.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  NButton, NCard, NCheckbox, NColorPicker, NDynamicTags, NEmpty, NForm, NFormItem, NInput, NInputNumber, NPagination,
  NProgress, NRadioButton, NRadioGroup, NSelect, NSpin, NSwitch, useMessage,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

const message = useMessage()
const tab = ref<'quality' | 'kit'>('quality')

// Quality list
interface Row { id: number; title: string; status: string; image: string | null; score: number; issues: string[] }
const rows = ref<Row[]>([])
const meta = ref({ total: 0, page: 1, last_page: 1 })
const summary = ref({ average: 0, low: 0, unscored: 0, total: 0 })
const minScore = ref(0)
const loading = ref(true)
const page = ref(1)
const onlyLow = ref(false)
const q = ref('')
const selected = ref<number[]>([])
async function load() {
  loading.value = true
  try {
    const d = (await http.get('/content/products', { params: { page: page.value, max: onlyLow.value ? 59 : undefined, q: q.value || undefined } })).data
    rows.value = d.data
    meta.value = d.meta
    summary.value = d.summary
    minScore.value = d.min_score
    selected.value = []
  } finally {
    loading.value = false
  }
}
watch([page, onlyLow], load)
let timer: number | undefined
watch(q, () => { clearTimeout(timer); timer = window.setTimeout(() => { page.value = 1; load() }, 300) })
const color = (s: number) => (s >= 80 ? 'var(--brand-600)' : s >= 60 ? '#d89614' : '#d03050')
const allChecked = computed(() => rows.value.length > 0 && rows.value.every((r) => selected.value.includes(r.id)))

const rescoring = ref(false)
async function rescore() {
  rescoring.value = true
  try {
    const r = (await http.post('/content/rescore')).data
    message.success(t('已重新评分 {0} 个商品', [r.scored]))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    rescoring.value = false
  }
}

const removeBg = ref(false)
const imaging = ref(false)
async function standardize(scope: 'main' | 'all') {
  imaging.value = true
  try {
    const r = (await http.post('/content/images/standardize', { product_ids: selected.value, scope, remove_background: removeBg.value })).data
    if (r.errors.length) message.warning(t('处理了 {0} 张图片，{1} 个商品失败：{2}', [r.done, r.errors.length, r.errors[0].message]))
    else message.success(t('已处理 {0} 张图片，原图已保留', [r.done]))
    await load()
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    imaging.value = false
  }
}

// Brand kit
interface Kit {
  positioning: string; audience: string; voice: string; banned_words: string[]; title_pattern: string; title_min: number; title_max: number
  ratio: string; background: string; padding: number; min_images: number; sections: string[]; min_score: number; removebg_connected: boolean
}
const kit = reactive<Kit>({ positioning: '', audience: '', voice: '', banned_words: [], title_pattern: '', title_min: 30, title_max: 90,
  ratio: '3:4', background: '#F7F5F2', padding: 8, min_images: 4, sections: [], min_score: 0, removebg_connected: false })
const allSections = ref<string[]>([])
const removebgKey = ref('')
// Image generation service for AI scene / model pictures
const imageAi = reactive({ provider: 'openai', connected: false, quota: 50, used: 0, providers: [] as { key: string; name: string }[] })
const imageAiKey = ref('')
const savingKit = ref(false)
const sectionLabels: Record<string, string> = {
  highlights: t('卖点'), details: t('细节'), materials: t('材质和工艺'), size: t('尺码 / 尺寸'), care: t('保养'), shipping: t('发货和退换'), faq: t('常见问题'),
}
async function loadKit() {
  const d = (await http.get('/content/brand-kit')).data
  Object.assign(kit, d.data)
  allSections.value = d.sections
  Object.assign(imageAi, d.image_ai)
}
function toggleSection(s: string, on: boolean) {
  kit.sections = on ? [...kit.sections, s] : kit.sections.filter((x) => x !== s)
}
function moveSection(i: number, dir: -1 | 1) {
  const j = i + dir
  if (j < 0 || j >= kit.sections.length) return
  const list = [...kit.sections];
  [list[i], list[j]] = [list[j], list[i]]
  kit.sections = list
}
async function saveKit() {
  savingKit.value = true
  try {
    const body: Record<string, unknown> = { ...kit }
    delete body.removebg_connected
    if (removebgKey.value.trim()) body.removebg_key = removebgKey.value.trim()
    body.image_ai_provider = imageAi.provider
    body.image_ai_quota = imageAi.quota
    if (imageAiKey.value.trim()) body.image_ai_key = imageAiKey.value.trim()
    const d = (await http.put('/content/brand-kit', body)).data
    Object.assign(kit, d.data)
    Object.assign(imageAi, d.image_ai)
    removebgKey.value = ''
    imageAiKey.value = ''
    message.success(t('已保存。修改了规范后，可以在「内容质量」里重新评分。'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    savingKit.value = false
  }
}
async function disconnectImageAi() {
  try {
    const d = (await http.put('/content/brand-kit', { ...kit, image_ai_key: '' })).data
    Object.assign(imageAi, d.image_ai)
  } catch (e) {
    message.error(errorMessage(e))
  }
}
async function disconnectRemoveBg() {
  try {
    const d = (await http.put('/content/brand-kit', { ...kit, removebg_key: '' })).data
    Object.assign(kit, d.data)
  } catch (e) {
    message.error(errorMessage(e))
  }
}
// Preview of the image canvas
const previewStyle = computed(() => {
  const [w, h] = kit.ratio.split(':').map(Number)
  return { aspectRatio: `${w} / ${h}`, background: kit.background, padding: `${kit.padding}%` }
})

onMounted(() => { load(); loadKit() })
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Sourcing</div>
      <h1 class="page-title">{{ t('内容工坊') }}</h1>
      <p class="muted">{{ t('按品牌规范检查每个商品的标题、图片、描述、属性和翻译，给出 0–100 的内容分。用 AI 和图片工具把采集来的商品统一成自己的品牌风格。') }}</p>
    </div>
    <NRadioGroup v-model:value="tab" size="small">
      <NRadioButton value="quality">{{ t('内容质量') }}</NRadioButton>
      <NRadioButton value="kit">{{ t('品牌规范') }}</NRadioButton>
    </NRadioGroup>

    <template v-if="tab === 'quality'">
      <div class="tiles">
        <NCard size="small"><div class="tile-label">{{ t('平均内容分') }}</div><div class="tile-value" :style="{ color: color(summary.average) }">{{ summary.average }}</div></NCard>
        <NCard size="small"><div class="tile-label">{{ t('低于 60 分') }}</div><div class="tile-value">{{ summary.low }}</div></NCard>
        <NCard size="small"><div class="tile-label">{{ t('上架标准') }}</div><div class="tile-value">{{ minScore || t('未设置') }}</div></NCard>
        <NCard size="small"><div class="tile-label">{{ t('商品总数') }}</div><div class="tile-value">{{ summary.total }}</div></NCard>
      </div>

      <NCard size="small">
        <div class="toolbar">
          <NInput v-model:value="q" size="small" clearable :placeholder="t('搜索商品')" style="width: 220px" />
          <NCheckbox v-model:checked="onlyLow">{{ t('只看低于 60 分') }}</NCheckbox>
          <NButton size="small" secondary :loading="rescoring" style="margin-left: auto" @click="rescore">{{ t('全部重新评分') }}</NButton>
        </div>
        <div v-if="selected.length" class="toolbar sel">
          <span class="strong">{{ t('已选 {0} 个', [selected.length]) }}</span>
          <NButton size="small" type="primary" secondary :loading="imaging" @click="standardize('main')">{{ t('统一主图') }}</NButton>
          <NButton size="small" secondary :loading="imaging" @click="standardize('all')">{{ t('统一全部图片') }}</NButton>
          <NCheckbox v-model:checked="removeBg" :disabled="!kit.removebg_connected">{{ t('先抠图（remove.bg）') }}</NCheckbox>
        </div>

        <div v-if="loading" class="center"><NSpin /></div>
        <NEmpty v-else-if="!rows.length" :description="t('没有符合条件的商品')" style="padding: 32px 0" />
        <div v-else>
          <div class="row header">
            <NCheckbox :checked="allChecked" :aria-label="t('全选')" @update:checked="(v: boolean) => selected = v ? rows.map((r) => r.id) : []" />
            <span>{{ t('商品') }}</span><span>{{ t('需要改进') }}</span><span class="r">{{ t('内容分') }}</span>
          </div>
          <div v-for="r in rows" :key="r.id" class="row">
            <NCheckbox :checked="selected.includes(r.id)" :aria-label="t('选择')" @update:checked="(v: boolean) => selected = v ? [...selected, r.id] : selected.filter((x) => x !== r.id)" />
            <div class="prod">
              <img v-if="r.image" :src="r.image" alt="" class="thumb">
              <div v-else class="thumb" />
              <div>
                <RouterLink :to="{ name: 'product-edit', params: { id: r.id } }" class="title">{{ r.title }}</RouterLink>
                <div class="muted small">{{ r.status === 'active' ? t('在售') : r.status === 'draft' ? t('草稿') : t('已归档') }}</div>
              </div>
            </div>
            <ul class="issues"><li v-for="(m, i) in r.issues" :key="i">{{ m }}</li><li v-if="!r.issues.length" class="ok">{{ t('没有问题') }}</li></ul>
            <div class="r score-cell">
              <NProgress type="line" :percentage="r.score" :color="color(r.score)" :show-indicator="false" style="width: 70px" />
              <span class="score" :style="{ color: color(r.score) }">{{ r.score }}</span>
            </div>
          </div>
        </div>
        <div v-if="meta.last_page > 1" class="pager"><NPagination v-model:page="page" :page-count="meta.last_page" /></div>
      </NCard>
    </template>

    <template v-else>
      <NCard :title="t('品牌')" size="small">
        <NForm label-placement="top">
          <NFormItem :label="t('品牌定位')"><NInput v-model:value="kit.positioning" maxlength="300" :placeholder="t('例如：给 25–35 岁都市女性的平价轻奢饰品')" /></NFormItem>
          <NFormItem :label="t('目标人群')"><NInput v-model:value="kit.audience" maxlength="300" :placeholder="t('例如：注重设计、预算有限的上班族')" /></NFormItem>
          <NFormItem :label="t('品牌语气')"><NInput v-model:value="kit.voice" maxlength="300" :placeholder="t('例如：温暖、自信、简洁；不用感叹号堆砌')" /></NFormItem>
          <NFormItem :label="t('禁用词（标题和描述里不能出现）')"><NDynamicTags v-model:value="kit.banned_words" /></NFormItem>
        </NForm>
      </NCard>

      <NCard :title="t('标题')" size="small">
        <NForm label-placement="top">
          <NFormItem :label="t('命名规则')"><NInput v-model:value="kit.title_pattern" maxlength="200" :placeholder="t('例如：[系列名] [品类] – [材质/卖点] | [风格]')" /></NFormItem>
          <div class="two">
            <NFormItem :label="t('最短（字符）')"><NInputNumber v-model:value="kit.title_min" :min="5" :max="200" style="width: 100%" /></NFormItem>
            <NFormItem :label="t('最长（字符）')"><NInputNumber v-model:value="kit.title_max" :min="10" :max="255" style="width: 100%" /></NFormItem>
          </div>
        </NForm>
      </NCard>

      <NCard :title="t('图片')" size="small">
        <div class="img-kit">
          <NForm label-placement="top" class="img-form">
            <NFormItem :label="t('主图比例')">
              <NRadioGroup v-model:value="kit.ratio">
                <NRadioButton value="3:4">3:4</NRadioButton><NRadioButton value="4:5">4:5</NRadioButton><NRadioButton value="1:1">1:1</NRadioButton>
              </NRadioGroup>
            </NFormItem>
            <div class="two">
              <NFormItem :label="t('背景色')"><NColorPicker v-model:value="kit.background" :modes="['hex']" :show-alpha="false" /></NFormItem>
              <NFormItem :label="t('四周留白（%）')"><NInputNumber v-model:value="kit.padding" :min="0" :max="30" style="width: 100%" /></NFormItem>
            </div>
            <NFormItem :label="t('每个商品至少几张图')"><NInputNumber v-model:value="kit.min_images" :min="1" :max="12" style="width: 140px" /></NFormItem>
            <NFormItem :label="t('remove.bg API Key（自动抠图，可选）')">
              <div class="inline">
                <NInput v-model:value="removebgKey" type="password" show-password-on="click" :placeholder="kit.removebg_connected ? t('已连接（留空保持不变）') : t('在 remove.bg 申请，按张计费')" />
                <NButton v-if="kit.removebg_connected" size="small" quaternary type="error" @click="disconnectRemoveBg">{{ t('断开') }}</NButton>
              </div>
            </NFormItem>
            <NFormItem :label="t('AI 场景图 / 模特图（图片生成服务，可选）')">
              <div class="stack">
                <div class="inline">
                  <NSelect v-model:value="imageAi.provider" :options="imageAi.providers.map((p) => ({ label: p.name, value: p.key }))" style="width: 200px" />
                  <NInput v-model:value="imageAiKey" type="password" show-password-on="click" :placeholder="imageAi.connected ? t('已连接（留空保持不变）') : t('API Key，按张计费')" />
                  <NButton v-if="imageAi.connected" size="small" quaternary type="error" @click="disconnectImageAi">{{ t('断开') }}</NButton>
                </div>
                <div class="inline">
                  <span class="muted small">{{ t('每月最多生成') }}</span>
                  <NInputNumber v-model:value="imageAi.quota" :min="0" :max="10000" size="small" style="width: 110px" />
                  <span class="muted small">{{ t('张 · 本月已用 {0} 张', [imageAi.used]) }}</span>
                </div>
              </div>
            </NFormItem>
          </NForm>
          <div class="preview" :aria-label="t('主图版式预览')">
            <div class="canvas" :style="previewStyle"><div class="product" /></div>
            <div class="muted small">{{ t('主图版式预览') }}</div>
          </div>
        </div>
      </NCard>

      <NCard :title="t('详情页结构')" size="small">
        <p class="muted">{{ t('AI 改写描述时按这个顺序组织内容。勾选要用的模块，用箭头调整顺序。') }}</p>
        <div class="sections">
          <div v-for="(s, i) in kit.sections" :key="s" class="section on">
            <NCheckbox :checked="true" @update:checked="(v: boolean) => toggleSection(s, v)">{{ sectionLabels[s] ?? s }}</NCheckbox>
            <span class="arrows">
              <NButton size="tiny" quaternary :aria-label="t('上移')" @click="moveSection(i, -1)">↑</NButton>
              <NButton size="tiny" quaternary :aria-label="t('下移')" @click="moveSection(i, 1)">↓</NButton>
            </span>
          </div>
          <div v-for="s in allSections.filter((x) => !kit.sections.includes(x))" :key="s" class="section">
            <NCheckbox :checked="false" @update:checked="(v: boolean) => toggleSection(s, v)">{{ sectionLabels[s] ?? s }}</NCheckbox>
          </div>
        </div>
      </NCard>

      <NCard :title="t('上架标准')" size="small">
        <div class="inline">
          <NSwitch :value="kit.min_score > 0" @update:value="(v: boolean) => kit.min_score = v ? 60 : 0" />
          <span>{{ t('内容分低于') }}</span>
          <NInputNumber v-model:value="kit.min_score" :min="0" :max="100" :disabled="kit.min_score === 0" size="small" style="width: 90px" />
          <span>{{ t('的采集商品，选择「直接在售」时也只存为草稿') }}</span>
        </div>
      </NCard>

      <div><NButton type="primary" :loading="savingKit" @click="saveKit">{{ t('保存品牌规范') }}</NButton></div>
    </template>
  </div>
</template>

<style scoped>
.stack { display: grid; gap: 8px; width: 100%; }
.page { display: grid; gap: 16px; max-width: 1100px; }
.page-title { margin: 0 0 4px; }
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0; }
.small { font-size: 12px; }
.strong { font-weight: 600; }
.tiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.tile-label { color: var(--ink-muted); font-size: 13px; }
.tile-value { font-size: 26px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
.toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
.toolbar.sel { background: var(--surface-muted); padding: 8px 10px; border-radius: 8px; }
.center { display: flex; justify-content: center; padding: 40px; }
.row { display: grid; grid-template-columns: 28px minmax(200px, 1.2fr) 2fr 110px; gap: 12px; align-items: center; padding: 10px 4px; border-top: 1px solid var(--border); }
.row.header { border-top: 0; font-size: 13px; color: var(--ink-muted); }
.r { text-align: right; }
.prod { display: flex; gap: 10px; align-items: center; min-width: 0; }
.thumb { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border); background: var(--surface-muted); flex-shrink: 0; }
.title { color: var(--ink); font-weight: 500; font-size: 14px; text-decoration: none; }
.title:hover { color: var(--brand-700); text-decoration: underline; }
.issues { margin: 0; padding-left: 16px; font-size: 12px; line-height: 1.6; color: var(--ink); }
.issues .ok { color: var(--brand-700); list-style: none; margin-left: -16px; }
.score-cell { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
.score { font-weight: 700; font-variant-numeric: tabular-nums; width: 26px; }
.pager { display: flex; justify-content: flex-end; padding-top: 12px; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
.img-kit { display: grid; grid-template-columns: 1fr 180px; gap: 24px; align-items: start; }
.preview { display: grid; gap: 6px; justify-items: center; }
.canvas { width: 150px; border: 1px solid var(--border); border-radius: 6px; box-sizing: border-box; display: flex; }
.product { flex: 1; border-radius: 4px; background: repeating-linear-gradient(45deg, #c9d3cd, #c9d3cd 6px, #dfe6e2 6px, #dfe6e2 12px); }
.inline { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; width: 100%; }
.sections { display: grid; gap: 4px; max-width: 420px; }
.section { display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; border: 1px solid var(--border); border-radius: 8px; }
.section:not(.on) { opacity: .7; border-style: dashed; }
@media (max-width: 760px) {
  .tiles { grid-template-columns: 1fr 1fr; }
  .row { grid-template-columns: 28px 1fr; }
  .row > .issues, .row > .r { grid-column: 2; text-align: left; }
  .row.header > span:not(:nth-child(2)) { display: none; }
  .score-cell { justify-content: flex-start; }
  .img-kit, .two { grid-template-columns: 1fr; }
}
</style>
