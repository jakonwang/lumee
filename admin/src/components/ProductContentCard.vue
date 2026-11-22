<script setup lang="ts">
/**
 * Product page › content quality (内容工坊): the score against the brand kit with what to fix,
 * an AI rewrite per language (shown side by side, saved only when accepted), the version
 * history, and one-click image standardizing.
 */
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { NButton, NCard, NCheckbox, NInput, NModal, NProgress, NSelect, NTag, useDialog, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

const props = defineProps<{ productId: number; dirty: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const message = useMessage()
const dialog = useDialog()

interface Check { key: string; group: string; points: number; max: number; message: string | null }
interface Payload {
  score: number; checks: Check[]; ai_ready: boolean
  languages: { code: string; name: string; default: boolean }[]
  versions: { id: number; locale: string | null; title: string | null; reason: string; created_at: string }[]
  images: { id: number; url: string; processed: boolean; ai: string | null }[]
  image_ai: { connected: boolean; quota: number; used: number }
}
const d = ref<Payload | null>(null)
async function load() {
  d.value = (await http.get(`/products/${props.productId}/content`)).data.data
}
onMounted(load)

const groups: Record<string, string> = { title: t('标题'), images: t('图片'), description: t('描述'), attributes: t('属性'), translations: t('翻译'), seo: 'SEO' }
const byGroup = computed(() => Object.entries(groups).map(([key, label]) => {
  const cs = (d.value?.checks ?? []).filter((c) => c.group === key)
  return { key, label, points: cs.reduce((s, c) => s + c.points, 0), max: cs.reduce((s, c) => s + c.max, 0), issues: cs.filter((c) => c.message).map((c) => c.message!) }
}))
const color = (s: number) => (s >= 80 ? 'var(--brand-600)' : s >= 60 ? '#d89614' : '#d03050')

// AI rewrite
const locale = ref<string | null>(null)
const suggesting = ref(false)
const applying = ref(false)
const show = ref(false)
const cur = ref<{ title: string | null; description: string | null; seo_title: string | null; seo_description: string | null } | null>(null)
const sug = ref({ title: '', description: '', seo_title: '', seo_description: '', notes: [] as string[] })
const langOptions = computed(() => (d.value?.languages ?? []).map((l) => ({ label: l.default ? t('{0}（默认）', [l.name]) : l.name, value: l.code })))
async function suggest() {
  if (props.dirty) return message.warning(t('请先保存商品，再用 AI 改写'))
  suggesting.value = true
  try {
    const r = (await http.post(`/products/${props.productId}/content/suggest`, { locale: locale.value ?? d.value?.languages[0]?.code })).data.data
    cur.value = r.current
    sug.value = { ...r.suggestion }
    show.value = true
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    suggesting.value = false
  }
}
async function accept() {
  applying.value = true
  try {
    await http.post(`/products/${props.productId}/content/apply`, { locale: locale.value ?? d.value?.languages[0]?.code, ...sug.value })
    message.success(t('已采纳，原内容已存入历史版本'))
    show.value = false
    await load()
    emit('changed')
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    applying.value = false
  }
}
function restore(v: Payload['versions'][number]) {
  dialog.warning({
    title: t('恢复这个版本？'), content: t('当前内容会先存入历史版本，可以再恢复回来。'), positiveText: t('恢复'), negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        await http.post(`/products/${props.productId}/content/versions/${v.id}/restore`)
        message.success(t('已恢复'))
        await load()
        emit('changed')
      } catch (e) {
        message.error(errorMessage(e))
      }
    },
  })
}
const reasonLabel: Record<string, string> = { before_ai: t('AI 改写前'), before_restore: t('恢复前'), before_edit: t('修改前'), before_test: t('A/B 测试采用新版本前') }
const langName = (code: string | null) => (code === null ? t('默认语言') : d.value?.languages.find((l) => l.code === code)?.name ?? code)

// Images
const removeBg = ref(false)
const imaging = ref(false)
async function standardize(scope: 'main' | 'all') {
  if (props.dirty) return message.warning(t('请先保存商品'))
  imaging.value = true
  try {
    const r = (await http.post('/content/images/standardize', { product_ids: [props.productId], scope, remove_background: removeBg.value })).data
    if (r.errors.length) message.error(r.errors[0].message)
    else message.success(t('已处理 {0} 张图片，原图已保留，可以随时还原', [r.done]))
    await load()
    emit('changed')
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    imaging.value = false
  }
}
async function revert(id: number) {
  try {
    await http.post(`/products/${props.productId}/images/${id}/revert`)
    message.success(t('已还原原图'))
    await load()
    emit('changed')
  } catch (e) {
    message.error(errorMessage(e))
  }
}
const processed = computed(() => (d.value?.images ?? []).filter((i) => i.processed))

// AI scene / model pictures from a real product photo
const sceneLabels: Record<string, string> = { lifestyle: t('生活场景'), studio: t('棚拍'), flatlay: t('平铺摆拍'), model: t('模特上身 / 手持'), holiday: t('节日礼品') }
const sceneSource = ref<number | null>(null)
const scene = ref('lifestyle')
const sceneNote = ref('')
const generating = ref(false)
const realPhotos = computed(() => (d.value?.images ?? []).filter((i) => !i.ai))
async function generateScene() {
  if (props.dirty) return message.warning(t('请先保存商品'))
  const src = sceneSource.value ?? realPhotos.value[0]?.id
  if (!src) return message.warning(t('先上传一张商品实拍图'))
  generating.value = true
  try {
    await http.post(`/products/${props.productId}/images/${src}/scene`, { scene: scene.value, note: sceneNote.value || null })
    message.success(t('已生成，加在商品图片的最后。上架前请确认颜色、形状和实物一致。'))
    await load()
    emit('changed')
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    generating.value = false
  }
}
defineExpose({ load })
</script>

<template>
  <NCard v-if="d" :title="t('内容质量')" size="small">
    <template #header-extra>
      <RouterLink :to="{ name: 'content' }" class="link">{{ t('品牌规范') }}</RouterLink>
    </template>
    <div class="score-row">
      <NProgress type="circle" :percentage="d.score" :color="color(d.score)" :stroke-width="9" style="width: 76px">
        <span class="score">{{ d.score }}</span>
      </NProgress>
      <div class="groups">
        <div v-for="g in byGroup" :key="g.key" class="group">
          <span class="g-label">{{ g.label }}</span>
          <span class="g-pts" :class="{ full: g.points === g.max }">{{ g.points }}/{{ g.max }}</span>
        </div>
      </div>
    </div>
    <ul v-if="byGroup.some((g) => g.issues.length)" class="issues">
      <template v-for="g in byGroup" :key="g.key"><li v-for="(m, i) in g.issues" :key="g.key + i">{{ m }}</li></template>
    </ul>

    <div class="block">
      <div class="block-title">{{ t('AI 按品牌规范改写') }}</div>
      <div class="inline">
        <NSelect v-model:value="locale" :options="langOptions" size="small" :placeholder="langOptions[0]?.label" style="width: 170px" />
        <NButton size="small" type="primary" secondary :loading="suggesting" :disabled="!d.ai_ready" @click="suggest">{{ t('生成建议') }}</NButton>
      </div>
      <p v-if="!d.ai_ready" class="hint">{{ t('在「获客雷达 › 设置」填写 Claude API Key 后可用。') }}</p>
    </div>

    <div class="block">
      <div class="block-title">{{ t('统一图片风格') }}</div>
      <div class="inline">
        <NButton size="small" secondary :loading="imaging" @click="standardize('main')">{{ t('统一主图') }}</NButton>
        <NButton size="small" secondary :loading="imaging" @click="standardize('all')">{{ t('统一全部图片') }}</NButton>
        <NCheckbox v-model:checked="removeBg" size="small">{{ t('先抠图（remove.bg）') }}</NCheckbox>
      </div>
      <p class="hint">{{ t('裁掉白边，按品牌规范的比例、背景色和留白重新排版，输出 WebP。原图会保留。') }}</p>
      <div v-if="processed.length" class="processed">
        <div v-for="i in processed" :key="i.id" class="pimg">
          <img :src="i.url" alt="">
          <NButton size="tiny" quaternary @click="revert(i.id)">{{ t('还原') }}</NButton>
        </div>
      </div>
    </div>

    <div class="block">
      <div class="block-title">{{ t('AI 场景图 / 模特图') }}</div>
      <template v-if="d.image_ai.connected">
        <div class="thumbs">
          <button v-for="i in realPhotos" :key="i.id" type="button" class="thumb" :class="{ on: (sceneSource ?? realPhotos[0]?.id) === i.id }" :aria-label="t('用这张图生成')" @click="sceneSource = i.id"><img :src="i.url" alt=""></button>
        </div>
        <div class="inline">
          <NSelect v-model:value="scene" :options="Object.entries(sceneLabels).map(([value, label]) => ({ label, value }))" size="small" style="width: 160px" />
          <NInput v-model:value="sceneNote" size="small" maxlength="300" :placeholder="t('补充要求（可选），例如：海边、暖色调')" style="flex: 1; min-width: 160px" />
          <NButton size="small" type="primary" secondary :loading="generating" :disabled="d.image_ai.used >= d.image_ai.quota || !realPhotos.length" @click="generateScene">{{ t('生成') }}</NButton>
        </div>
        <p class="hint">{{ t('商品本身保持实拍的样子，只生成周围的场景。本月已用 {0} / {1} 张。', [d.image_ai.used, d.image_ai.quota]) }}</p>
      </template>
      <p v-else class="hint">{{ t('在「内容工坊 › 品牌规范」填写图片生成服务的 API Key 后可用。') }}</p>
    </div>

    <div v-if="d.versions.length" class="block">
      <div class="block-title">{{ t('历史版本') }}</div>
      <div v-for="v in d.versions.slice(0, 6)" :key="v.id" class="version">
        <div class="v-info">
          <span class="v-title">{{ v.title || '—' }}</span>
          <span class="hint">{{ langName(v.locale) }} · {{ reasonLabel[v.reason] ?? v.reason }} · {{ formatDate(v.created_at) }}</span>
        </div>
        <NButton size="tiny" secondary @click="restore(v)">{{ t('恢复') }}</NButton>
      </div>
    </div>
  </NCard>

  <NModal v-model:show="show" preset="card" :title="t('AI 改写建议')" style="max-width: 980px">
    <div class="compare">
      <div class="col">
        <div class="col-title">{{ t('现在') }}</div>
        <div class="field-label">{{ t('标题') }}</div>
        <p class="now">{{ cur?.title || t('（还没有）') }}</p>
        <div class="field-label">{{ t('描述') }}</div>
        <div class="now html" v-html="cur?.description || t('（还没有）')" />
        <div class="field-label">SEO</div>
        <p class="now">{{ cur?.seo_title || '—' }}<br>{{ cur?.seo_description || '—' }}</p>
      </div>
      <div class="col">
        <div class="col-title">{{ t('建议（可以修改后再采纳）') }}</div>
        <div class="field-label">{{ t('标题') }}</div>
        <NInput v-model:value="sug.title" maxlength="255" show-count />
        <div class="field-label">{{ t('描述（HTML）') }}</div>
        <NInput v-model:value="sug.description" type="textarea" :autosize="{ minRows: 8, maxRows: 18 }" />
        <div class="field-label">{{ t('SEO 标题 / 描述') }}</div>
        <NInput v-model:value="sug.seo_title" maxlength="70" show-count />
        <NInput v-model:value="sug.seo_description" type="textarea" maxlength="320" show-count :autosize="{ minRows: 2 }" style="margin-top: 6px" />
        <div v-if="sug.notes.length" class="notes">
          <NTag v-for="(n, i) in sug.notes" :key="i" type="warning" size="small" :bordered="false">{{ n }}</NTag>
        </div>
      </div>
    </div>
    <template #footer>
      <div class="foot">
        <NButton @click="show = false">{{ t('取消') }}</NButton>
        <NButton type="primary" :loading="applying" @click="accept">{{ t('采纳') }}</NButton>
      </div>
    </template>
  </NModal>
</template>

<style scoped>
.thumbs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
.thumb { width: 44px; height: 44px; padding: 0; border: 2px solid transparent; border-radius: 6px; overflow: hidden; background: #F4F6F5; cursor: pointer; }
.thumb.on { border-color: var(--brand-600, #0E8A5F); }
.thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.link { color: var(--brand-700); font-size: 13px; }
.score-row { display: flex; gap: 16px; align-items: center; }
.score { font-size: 20px; font-weight: 700; }
.groups { flex: 1; display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 6px 14px; }
.group { display: flex; justify-content: space-between; font-size: 13px; border-bottom: 1px dashed var(--border); padding-bottom: 3px; }
.g-label { color: var(--ink-muted); }
.g-pts { font-variant-numeric: tabular-nums; color: #b42318; }
.g-pts.full { color: var(--brand-700); }
.issues { margin: 12px 0 0; padding-left: 18px; font-size: 13px; line-height: 1.7; color: var(--ink); }
.block { border-top: 1px solid var(--border); margin-top: 14px; padding-top: 12px; }
.block-title { font-weight: 600; font-size: 14px; margin-bottom: 8px; }
.inline { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.hint { color: var(--ink-muted); font-size: 12px; margin: 6px 0 0; line-height: 1.6; }
.processed { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.pimg { display: grid; justify-items: center; gap: 2px; }
.pimg img { width: 72px; height: 96px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border); }
.version { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 6px 0; }
.v-info { display: grid; min-width: 0; }
.v-title { font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.compare { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.col-title { font-weight: 600; margin-bottom: 8px; }
.field-label { font-size: 12px; color: var(--ink-muted); margin: 10px 0 4px; }
.now { margin: 0; font-size: 14px; line-height: 1.6; }
.now.html { max-height: 340px; overflow: auto; font-size: 13px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; }
.notes { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
@media (max-width: 760px) { .compare { grid-template-columns: 1fr; } }
</style>
