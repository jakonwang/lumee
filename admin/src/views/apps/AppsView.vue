<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NCard, NButton, NSpin, NTag, NEmpty, NDrawer, NDrawerContent, NRate, NInput, useMessage, useDialog } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface AppCard { id: number; name: string; tagline: string | null; description: string | null; icon_url: string | null; developer: string | null; scopes: string[]; rating: number; rating_count: number; pricing?: string | null; embedded?: boolean }
interface Review { id: number; rating: number; body: string | null; store: string | null; developer_reply: string | null; updated_at: string }
interface Installed { id: number; app: AppCard; scopes: string[]; installed_at: string; open_url: string; embedded?: boolean; charges?: { id: number; name: string; price: string; trial_ends_at: string | null; billing_on: string | null; test: boolean }[] }

const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const loading = ref(true)
const installed = ref<Installed[]>([])
const store = ref<(AppCard & { installed: boolean; install_url: string })[]>([])
const scopes = ref<{ key: string; label: string }[]>([])
const monthlySpend = ref('0.00')
const scopeLabel = computed(() => Object.fromEntries(scopes.value.map((s) => [s.key, s.label])))

async function load() {
  try {
    const { data } = await http.get('/apps')
    installed.value = data.installed
    store.value = data.store
    scopes.value = data.scopes
    monthlySpend.value = data.monthly_app_spend ?? '0.00'
  } finally {
    loading.value = false
  }
}

function uninstall(i: Installed) {
  dialog.warning({
    title: t('卸载「{0}」？', [i.app.name]),
    content: t('卸载后应用会立即失去访问权限，它创建的 Webhook 也会被删除。店铺里的商品、订单等数据不受影响。'),
    positiveText: t('卸载'), negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        await http.delete(`/apps/installations/${i.id}`)
        message.success(t('已卸载'))
        await load()
      } catch (e) {
        message.error(errorMessage(e))
      }
    },
  })
}

// Ratings & reviews
const reviewing = ref<AppCard | null>(null)
const reviews = ref<Review[]>([])
const distribution = ref<{ stars: number; count: number }[]>([])
const canReview = ref(false)
const mine = reactive({ rating: 0, body: '', exists: false })
const reviewsLoading = ref(false)
const savingReview = ref(false)

async function openReviews(a: AppCard) {
  reviewing.value = a
  reviewsLoading.value = true
  try {
    const { data } = await http.get(`/apps/${a.id}/reviews`)
    reviews.value = data.reviews
    distribution.value = data.distribution
    canReview.value = data.can_review
    Object.assign(mine, { rating: data.mine?.rating ?? 0, body: data.mine?.body ?? '', exists: !!data.mine })
    Object.assign(a, { rating: data.app.rating, rating_count: data.app.rating_count })
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    reviewsLoading.value = false
  }
}

async function saveReview() {
  if (!reviewing.value || !mine.rating) {
    message.error(t('请先选择星级'))
    return
  }
  savingReview.value = true
  try {
    await http.put(`/apps/${reviewing.value.id}/review`, { rating: mine.rating, body: mine.body })
    message.success(t('评价已发布'))
    await openReviews(reviewing.value)
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    savingReview.value = false
  }
}

async function deleteReview() {
  if (!reviewing.value) return
  try {
    await http.delete(`/apps/${reviewing.value.id}/review`)
    message.success(t('评价已删除'))
    await openReviews(reviewing.value)
  } catch (e) {
    message.error(errorMessage(e))
  }
}

function closeReviews(show: boolean) {
  if (!show) reviewing.value = null
}

const maxCount = computed(() => Math.max(1, ...distribution.value.map((d) => d.count)))
const initial = (name: string) => name.slice(0, 1).toUpperCase()
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="head">
      <div>
        <div class="eyebrow">Apps</div>
        <h1 class="page-title">{{ t('应用') }}</h1>
        <p class="page-sub">{{ t('安装第三方应用，扩展店铺功能。应用只能访问你授权的数据，随时可以卸载。') }}</p>
      </div>
      <NButton quaternary @click="router.push({ name: 'partner-apps' })">{{ t('开发自己的应用') }} →</NButton>
    </div>

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else>
      <NCard :title="t('已安装')" size="small">
        <template v-if="Number(monthlySpend) > 0" #header-extra><span class="muted">{{ t('应用月费合计 US${0}', [monthlySpend]) }}</span></template>
        <div v-if="installed.length" class="list">
          <div v-for="i in installed" :key="i.id" class="row">
            <div class="icon"><img v-if="i.app.icon_url" :src="i.app.icon_url" alt=""><span v-else>{{ initial(i.app.name) }}</span></div>
            <div class="grow">
              <strong>{{ i.app.name }}</strong>
              <div class="tags"><NTag v-for="s in i.scopes" :key="s" size="tiny" :bordered="false">{{ scopeLabel[s] ?? s }}</NTag></div>
              <div class="muted">{{ t('安装于 {0}', [formatDate(i.installed_at)]) }}</div>
              <div v-for="c in i.charges ?? []" :key="c.id" class="muted charge">
                {{ c.name }} · US${{ c.price }} / {{ t('30 天') }}
                <template v-if="c.test"> · {{ t('测试') }}</template>
                <template v-else-if="c.trial_ends_at && new Date(c.trial_ends_at) > new Date()"> · {{ t('试用到 {0}', [formatDate(c.trial_ends_at)]) }}</template>
                <template v-else-if="c.billing_on"> · {{ t('下次扣费 {0}', [formatDate(c.billing_on)]) }}</template>
              </div>
            </div>
            <NButton v-if="i.embedded" size="small" @click="router.push({ name: 'app-embedded', params: { id: i.id } })">{{ t('打开') }}</NButton>
            <a v-else :href="i.open_url" target="_blank" rel="noopener"><NButton size="small">{{ t('打开') }}</NButton></a>
            <NButton size="small" quaternary type="error" @click="uninstall(i)">{{ t('卸载') }}</NButton>
          </div>
        </div>
        <NEmpty v-else :description="t('还没有安装应用')" style="padding: 20px 0" />
      </NCard>

      <div>
        <h2 class="section">{{ t('应用市场') }}</h2>
        <div v-if="store.length" class="grid">
          <NCard v-for="a in store" :key="a.id" size="small" class="app">
            <div class="app-head">
              <div class="icon"><img v-if="a.icon_url" :src="a.icon_url" alt=""><span v-else>{{ initial(a.name) }}</span></div>
              <div class="grow">
                <strong>{{ a.name }}</strong>
                <div class="muted">{{ a.developer }}</div>
                <button type="button" class="stars" @click="openReviews(a)">
                  <template v-if="a.rating_count"><NRate :value="a.rating" readonly allow-half size="small" /> <span>{{ a.rating.toFixed(1) }} · {{ t('{0} 条评价', [a.rating_count]) }}</span></template>
                  <span v-else>{{ t('暂无评价') }}</span>
                </button>
              </div>
            </div>
            <p class="tagline">{{ a.tagline || a.description }}</p>
            <div v-if="a.pricing" class="muted pricing">{{ a.pricing }}</div>
            <div class="foot">
              <NButton size="small" quaternary @click="openReviews(a)">{{ t('评价') }}</NButton>
              <NTag v-if="a.installed" size="small" :bordered="false" type="success">{{ t('已安装') }}</NTag>
              <a v-else :href="a.install_url" target="_blank" rel="noopener"><NButton size="small" type="primary">{{ t('安装') }}</NButton></a>
            </div>
          </NCard>
        </div>
        <NEmpty v-else :description="t('应用市场里还没有应用')" style="padding: 32px 0" />
      </div>
    </template>

    <NDrawer :show="!!reviewing" :width="480" @update:show="closeReviews">
      <NDrawerContent v-if="reviewing" :title="t('「{0}」的评价', [reviewing.name])" closable>
        <div v-if="reviewsLoading" class="center"><NSpin /></div>
        <div v-else class="reviews">
          <div class="summary">
            <div class="big">{{ reviewing.rating_count ? reviewing.rating.toFixed(1) : '–' }}</div>
            <div>
              <NRate :value="reviewing.rating" readonly allow-half />
              <div class="muted">{{ t('{0} 条评价', [reviewing.rating_count]) }}</div>
            </div>
            <div class="bars">
              <div v-for="d in distribution" :key="d.stars" class="bar-row">
                <span>{{ d.stars }}★</span>
                <div class="bar"><div class="fill" :style="{ width: (d.count / maxCount) * 100 + '%' }" /></div>
                <span class="muted">{{ d.count }}</span>
              </div>
            </div>
          </div>

          <NCard size="small" :title="mine.exists ? t('我的评价') : t('写评价')">
            <template v-if="canReview">
              <NRate v-model:value="mine.rating" />
              <NInput v-model:value="mine.body" type="textarea" :autosize="{ minRows: 3, maxRows: 8 }" :maxlength="2000" :placeholder="t('说说这个应用好用在哪里，或者哪里需要改进（选填）')" style="margin-top: 10px" />
              <div class="actions">
                <NButton v-if="mine.exists" quaternary type="error" size="small" @click="deleteReview">{{ t('删除') }}</NButton>
                <NButton type="primary" size="small" :loading="savingReview" @click="saveReview">{{ mine.exists ? t('更新评价') : t('发布评价') }}</NButton>
              </div>
            </template>
            <p v-else class="muted">{{ t('安装过这个应用的店铺才能评价。') }}</p>
          </NCard>

          <div v-for="r in reviews" :key="r.id" class="review">
            <div class="review-head"><NRate :value="r.rating" readonly size="small" /><strong>{{ r.store }}</strong><span class="muted">{{ formatDate(r.updated_at) }}</span></div>
            <p v-if="r.body">{{ r.body }}</p>
            <div v-if="r.developer_reply" class="reply"><span class="muted">{{ t('开发者回复') }}</span><p>{{ r.developer_reply }}</p></div>
          </div>
          <NEmpty v-if="!reviews.length" :description="t('还没有评价')" />
        </div>
      </NDrawerContent>
    </NDrawer>
  </div>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.page-title { margin: 0; }
.page-sub { margin: 4px 0 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.list { display: grid; }
.row { display: flex; align-items: center; gap: 12px; padding: 10px 0; }
.row + .row { border-top: 1px solid var(--border); }
.row a { text-decoration: none; }
.icon { width: 40px; height: 40px; border-radius: 10px; overflow: hidden; background: var(--brand-50, #EAF7F1); color: var(--brand-700, #0A7350); display: grid; place-items: center; font-weight: 600; flex: none; }
.icon img { width: 100%; height: 100%; object-fit: cover; }
.grow { flex: 1; min-width: 0; display: grid; gap: 3px; }
.tags { display: flex; flex-wrap: wrap; gap: 4px; }
.muted { color: var(--ink-muted); font-size: 12px; }
.section { font-size: 16px; margin: 4px 0 12px; }
.grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.app-head { display: flex; gap: 12px; align-items: center; }
.tagline { font-size: 13px; color: var(--ink-muted); line-height: 1.6; margin: 10px 0; min-height: 42px; }
.foot { display: flex; justify-content: flex-end; align-items: center; gap: 6px; }
.stars { all: unset; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ink-muted); }
.reviews { display: grid; gap: 16px; }
.summary { display: grid; grid-template-columns: auto auto 1fr; gap: 16px; align-items: center; }
.big { font-size: 36px; font-weight: 600; }
.bars { display: grid; gap: 3px; font-size: 12px; }
.bar-row { display: grid; grid-template-columns: 24px 1fr 24px; gap: 6px; align-items: center; }
.bar { height: 6px; border-radius: 3px; background: var(--border); overflow: hidden; }
.fill { height: 100%; background: var(--brand-600, #0E8A5F); border-radius: 3px; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
.review { border-top: 1px solid var(--border); padding-top: 12px; }
.review-head { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.review p { margin: 6px 0 0; font-size: 13px; line-height: 1.6; white-space: pre-line; }
.reply { margin-top: 8px; padding: 8px 10px; background: var(--brand-50, #EAF7F1); border-radius: 8px; }
.foot a { text-decoration: none; }
@media (max-width: 900px) { .grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }
.charge { color: var(--brand-700, #0A7350); }
.pricing { margin: -4px 0 8px; }
</style>
