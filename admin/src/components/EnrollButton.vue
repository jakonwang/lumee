<script setup lang="ts">
/** "Add to outreach sequence": pick one of the sequences for this audience and enrol the selected leads / creators. */
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { NButton, NModal, NRadio, NRadioGroup, NSpin, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { t } from '@/i18n'

const props = withDefaults(defineProps<{ audience: 'leads' | 'creators'; ids: (number | string)[]; size?: 'tiny' | 'small' | 'medium'; quaternary?: boolean }>(), { size: 'small', quaternary: false })
const emit = defineEmits<{ (e: 'done'): void }>()
const message = useMessage()
const router = useRouter()

interface Seq { id: number; name: string; audience: string; status: string; steps: unknown[] }
const open = ref(false)
const loading = ref(false)
const saving = ref(false)
const sequences = ref<Seq[]>([])
const canSend = ref(true)
const chosen = ref<number | null>(null)

const REASONS: Record<string, string> = {
  no_email: t('没有可用邮箱'), unsubscribed: t('已退订或退信'), needs_consent: t('德国/奥地利需要事先同意'),
  in_sequence: t('已在其他序列中'), already: t('已在这个序列里'),
}

async function show() {
  open.value = true
  loading.value = true
  try {
    const d = (await http.get('/outreach')).data
    sequences.value = d.sequences.filter((s: Seq) => s.audience === props.audience)
    canSend.value = d.mailbox.can_send
    chosen.value = sequences.value.find((s) => s.status === 'active')?.id ?? sequences.value[0]?.id ?? null
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    loading.value = false
  }
}

async function enroll() {
  if (!chosen.value) return
  saving.value = true
  try {
    const r = (await http.post(`/outreach/sequences/${chosen.value}/enroll`, { ids: props.ids })).data
    const skipped = Object.entries(r.skipped as Record<string, number>).map(([k, n]) => `${REASONS[k] ?? k} ${n}`).join('，')
    message.success(t('已加入 {0} 个', [r.enrolled]) + (skipped ? t('；跳过：{0}', [skipped]) : ''), { duration: 6000 })
    open.value = false
    emit('done')
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <NButton :size="size" :quaternary="quaternary" @click="show">{{ t('加入开发信序列') }}</NButton>
  <NModal v-model:show="open" preset="card" :title="t('加入开发信序列（{0} 个）', [ids.length])" style="max-width: 460px">
    <div v-if="loading" class="center"><NSpin size="small" /></div>
    <template v-else>
      <p v-if="!canSend" class="warn">{{ t('还没有设置发信邮箱，加入后要等邮箱设置好才会开始发送。') }}</p>
      <NRadioGroup v-if="sequences.length" v-model:value="chosen" class="list">
        <NRadio v-for="s in sequences" :key="s.id" :value="s.id">{{ s.name }} <span class="muted">· {{ t('{0} 封', [s.steps.length]) }}{{ s.status === 'paused' ? ` · ${t('已暂停')}` : '' }}</span></NRadio>
      </NRadioGroup>
      <p v-else class="muted">{{ t('还没有这类收件人的序列。') }}</p>
      <p class="muted">{{ t('没有公开邮箱的、推测的邮箱、已退订的会自动跳过。') }}</p>
    </template>
    <template #footer>
      <div class="foot">
        <NButton quaternary @click="router.push({ name: 'outreach' })">{{ t('管理序列') }}</NButton>
        <span class="grow" />
        <NButton @click="open = false">{{ t('取消') }}</NButton>
        <NButton type="primary" :loading="saving" :disabled="!chosen" @click="enroll">{{ t('加入') }}</NButton>
      </div>
    </template>
  </NModal>
</template>

<style scoped>
.center { display: flex; justify-content: center; padding: 24px; }
.list { display: grid; gap: 8px; margin-bottom: 12px; }
.muted { color: var(--ink-muted); font-size: 12px; }
.warn { color: var(--danger); font-size: 13px; margin: 0 0 12px; }
.foot { display: flex; gap: 8px; align-items: center; }
.grow { flex: 1; }
</style>
