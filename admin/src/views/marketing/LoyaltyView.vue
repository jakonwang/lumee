<script setup lang="ts">
/** Marketing › Points: rules of the loyalty program, totals, and the customers with the most points. */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NButton, NCard, NEmpty, NInputNumber, NSkeleton, NSwitch, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

interface Data {
  settings: { enabled: boolean; earn_rate: number; redeem_points: number; min_redeem: number }
  currency: string
  stats: { members: number; outstanding: number; outstanding_value: string; earned_30d: number; redeemed_30d: number }
  top: { id: number; name: string; email: string; points: number }[]
}

const router = useRouter()
const message = useMessage()
const data = ref<Data | null>(null)
const form = reactive({ enabled: false, earn_rate: 1, redeem_points: 100, min_redeem: 100 })
const saving = ref(false)

async function load() {
  data.value = (await http.get('/loyalty')).data
  Object.assign(form, data.value!.settings)
}
onMounted(load)

async function save() {
  saving.value = true
  try {
    data.value = (await http.put('/loyalty', form)).data
    message.success(t('已保存'))
  } catch (e) { message.error(errorMessage(e)) } finally { saving.value = false }
}

const cur = computed(() => data.value?.currency ?? '')
// Example for the merchant: what a 100-unit order earns and what that is worth
const example = computed(() => {
  const pts = Math.floor(100 * form.earn_rate)
  const worth = form.redeem_points ? Math.floor((pts * 100) / form.redeem_points) / 100 : 0
  return { pts, worth: worth.toFixed(2), pct: ((worth / 100) * 100).toFixed(1) }
})
</script>

<template>
  <div>
    <div class="eyebrow">Marketing</div>
    <h1 class="page-title">{{ t('积分') }}</h1>
    <p class="page-sub">{{ t('顾客注册账户后，每笔付款订单都能获得积分，积分可以兑换成购物抵扣金，下次购物时使用。') }}</p>

    <NSkeleton v-if="!data" height="300px" />
    <template v-else>
      <div class="stats">
        <div class="stat"><div class="label">{{ t('有积分的顾客') }}</div><div class="value">{{ data.stats.members }}</div></div>
        <div class="stat"><div class="label">{{ t('未兑换积分') }}</div><div class="value">{{ data.stats.outstanding.toLocaleString() }}</div><div class="muted">≈ {{ cur }} {{ data.stats.outstanding_value }}</div></div>
        <div class="stat"><div class="label">{{ t('近 30 天发放') }}</div><div class="value">{{ data.stats.earned_30d.toLocaleString() }}</div></div>
        <div class="stat"><div class="label">{{ t('近 30 天兑换') }}</div><div class="value">{{ data.stats.redeemed_30d.toLocaleString() }}</div></div>
      </div>

      <div class="grid">
        <NCard :title="t('积分规则')" size="small">
          <template #header-extra><NSwitch v-model:value="form.enabled" :aria-label="t('开启积分')" /></template>
          <div class="form">
            <div class="row"><span>{{ t('每消费 1 {0} 获得', [cur]) }}</span><NInputNumber v-model:value="form.earn_rate" :min="0" :max="1000" :step="0.5" style="width: 140px"><template #suffix>{{ t('积分') }}</template></NInputNumber></div>
            <div class="row"><span>{{ t('多少积分抵 1 {0}', [cur]) }}</span><NInputNumber v-model:value="form.redeem_points" :min="1" :max="100000" style="width: 140px"><template #suffix>{{ t('积分') }}</template></NInputNumber></div>
            <div class="row"><span>{{ t('最少兑换') }}</span><NInputNumber v-model:value="form.min_redeem" :min="1" style="width: 140px"><template #suffix>{{ t('积分') }}</template></NInputNumber></div>
            <p class="example">{{ t('举例：一笔 {0} 100 的订单获得 {1} 积分，可以兑换 {0} {2}（相当于返利 {3}%）。', [cur, example.pts, example.worth, example.pct]) }}</p>
            <p class="muted">{{ t('按实际付款金额计算（不含礼品卡抵扣部分）。订单取消时扣回本单积分。兑换后生成顾客专属的抵扣金，可以分多次使用。') }}</p>
            <NButton type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton>
          </div>
        </NCard>
        <NCard :title="t('积分最多的顾客')" size="small" content-style="padding: 0">
          <NEmpty v-if="!data.top.length" :description="t('还没有顾客获得积分')" style="padding: 32px 0" />
          <div v-for="c in data.top" :key="c.id" class="member" @click="router.push({ name: 'customer', params: { id: c.id } })">
            <div><div>{{ c.name }}</div><div class="muted">{{ c.email }}</div></div>
            <div class="pts">{{ c.points.toLocaleString() }}</div>
          </div>
        </NCard>
      </div>
    </template>
  </div>
</template>

<style scoped>
.stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.stat { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 12px 16px; }
.label { font-size: 12px; color: var(--ink-muted); }
.value { font-size: 22px; font-weight: 600; margin-top: 2px; font-variant-numeric: tabular-nums; }
.muted { color: var(--ink-muted); font-size: 12px; }
.grid { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 16px; align-items: start; }
.form { display: grid; gap: 14px; justify-items: start; }
.row { display: flex; justify-content: space-between; align-items: center; gap: 12px; width: 100%; font-size: 14px; }
.example { margin: 0; padding: 10px 12px; background: var(--brand-50); border-radius: 8px; font-size: 13px; width: 100%; box-sizing: border-box; }
.member { display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; border-bottom: 1px solid var(--border); cursor: pointer; font-size: 14px; }
.member:hover { background: var(--surface-muted); }
.member:last-child { border-bottom: 0; }
.pts { font-weight: 600; font-variant-numeric: tabular-nums; }
@media (max-width: 900px) { .grid { grid-template-columns: 1fr; } .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
