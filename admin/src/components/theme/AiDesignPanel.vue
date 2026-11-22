<script setup lang="ts">
/**
 * Theme editor › AI 助手: describe what you want for the page being designed. Claude answers with a
 * proposal (blocks and settings from the theme — never code) that you compare with the current draft in
 * the preview and apply to the draft only if you like it; the draft before is kept in the version history.
 * Also: an AI review of the page, and a version of this page's design rewritten for another market.
 */
import { computed, ref } from 'vue'
import { NButton, NCheckbox, NInput, NRadioButton, NRadioGroup, NSelect, NTag, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import type { ThemeResponse } from '@/api/theme'
import { t } from '@/i18n'

interface Issue { level: 'error' | 'warning' | 'info'; message: string; block: string | null }
interface Turn {
  kind: 'proposal' | 'review' | 'localized' | 'error'
  request: string
  reply?: string
  summary?: string[]
  changes?: string[]
  issues?: Issue[]
  token?: string
  previewUrl?: string | null
  suggestions?: { title: string; detail: string; block_id: string }[]
  notes?: string[]
  marketId?: number
  state?: 'open' | 'applied' | 'discarded'
}

const props = defineProps<{
  themeId: number
  marketId: number | null
  place: string
  placeLabel: string
  markets: ThemeResponse['markets']
  status: ThemeResponse['ai']
}>()
const emit = defineEmits<{ preview: [url: string | null]; applied: [data: ThemeResponse]; select: [blockId: string]; 'switch-market': [id: number]; status: [s: ThemeResponse['ai']] }>()
const message = useMessage()

const mode = ref<'edit' | 'build'>('edit')
const text = ref('')
const aiBanner = ref(false)
const busy = ref(false)
const turns = ref<Turn[]>([])
const comparing = ref<'before' | 'after'>('after')
const open = computed(() => [...turns.value].reverse().find((x) => x.kind === 'proposal' && x.state === 'open'))
const left = computed(() => Math.max(0, props.status.quota - props.status.used))
const quick = computed(() => mode.value === 'build'
  ? [t('为接下来的节日做一个促销首页，商品放前面'), t('简洁高级的品牌首页，突出新品和买家秀'), t('手机优先的高密度首页：分类入口、限时抢购、热卖')]
  : [t('首屏换成促销风格'), t('把文案改得更有当地感觉'), t('手机上让商品更早出现')])
const localizeTo = ref<number | null>(null)
const localizeOptions = computed(() => props.markets.filter((m) => m.id !== props.marketId).map((m) => ({ label: `${m.name} · ${m.currency}`, value: m.id })))

function remember(s?: ThemeResponse['ai']) { if (s) emit('status', s) }

async function send(request = text.value) {
  if (!request.trim() || busy.value) return
  busy.value = true
  try {
    const { data } = await http.post('/theme/ai/propose', { id: props.themeId, market: props.marketId, place: props.place, mode: mode.value, request, ai_banner: mode.value === 'build' && aiBanner.value })
    turns.value.forEach((x) => { if (x.state === 'open') x.state = 'discarded' })
    turns.value.push({ kind: 'proposal', request, reply: data.reply, summary: data.summary, changes: data.changes, issues: data.issues, token: data.token, previewUrl: data.preview_url, state: 'open' })
    comparing.value = 'after'
    emit('preview', data.preview_url)
    remember(data.ai)
    text.value = ''
  } catch (e) {
    turns.value.push({ kind: 'error', request, reply: errorMessage(e) })
  } finally {
    busy.value = false
  }
}
function compare(v: 'before' | 'after') {
  comparing.value = v
  emit('preview', v === 'after' ? open.value?.previewUrl ?? null : null)
}
async function apply(turn: Turn) {
  busy.value = true
  try {
    const { data } = await http.post<ThemeResponse>('/theme/ai/apply', { token: turn.token })
    turn.state = 'applied'
    emit('preview', null)
    emit('applied', data)
    message.success(t('已应用到草稿，可以继续修改；改坏了可以在「历史版本」里恢复'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}
function discard(turn: Turn) {
  turn.state = 'discarded'
  emit('preview', null)
}
async function review() {
  busy.value = true
  try {
    const { data } = await http.post('/theme/ai/review', { id: props.themeId, market: props.marketId, place: props.place })
    turns.value.push({ kind: 'review', request: t('检查一下「{0}」', [props.placeLabel]), issues: data.issues, suggestions: data.suggestions })
    remember(data.ai)
  } catch (e) {
    turns.value.push({ kind: 'error', request: t('检查'), reply: errorMessage(e) })
  } finally {
    busy.value = false
  }
}
async function localize() {
  if (!localizeTo.value) return
  busy.value = true
  const target = props.markets.find((m) => m.id === localizeTo.value)
  try {
    const { data } = await http.post('/theme/ai/localize', { id: props.themeId, from: props.marketId, to: localizeTo.value })
    turns.value.push({ kind: 'localized', request: t('改写成「{0}」的版本', [target?.name ?? '']), notes: data.notes, marketId: localizeTo.value })
    remember(data.ai)
  } catch (e) {
    turns.value.push({ kind: 'error', request: t('改写成其他市场的版本'), reply: errorMessage(e) })
  } finally {
    busy.value = false
  }
}
const levelType: Record<string, 'error' | 'warning' | 'info'> = { error: 'error', warning: 'warning', info: 'info' }
</script>

<template>
  <div class="ai">
    <div v-if="!status.ready" class="off">{{ t('在「获客雷达 › 设置」填写 Claude API Key 后可以用 AI 装修。') }}</div>
    <template v-else>
      <div class="meta">{{ t('正在改：{0}', [placeLabel]) }} · {{ t('本月还能用 {0} 次', [left]) }}</div>

      <div class="turns">
        <div v-for="(x, i) in turns" :key="i" class="turn">
          <div class="me">{{ x.request }}</div>
          <div v-if="x.kind === 'error'" class="bot err">{{ x.reply }}</div>

          <div v-else-if="x.kind === 'proposal'" class="bot">
            <p v-if="x.reply" class="reply">{{ x.reply }}</p>
            <ul v-if="x.changes?.length" class="list"><li v-for="(c, j) in x.changes" :key="j">{{ c }}</li></ul>
            <div v-if="x.issues?.some((y) => y.level !== 'info')" class="issues">
              <div v-for="(y, j) in x.issues.filter((y) => y.level !== 'info')" :key="j"><NTag size="tiny" :bordered="false" :type="levelType[y.level]">{{ y.level === 'error' ? t('错误') : t('提醒') }}</NTag> {{ y.message }}</div>
            </div>
            <template v-if="x.state === 'open'">
              <NRadioGroup :value="comparing" size="small" class="cmp" @update:value="compare">
                <NRadioButton value="before">{{ t('修改前') }}</NRadioButton>
                <NRadioButton value="after">{{ t('修改后') }}</NRadioButton>
              </NRadioGroup>
              <div class="acts">
                <NButton size="small" type="primary" :loading="busy" @click="apply(x)">{{ t('应用到草稿') }}</NButton>
                <NButton size="small" quaternary @click="discard(x)">{{ t('不要') }}</NButton>
              </div>
            </template>
            <div v-else class="hint">{{ x.state === 'applied' ? t('已应用到草稿') : t('已放弃') }}</div>
          </div>

          <div v-else-if="x.kind === 'review'" class="bot">
            <div v-for="(y, j) in x.issues" :key="'i' + j" class="sug"><NTag size="tiny" :bordered="false" :type="levelType[y.level]">{{ y.level === 'error' ? t('错误') : y.level === 'warning' ? t('提醒') : t('建议') }}</NTag> {{ y.message }}</div>
            <div v-for="(s, j) in x.suggestions" :key="'s' + j" class="sug">
              <strong>{{ s.title }}</strong>
              <div>{{ s.detail }}</div>
              <NButton v-if="s.block_id" size="tiny" quaternary @click="emit('select', s.block_id)">{{ t('去看看') }}</NButton>
            </div>
          </div>

          <div v-else-if="x.kind === 'localized'" class="bot">
            <ul class="list"><li v-for="(n, j) in x.notes" :key="j">{{ n }}</li></ul>
            <p class="hint">{{ t('已保存为这个市场的草稿，检查后再发布。') }}</p>
            <NButton size="small" secondary @click="emit('switch-market', x.marketId!)">{{ t('去看这个市场') }}</NButton>
          </div>
        </div>
        <div v-if="busy" class="bot thinking">{{ t('AI 正在设计…（通常需要 20–60 秒）') }}</div>
      </div>

      <NRadioGroup v-model:value="mode" size="small" class="mode">
        <NRadioButton value="edit">{{ t('修改这个页面') }}</NRadioButton>
        <NRadioButton value="build">{{ t('从头生成') }}</NRadioButton>
      </NRadioGroup>
      <div class="chips">
        <button v-for="q in quick" :key="q" type="button" class="chip" :disabled="busy" @click="send(q)">{{ q }}</button>
      </div>
      <NInput v-model:value="text" type="textarea" :autosize="{ minRows: 2, maxRows: 6 }" maxlength="1000"
        :placeholder="mode === 'build' ? t('例如：给巴西站做母亲节首页，温暖一点，主推项链和刻字款，R$ 200 以内的放前面') : t('例如：首屏太素了，换成促销风格')"
        @keydown.enter.ctrl.prevent="send()" />
      <NCheckbox v-if="mode === 'build' && status.images" v-model:checked="aiBanner" size="small" style="margin-top: 6px">{{ t('用 AI 生成首屏背景图（占用图片额度）') }}</NCheckbox>
      <div class="acts">
        <NButton type="primary" size="small" :loading="busy" :disabled="!text.trim() || left <= 0" @click="send()">{{ mode === 'build' ? t('生成方案') : t('提交') }}</NButton>
        <NButton size="small" secondary :disabled="busy || left <= 0" @click="review">{{ t('AI 检查这个页面') }}</NButton>
      </div>

      <div v-if="localizeOptions.length" class="loc">
        <div class="loc-title">{{ t('做一个其他市场的版本') }}</div>
        <div class="hint" style="margin: 2px 0 6px">{{ t('把当前站点的首页按目标市场的语言和购物习惯重写（不是逐字翻译），保存为那个市场的草稿。') }}</div>
        <div class="acts">
          <NSelect v-model:value="localizeTo" :options="localizeOptions" size="small" :placeholder="t('选择市场')" style="flex: 1" />
          <NButton size="small" secondary :disabled="!localizeTo || busy || left <= 0" @click="localize">{{ t('改写') }}</NButton>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ai { display: grid; gap: 10px; }
.off, .meta { font-size: 12.5px; color: var(--ink-muted); line-height: 1.5; }
.turns { display: grid; gap: 12px; }
.turn { display: grid; gap: 6px; }
.me { justify-self: end; max-width: 92%; background: var(--brand-50); color: var(--brand-800, var(--ink)); padding: 7px 10px; border-radius: 10px 10px 2px 10px; font-size: 13px; line-height: 1.5; }
.bot { border: 1px solid var(--border); border-radius: 10px; padding: 10px; font-size: 13px; line-height: 1.55; display: grid; gap: 6px; }
.bot.err { color: var(--danger); }
.thinking { color: var(--ink-muted); }
.reply { margin: 0; }
.list { margin: 0; padding-inline-start: 18px; color: var(--ink-muted); }
.issues, .sug { display: grid; gap: 4px; font-size: 12.5px; }
.sug { border-top: 1px solid var(--border); padding-top: 6px; }
.cmp { margin-top: 4px; }
.acts { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.hint { font-size: 12px; color: var(--ink-muted); margin: 0; }
.mode { margin-top: 4px; }
.chips { display: flex; gap: 6px; flex-wrap: wrap; }
.chip { border: 1px solid var(--border); background: var(--surface); border-radius: 999px; padding: 4px 10px; font: inherit; font-size: 12px; cursor: pointer; color: var(--ink); text-align: start; }
.chip:hover:not(:disabled) { border-color: var(--brand-300); background: var(--brand-50); }
.loc { border-top: 1px solid var(--border); padding-top: 10px; margin-top: 4px; }
.loc-title { font-size: 13px; font-weight: 600; }
</style>
