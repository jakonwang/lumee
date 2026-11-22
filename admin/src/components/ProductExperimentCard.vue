<script setup lang="ts">
/**
 * Product page › A/B test: try another main image or title on half of the visitors and compare
 * add-to-cart rates. When the difference is clear (enough views, p < 0.05) the winner can be
 * applied: B becomes the real title / main image (the old title stays in the version history).
 */
import { computed, onMounted, ref } from 'vue'
import { NButton, NCard, NInput, NProgress, NRadioButton, NRadioGroup, NTag, useDialog, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

const props = defineProps<{ productId: number; images: { id?: number; url: string }[]; title: string; dirty: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const message = useMessage()
const dialog = useDialog()

interface Stats { a_rate: number; b_rate: number; uplift: number | null; p_value: number | null; ready: boolean; leader: 'a' | 'b' | null }
interface Exp {
  id: number; kind: 'image' | 'title'; status: 'running' | 'finished' | 'stopped'; winner: 'a' | 'b' | null; a: string | null; b: string | null
  a_views: number; a_carts: number; b_views: number; b_carts: number; started_at: string; ended_at: string | null; stats: Stats; min_views: number
}
const list = ref<Exp[]>([])
const loaded = ref(false)
async function load() {
  list.value = (await http.get(`/testing/products/${props.productId}`)).data.data.experiments
  loaded.value = true
}
onMounted(load)
const running = computed(() => list.value.find((e) => e.status === 'running') ?? null)
const past = computed(() => list.value.filter((e) => e.status !== 'running').slice(0, 3))

const kind = ref<'image' | 'title'>('image')
const titleB = ref('')
const imageB = ref<number | null>(null)
const others = computed(() => props.images.slice(1).filter((i) => i.id))
const starting = ref(false)
async function start() {
  if (props.dirty) return message.warning(t('请先保存商品'))
  starting.value = true
  try {
    list.value = (await http.post(`/products/${props.productId}/experiments`, { kind: kind.value, b_value: kind.value === 'title' ? titleB.value : String(imageB.value ?? '') })).data.data
    message.success(t('测试已开始，一半访客会看到新版本'))
    titleB.value = ''
    imageB.value = null
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    starting.value = false
  }
}
function finish(winner: 'a' | 'b' | null) {
  const e = running.value!
  dialog.warning({
    title: winner === 'b' ? t('采用 B 并结束测试？') : winner === 'a' ? t('保留 A 并结束测试？') : t('停止测试？'),
    content: winner === 'b' ? (e.kind === 'title' ? t('B 会成为商品的正式标题，原标题会存入历史版本。') : t('B 会成为商品的主图。')) : t('商品保持现在的样子。'),
    positiveText: t('确定'), negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        list.value = (await http.post(`/experiments/${e.id}/finish`, { winner })).data.data
        if (winner === 'b') emit('changed')
      } catch (err) {
        message.error(errorMessage(err))
      }
    },
  })
}
const progress = (e: Exp) => Math.min(100, Math.round(Math.min(e.a_views, e.b_views) / e.min_views * 100))
</script>

<template>
  <NCard v-if="loaded" :title="t('A/B 测试')" size="small">
    <template v-if="running">
      <div class="muted small">{{ running.kind === 'title' ? t('标题测试') : t('主图测试') }} · {{ t('开始于 {0}', [formatDate(running.started_at)]) }}</div>
      <div class="arms">
        <div v-for="arm in (['a', 'b'] as const)" :key="arm" class="arm" :class="{ lead: running.stats.leader === arm }">
          <div class="arm-head"><strong>{{ arm.toUpperCase() }}</strong><span class="muted small">{{ arm === 'a' ? t('现在') : t('新版本') }}</span></div>
          <img v-if="running.kind === 'image'" :src="arm === 'a' ? images[0]?.url : (running.b ?? '')" alt="" class="arm-img">
          <p v-else class="arm-title">{{ arm === 'a' ? title : running.b }}</p>
          <div class="arm-stats">
            <span>{{ t('浏览 {0}', [arm === 'a' ? running.a_views : running.b_views]) }}</span>
            <span>{{ t('加购 {0}', [arm === 'a' ? running.a_carts : running.b_carts]) }}</span>
            <strong>{{ (arm === 'a' ? running.stats.a_rate : running.stats.b_rate) }}%</strong>
          </div>
        </div>
      </div>
      <div class="verdict">
        <template v-if="running.stats.ready">
          <NTag type="success" :bordered="false">{{ running.stats.leader === 'b' ? t('B 胜出') : t('A 胜出') }}</NTag>
          <span class="muted small">{{ t('加购率差 {0}%，结果可信（p = {1}）', [running.stats.uplift ?? '—', running.stats.p_value ?? '—']) }}</span>
        </template>
        <template v-else>
          <span class="muted small">{{ t('收集数据中：每个版本需要至少 {0} 次浏览', [running.min_views]) }}</span>
          <NProgress type="line" :percentage="progress(running)" :show-indicator="false" style="max-width: 200px" />
        </template>
      </div>
      <div class="actions">
        <NButton size="small" type="primary" :secondary="running.stats.leader !== 'b'" @click="finish('b')">{{ t('采用 B') }}</NButton>
        <NButton size="small" secondary @click="finish('a')">{{ t('保留 A') }}</NButton>
        <NButton size="small" quaternary @click="finish(null)">{{ t('停止') }}</NButton>
      </div>
    </template>

    <template v-else>
      <p class="muted">{{ t('让一半访客看到另一张主图或另一个标题，比较加购率，用数据决定用哪个。') }}</p>
      <NRadioGroup v-model:value="kind" size="small">
        <NRadioButton value="image">{{ t('主图') }}</NRadioButton>
        <NRadioButton value="title">{{ t('标题') }}</NRadioButton>
      </NRadioGroup>
      <div v-if="kind === 'image'" class="pick">
        <p v-if="!others.length" class="muted small">{{ t('需要至少两张图片：主图之外，再上传一张候选主图。') }}</p>
        <button v-for="img in others" :key="img.id" type="button" class="pick-img" :class="{ on: imageB === img.id }" :aria-pressed="imageB === img.id" @click="imageB = img.id!">
          <img :src="img.url" alt="">
        </button>
      </div>
      <NInput v-else v-model:value="titleB" maxlength="255" show-count :placeholder="t('新标题（B 版本）')" class="pick" />
      <NButton size="small" type="primary" :loading="starting" :disabled="kind === 'image' ? !imageB : !titleB.trim()" @click="start">{{ t('开始测试') }}</NButton>
    </template>

    <div v-if="past.length" class="past">
      <div v-for="e in past" :key="e.id" class="muted small">
        {{ e.kind === 'title' ? t('标题测试') : t('主图测试') }} · {{ e.winner ? t('{0} 胜出', [e.winner.toUpperCase()]) : t('已停止') }}
        · A {{ e.stats.a_rate }}% / B {{ e.stats.b_rate }}% · {{ e.ended_at ? formatDate(e.ended_at) : '' }}
      </div>
    </div>
  </NCard>
</template>

<style scoped>
.muted { color: var(--ink-muted); font-size: 13px; line-height: 1.6; margin: 0 0 10px; }
.small { font-size: 12px; }
.arms { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 10px 0; }
.arm { border: 1px solid var(--border); border-radius: 10px; padding: 10px; display: grid; gap: 8px; }
.arm.lead { border-color: var(--brand-600); }
.arm-head { display: flex; justify-content: space-between; align-items: center; }
.arm-img { width: 100%; aspect-ratio: 3 / 4; object-fit: cover; border-radius: 6px; background: var(--surface-muted); }
.arm-title { margin: 0; font-size: 14px; line-height: 1.5; }
.arm-stats { display: flex; gap: 10px; font-size: 12px; color: var(--ink-muted); align-items: baseline; flex-wrap: wrap; }
.arm-stats strong { margin-left: auto; color: var(--ink); font-size: 16px; }
.verdict { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.pick { display: flex; gap: 8px; flex-wrap: wrap; margin: 10px 0; }
.pick-img { padding: 0; border: 2px solid transparent; border-radius: 8px; background: none; cursor: pointer; }
.pick-img.on { border-color: var(--brand-600); }
.pick-img img { width: 64px; height: 80px; object-fit: cover; border-radius: 6px; display: block; }
.past { border-top: 1px solid var(--border); margin-top: 12px; padding-top: 8px; display: grid; gap: 2px; }
</style>
