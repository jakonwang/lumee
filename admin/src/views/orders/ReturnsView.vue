<script setup lang="ts">
/** Orders › Returns: approve or decline buyers' requests, mark items received, then refund from the order. */
import { onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NButton, NCard, NEmpty, NInput, NInputNumber, NModal, NSkeleton, NSwitch, NTabs, NTab, NTag, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { t } from '@/i18n'

interface Ret {
  id: number; status: 'requested' | 'approved' | 'declined' | 'completed'; reason_label: string; customer_note: string | null
  merchant_note: string | null; return_instructions: string | null; created_at: string; decided_at: string | null
  order: { id: number; name: string; email: string; currency: string }
  items: { order_item_id: number; title: string | null; variant_title: string | null; image: string | null; quantity: number; price: string | null }[]
}

const router = useRouter()
const message = useMessage()
const status = ref('requested')
const rows = ref<Ret[] | null>(null)
const counts = ref<Record<string, number>>({})
const settings = reactive({ enabled: true, window_days: 30, instructions: '' })

async function load() {
  const { data } = await http.get('/returns', { params: { status: status.value === 'all' ? undefined : status.value } })
  rows.value = data.data
  counts.value = data.counts
  Object.assign(settings, data.settings)
}
watch(status, load)
onMounted(load)

const statusTag: Record<Ret['status'], { label: string; type: 'warning' | 'info' | 'default' | 'success' }> = {
  requested: { label: t('待处理'), type: 'warning' }, approved: { label: t('已同意'), type: 'info' },
  declined: { label: t('已拒绝'), type: 'default' }, completed: { label: t('已收到退货'), type: 'success' },
}

// ---- Decide ----
const acting = ref<{ r: Ret; status: 'approved' | 'declined' } | null>(null)
const note = ref('')
const busy = ref(false)
function openDecide(r: Ret, s: 'approved' | 'declined') {
  acting.value = { r, status: s }
  note.value = s === 'approved' ? settings.instructions : ''
}
async function decide(r: Ret, s: string, body: Record<string, unknown> = {}) {
  busy.value = true
  try {
    await http.put(`/returns/${r.id}`, { status: s, ...body })
    message.success(t('已更新'))
    acting.value = null
    load()
  } catch (e) { message.error(errorMessage(e)) } finally { busy.value = false }
}
function submitDecide() {
  if (!acting.value) return
  const { r, status: s } = acting.value
  decide(r, s, s === 'approved' ? { instructions: note.value || undefined } : { note: note.value || undefined })
}

// ---- Policy ----
const showSettings = ref(false)
async function saveSettings() {
  try {
    await http.put('/returns/settings', settings)
    message.success(t('已保存'))
    showSettings.value = false
  } catch (e) { message.error(errorMessage(e)) }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <div class="eyebrow">Orders</div>
        <h1 class="page-title">{{ t('退货') }}</h1>
        <p class="page-sub">{{ t('买家在订单页面提交退货申请。同意后买家会收到退货说明；收到退货后，到订单里退款。') }}</p>
      </div>
      <NButton secondary @click="showSettings = true">{{ t('退货政策') }}</NButton>
    </div>

    <NCard content-style="padding: 0">
      <div class="toolbar">
        <NTabs v-model:value="status" type="line" size="small">
          <NTab name="requested">{{ t('待处理') }}{{ counts.requested ? ` (${counts.requested})` : '' }}</NTab>
          <NTab name="approved">{{ t('已同意') }}</NTab>
          <NTab name="completed">{{ t('已收到退货') }}</NTab>
          <NTab name="declined">{{ t('已拒绝') }}</NTab>
          <NTab name="all">{{ t('全部') }}</NTab>
        </NTabs>
      </div>
      <NSkeleton v-if="rows === null" height="200px" />
      <NEmpty v-else-if="!rows.length" :description="settings.enabled ? t('没有退货申请') : t('退货申请已关闭，可以在「退货政策」里开启')" style="padding: 40px 0" />
      <div v-for="r in rows ?? []" :key="r.id" class="ret">
        <div class="ret-head">
          <div>
            <a class="order" @click="router.push({ name: 'order', params: { id: r.order.id } })">{{ r.order.name }}</a>
            <NTag size="small" :bordered="false" :type="statusTag[r.status].type">{{ statusTag[r.status].label }}</NTag>
            <div class="muted">{{ r.order.email }} · {{ formatDate(r.created_at) }} · {{ t('原因：{0}', [r.reason_label]) }}</div>
          </div>
          <div class="acts">
            <template v-if="r.status === 'requested'">
              <NButton size="small" @click="openDecide(r, 'declined')">{{ t('拒绝') }}</NButton>
              <NButton size="small" type="primary" @click="openDecide(r, 'approved')">{{ t('同意') }}</NButton>
            </template>
            <template v-else-if="r.status === 'approved'">
              <NButton size="small" @click="openDecide(r, 'declined')">{{ t('拒绝') }}</NButton>
              <NButton size="small" type="primary" :loading="busy" @click="decide(r, 'completed')">{{ t('标记已收到') }}</NButton>
            </template>
            <NButton v-else-if="r.status === 'completed'" size="small" secondary @click="router.push({ name: 'order', params: { id: r.order.id } })">{{ t('去订单退款') }}</NButton>
          </div>
        </div>
        <div class="items">
          <div v-for="i in r.items" :key="i.order_item_id" class="item">
            <img v-if="i.image" :src="i.image" alt="">
            <span class="ph" v-else />
            <div class="i-main"><div>{{ i.title }}</div><div class="muted">{{ i.variant_title }}</div></div>
            <div class="muted">× {{ i.quantity }}</div>
            <div class="num">{{ r.order.currency }} {{ i.price }}</div>
          </div>
        </div>
        <p v-if="r.customer_note" class="note"><strong>{{ t('买家说明：') }}</strong>{{ r.customer_note }}</p>
        <p v-if="r.status === 'approved' && r.return_instructions" class="note muted-note">{{ t('退货说明：') }}{{ r.return_instructions }}</p>
        <p v-if="r.status === 'declined' && r.merchant_note" class="note muted-note">{{ t('拒绝原因：') }}{{ r.merchant_note }}</p>
      </div>
    </NCard>

    <NModal :show="!!acting" preset="card" style="width: 480px; max-width: 94vw" :title="acting?.status === 'approved' ? t('同意退货') : t('拒绝退货')" @update:show="(v: boolean) => !v && (acting = null)">
      <p class="muted">{{ acting?.status === 'approved' ? t('会发邮件告诉买家怎么把商品寄回来。') : t('会发邮件通知买家，可以写明原因。') }}</p>
      <NInput v-model:value="note" type="textarea" :autosize="{ minRows: 4 }" maxlength="1000"
        :placeholder="acting?.status === 'approved' ? t('退货地址、寄回方式、注意事项') : t('拒绝原因（可选）')" />
      <div class="modal-actions">
        <NButton @click="acting = null">{{ t('取消') }}</NButton>
        <NButton :type="acting?.status === 'approved' ? 'primary' : 'error'" :loading="busy" @click="submitDecide">{{ acting?.status === 'approved' ? t('同意并通知买家') : t('拒绝并通知买家') }}</NButton>
      </div>
    </NModal>

    <NModal v-model:show="showSettings" preset="card" :title="t('退货政策')" style="width: 500px; max-width: 94vw">
      <div class="form">
        <div class="row"><span>{{ t('允许买家在线申请退货') }}</span><NSwitch v-model:value="settings.enabled" /></div>
        <div class="row"><span>{{ t('签收后多少天内可以申请') }}</span><NInputNumber v-model:value="settings.window_days" :min="1" :max="365" style="width: 120px"><template #suffix>{{ t('天') }}</template></NInputNumber></div>
        <div>
          <div class="label">{{ t('默认退货说明（同意时自动填入，可再修改）') }}</div>
          <NInput v-model:value="settings.instructions" type="textarea" :autosize="{ minRows: 3 }" maxlength="1000" :placeholder="t('退货地址、寄回方式、注意事项')" />
        </div>
      </div>
      <div class="modal-actions">
        <NButton @click="showSettings = false">{{ t('取消') }}</NButton>
        <NButton type="primary" @click="saveSettings">{{ t('保存') }}</NButton>
      </div>
    </NModal>
  </div>
</template>

<style scoped>
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.toolbar { padding: 8px 16px 0; border-bottom: 1px solid var(--border); }
.ret { padding: 16px; border-bottom: 1px solid var(--border); }
.ret:last-child { border-bottom: 0; }
.ret-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.order { font-weight: 600; cursor: pointer; margin-right: 8px; color: var(--ink); }
.order:hover { color: var(--brand-700); }
.acts { display: flex; gap: 8px; align-items: flex-start; }
.muted { color: var(--ink-muted); font-size: 12px; }
.items { margin-top: 12px; display: grid; gap: 8px; }
.item { display: flex; align-items: center; gap: 12px; font-size: 13px; }
.item img, .ph { width: 40px; height: 40px; border-radius: 6px; object-fit: cover; background: var(--surface-muted); flex: none; }
.i-main { flex: 1; }
.num { width: 110px; text-align: right; font-variant-numeric: tabular-nums; }
.note { margin: 12px 0 0; font-size: 13px; padding: 10px 12px; background: var(--surface-muted); border-radius: 8px; white-space: pre-line; }
.muted-note { color: var(--ink-muted); }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.form { display: grid; gap: 16px; }
.row { display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 14px; }
.label { font-size: 13px; margin-bottom: 6px; }
</style>
