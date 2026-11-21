<script setup lang="ts">
/**
 * One lead: why it scored what it did, every way to reach them, an AI-written first email in
 * their language (with a Chinese translation), and the follow-up timeline.
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  NButton, NDrawer, NPopover, NDrawerContent, NEmpty, NIcon, NInput, NSelect, NSkeleton, NSpin, NTab, NTabs, NTooltip, useMessage,
} from 'naive-ui'
import {
  AlarmOutline, CallOutline, CopyOutline, GlobeOutline, LocationOutline, LogoWhatsapp, MailOutline, OpenOutline, RefreshOutline, SparklesOutline, Star, StarOutline,
  BusinessOutline, ChatbubbleEllipsesOutline, CreateOutline,
} from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { formatDate } from '@/api/orders'
import { SEGMENT_LABELS, SOCIAL_LABELS, STATUS_LABELS, STATUS_OPTIONS, dueText, emailBadge, flag, type LeadDetail, type LeadStatus } from '@/api/leads'
import ScoreRing from './ScoreRing.vue'
import { t } from '@/i18n'

const props = defineProps<{ id: number | null; aiReady: boolean; countryName: (c: string | null) => string }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void; (e: 'settings'): void }>()
const message = useMessage()
const router = useRouter()

const lead = ref<LeadDetail | null>(null)
const tab = ref<'overview' | 'outreach' | 'timeline'>('overview')
const busy = ref<'' | 'recheck' | 'analyze' | 'convert'>('')
interface Draft { language: string; subject: string; body: string; subject_zh: string; body_zh: string; ai: boolean }
const draft = ref<Draft | null>(null)

watch(() => props.id, async (id) => {
  lead.value = null
  draft.value = null
  tab.value = 'overview'
  if (id) lead.value = (await http.get(`/leads/${id}`)).data.data
}, { immediate: true })

async function patch(body: Record<string, unknown>) {
  try {
    lead.value = (await http.patch(`/leads/${lead.value!.id}`, body)).data.data
    emit('changed')
  } catch (e) { message.error(errorMessage(e)) }
}
async function act(kind: 'recheck' | 'analyze') {
  busy.value = kind
  try {
    lead.value = (await http.post(`/leads/${lead.value!.id}/${kind}`)).data.data
    emit('changed')
    message.success(kind === 'analyze' ? t('AI 分析完成') : t('已重新读取官网'))
  } catch (e) { message.error(errorMessage(e)) } finally { busy.value = '' }
}
async function convert() {
  busy.value = 'convert'
  try {
    const { data } = await http.post(`/leads/${lead.value!.id}/convert`)
    message.success(t('已转为 B2B 公司'))
    emit('changed')
    router.push({ name: 'companies', query: { open: data.company_id } })
  } catch (e) { message.error(errorMessage(e)) } finally { busy.value = '' }
}

function copy(text: string) {
  navigator.clipboard.writeText(text).then(() => message.success(t('已复制')), () => message.error(t('复制失败')))
}
const mapUrl = computed(() => {
  const l = lead.value
  if (!l) return ''
  if (l.lat && l.lng) return `https://www.google.com/maps/search/?api=1&query=${l.lat},${l.lng}`
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent([l.name, l.address, l.city].filter(Boolean).join(' '))}`
})
const waUrl = computed(() => {
  const l = lead.value
  if (!l) return null
  if (l.socials.whatsapp) return l.socials.whatsapp
  const num = (l.phone ?? '').replace(/[^\d]/g, '')
  return num.length >= 8 ? `https://wa.me/${num}` : null
})

// ---- Outreach ----
const drafting = ref(false)
const showZh = ref(false)
const language = ref('')
const languages = [
  { label: t('自动（对方国家语言）'), value: '' }, { label: 'English', value: 'en' }, { label: 'Español', value: 'es' }, { label: 'Português', value: 'pt' },
  { label: 'Deutsch', value: 'de' }, { label: 'Français', value: 'fr' }, { label: 'Italiano', value: 'it' }, { label: 'Nederlands', value: 'nl' },
  { label: 'Polski', value: 'pl' }, { label: 'Türkçe', value: 'tr' }, { label: 'Русский', value: 'ru' }, { label: 'Bahasa Indonesia', value: 'id' },
  { label: 'Tiếng Việt', value: 'vi' }, { label: 'العربية', value: 'ar' },
]
async function generate() {
  drafting.value = true
  try {
    draft.value = (await http.post(`/leads/${lead.value!.id}/outreach`, { language: language.value || null }, { timeout: 120000 })).data.data
  } catch (e) { message.error(errorMessage(e)) } finally { drafting.value = false }
}
const toEmail = computed(() => lead.value?.emails[0] ?? '')
function sendByMail() {
  if (!draft.value) return
  const url = `mailto:${encodeURIComponent(toEmail.value)}?subject=${encodeURIComponent(draft.value.subject)}&body=${encodeURIComponent(draft.value.body)}`
  window.open(url, '_self')
  logActivity('email', `${draft.value.subject}\n\n${draft.value.body}`, false)
}
function copyEmail() {
  if (!draft.value) return
  copy(`${draft.value.subject}\n\n${draft.value.body}`)
}

// ---- Timeline ----
const noteText = ref('')
async function logActivity(type: 'note' | 'email' | 'call' | 'whatsapp', body?: string, notify = true) {
  try {
    lead.value = (await http.post(`/leads/${lead.value!.id}/activities`, { type, body: body ?? (noteText.value || null) })).data.data
    if (type === 'note') noteText.value = ''
    emit('changed')
    if (notify) message.success(t('已记录'))
  } catch (e) { message.error(errorMessage(e)) }
}
// ---- Follow-up reminder ----
const showRemind = ref(false)
const due = computed(() => dueText(lead.value?.next_follow_up_at ?? null))
function remindIn(days: number | null) {
  showRemind.value = false
  if (days === null) return patch({ next_follow_up_at: null })
  const d = new Date()
  d.setDate(d.getDate() + days)
  d.setHours(9, 0, 0, 0)
  patch({ next_follow_up_at: d.toISOString() })
}
const today = new Date(Date.now() - new Date().getTimezoneOffset() * 6e4).toISOString().slice(0, 10)
function remindAt(day: string) {   // native date input: "2026-11-30", reminded at 9:00 local time
  showRemind.value = false
  if (day) patch({ next_follow_up_at: new Date(`${day}T09:00:00`).toISOString() })
}

const ACT_LABELS: Record<string, string> = { reminder: t('设置跟进提醒'), created: t('加入线索库'), status: t('状态改为'), note: t('备注'), email: t('发送开发信'), call: t('电话联系'), whatsapp: t('WhatsApp 联系') }
function actText(a: { type: string; body: string | null }) {
  if (a.type === 'reminder') return `${ACT_LABELS.reminder}：${a.body}`
  if (a.type === 'status') return `${ACT_LABELS.status}「${STATUS_LABELS[a.body as LeadStatus] ?? a.body}」`
  return ACT_LABELS[a.type] ?? a.type
}
const reasonsOk = (r: string) => !/没有看到/.test(r)
</script>

<template>
  <NDrawer :show="!!id" :width="640" placement="right" @update:show="(v: boolean) => !v && emit('close')">
    <NDrawerContent closable :native-scrollbar="false" body-content-style="padding: 0">
      <template #header>
        <div v-if="lead" class="hd">
          <ScoreRing :score="lead.score" :size="56" :ai="lead.ai_scored" />
          <div class="hd-main">
            <div class="hd-name">
              <span>{{ lead.name }}</span>
              <button class="star" :class="{ on: lead.starred }" :aria-label="t('标星')" @click="patch({ starred: !lead.starred })">
                <NIcon size="18"><Star v-if="lead.starred" /><StarOutline v-else /></NIcon>
              </button>
            </div>
            <div class="hd-sub">
              <span class="seg">{{ SEGMENT_LABELS[lead.segment] }}</span>
              <span>{{ flag(lead.country) }} {{ [lead.city, countryName(lead.country)].filter(Boolean).join(' · ') }}</span>
              <span v-if="lead.rating">★ {{ lead.rating }} ({{ lead.reviews_count }})</span>
            </div>
          </div>
        </div>
        <NSkeleton v-else text style="width: 240px" />
      </template>

      <div v-if="!lead" class="pad"><NSkeleton text :repeat="8" /></div>
      <template v-else>
        <div class="bar">
          <NSelect :value="lead.status" :options="STATUS_OPTIONS" size="small" style="width: 128px" @update:value="(v: string) => patch({ status: v })" />
          <NPopover v-model:show="showRemind" trigger="click" placement="bottom-start" :show-arrow="false">
            <template #trigger>
              <button class="remind" :class="{ set: !!due, overdue: due?.overdue }">
                <NIcon size="14"><AlarmOutline /></NIcon>{{ due ? due.text : t('设置跟进提醒') }}
              </button>
            </template>
            <div class="remind-menu">
              <button @click="remindIn(1)">{{ t('明天') }}</button>
              <button @click="remindIn(3)">{{ t('3 天后') }}</button>
              <button @click="remindIn(7)">{{ t('1 周后') }}</button>
              <button @click="remindIn(14)">{{ t('2 周后') }}</button>
              <label class="pick">{{ t('选择日期') }}<input type="date" :min="today" @change="(e: Event) => remindAt((e.target as HTMLInputElement).value)" /></label>
              <button v-if="due" class="clear" @click="remindIn(null)">{{ t('清除提醒') }}</button>
            </div>
          </NPopover>
          <div class="bar-acts">
            <NTooltip><template #trigger>
              <NButton size="small" quaternary :loading="busy === 'recheck'" :disabled="!lead.website" @click="act('recheck')">
                <template #icon><NIcon><RefreshOutline /></NIcon></template>{{ t('重新抓取') }}
              </NButton></template>{{ t('重新读取对方官网，更新联系方式和评分') }}</NTooltip>
            <NButton size="small" quaternary :loading="busy === 'analyze'" @click="aiReady ? act('analyze') : emit('settings')">
              <template #icon><NIcon><SparklesOutline /></NIcon></template>{{ t('AI 分析') }}
            </NButton>
            <NButton size="small" :loading="busy === 'convert'" @click="convert">
              <template #icon><NIcon><BusinessOutline /></NIcon></template>{{ lead.company_id ? t('查看公司') : t('转为 B2B 客户') }}
            </NButton>
          </div>
        </div>

        <NTabs v-model:value="tab" type="line" class="tabs" pane-style="padding: 0">
          <NTab name="overview">{{ t('概览') }}</NTab>
          <NTab name="outreach">{{ t('开发信') }}</NTab>
          <NTab name="timeline">{{ t('跟进记录') }}{{ lead.activities.length > 1 ? ` (${lead.activities.length})` : '' }}</NTab>
        </NTabs>

        <!-- Overview -->
        <div v-if="tab === 'overview'" class="pad">
          <p v-if="lead.summary" class="summary">{{ lead.summary }}</p>

          <div class="sec">{{ t('为什么推荐') }} <span v-if="lead.ai_scored" class="ai-tag"><NIcon size="12"><SparklesOutline /></NIcon> AI</span></div>
          <ul class="reasons">
            <li v-for="(r, i) in lead.score_reasons" :key="i" :class="{ neg: !reasonsOk(r) }">{{ r }}</li>
            <li v-if="!lead.score_reasons.length" class="neg">{{ t('暂无评分依据') }}</li>
          </ul>

          <div class="sec">{{ t('联系方式') }}</div>
          <div class="contacts">
            <div v-for="e in lead.emails" :key="e" class="c-row">
              <NIcon size="16" class="c-ico"><MailOutline /></NIcon>
              <a :href="`mailto:${e}`" class="c-val">{{ e }}</a>
              <NTooltip v-if="emailBadge(lead.email_checks[e])"><template #trigger>
                <span class="eb" :class="emailBadge(lead.email_checks[e])!.tone">{{ emailBadge(lead.email_checks[e])!.label }}</span></template>
                {{ lead.email_checks[e].guessed ? t('官网没有公开邮箱。对方域名可以收信，这是最常用的公司邮箱，建议先打电话确认。')
                  : lead.email_checks[e].mx ? t('对方域名有收信服务器（MX 记录），邮件可以送达。') : t('这个域名没有收信服务器，发过去会被退回。') }}
              </NTooltip>
              <button class="ghost" :aria-label="t('复制')" @click="copy(e)"><NIcon size="14"><CopyOutline /></NIcon></button>
            </div>
            <div v-for="p in [lead.phone, ...lead.phones].filter(Boolean) as string[]" :key="p" class="c-row">
              <NIcon size="16" class="c-ico"><CallOutline /></NIcon>
              <a :href="`tel:${p}`" class="c-val">{{ p }}</a>
              <button class="ghost" :aria-label="t('复制')" @click="copy(p)"><NIcon size="14"><CopyOutline /></NIcon></button>
            </div>
            <div v-if="waUrl" class="c-row">
              <NIcon size="16" class="c-ico"><LogoWhatsapp /></NIcon>
              <a :href="waUrl" target="_blank" rel="noopener" class="c-val" @click="logActivity('whatsapp', undefined, false)">{{ lead.socials.whatsapp ? 'WhatsApp' : t('用这个号码打开 WhatsApp') }}</a>
              <NIcon size="13" class="ext"><OpenOutline /></NIcon>
            </div>
            <div v-if="lead.website" class="c-row">
              <NIcon size="16" class="c-ico"><GlobeOutline /></NIcon>
              <a :href="lead.website" target="_blank" rel="noopener" class="c-val">{{ lead.domain ?? lead.website }}</a>
              <NIcon size="13" class="ext"><OpenOutline /></NIcon>
            </div>
            <div class="c-row">
              <NIcon size="16" class="c-ico"><LocationOutline /></NIcon>
              <a :href="mapUrl" target="_blank" rel="noopener" class="c-val">{{ lead.address || t('在地图上查看') }}</a>
              <NIcon size="13" class="ext"><OpenOutline /></NIcon>
            </div>
            <div v-if="Object.keys(lead.socials).filter((k) => k !== 'whatsapp').length" class="socials">
              <a v-for="(url, k) in lead.socials" v-show="k !== 'whatsapp'" :key="k" :href="url" target="_blank" rel="noopener" class="chip">{{ SOCIAL_LABELS[k] ?? k }}</a>
            </div>
            <p v-if="!lead.emails.length && !lead.phone" class="muted">{{ t('还没有找到联系方式。') }}{{ lead.website ? t('可以点「重新抓取」再试一次，或打开官网查看。') : '' }}</p>
          </div>

          <div class="sec">{{ t('数据') }}</div>
          <div class="facts">
            <div><span>{{ t('官网读取') }}</span><b>{{ { done: t('已完成'), failed: t('未能读取'), pending: t('排队中'), skipped: t('没有官网') }[lead.enrich_status] ?? lead.enrich_status }}</b></div>
            <div v-if="lead.enrich_error"><span>{{ t('原因') }}</span><b>{{ lead.enrich_error }}</b></div>
            <div v-if="lead.category"><span>{{ t('地图类别') }}</span><b>{{ lead.category }}</b></div>
            <div v-if="lead.signals.length"><span>{{ t('网站平台') }}</span><b>{{ lead.signals.join(', ') }}</b></div>
            <div><span>{{ t('来源') }}</span><b>{{ { google: 'Google Maps', osm: 'OpenStreetMap', manual: t('导入网址') }[lead.source] ?? lead.source }}{{ lead.search ? ` · ${lead.search.name}` : '' }}</b></div>
            <div><span>{{ t('加入时间') }}</span><b>{{ formatDate(lead.created_at) }}</b></div>
          </div>

          <div class="sec">{{ t('备注') }}</div>
          <NInput :value="lead.note ?? ''" type="textarea" :autosize="{ minRows: 2, maxRows: 6 }" :placeholder="t('例如：对方主要做 iPhone 维修，月采购约 2000 片')"
            @change="(v: string) => patch({ note: v })" />
        </div>

        <!-- Outreach -->
        <div v-else-if="tab === 'outreach'" class="pad">
          <div class="draft-head">
            <NSelect v-model:value="language" :options="languages" size="small" style="width: 190px" :placeholder="t('语言')" />
            <NButton type="primary" size="small" :loading="drafting" @click="generate">
              <template #icon><NIcon><SparklesOutline /></NIcon></template>{{ draft ? t('重新生成') : t('生成开发信') }}
            </NButton>
          </div>
          <p v-if="!aiReady" class="muted hint">{{ t('当前使用英文模板。') }}<a href="#" @click.prevent="emit('settings')">{{ t('配置 AI') }}</a>{{ t(' 后，会根据对方官网内容用当地语言写个性化开发信。') }}</p>
          <NSpin :show="drafting">
            <NEmpty v-if="!draft" :description="t('根据这家公司的资料和你的公司介绍，生成一封首次联系的开发信')" style="padding: 40px 0" />
            <div v-else class="draft">
              <div class="to">{{ t('收件人') }}：<b>{{ toEmail || t('（没有邮箱，可复制后用 WhatsApp / 表单发送）') }}</b></div>
              <NInput v-model:value="draft.subject" :placeholder="t('主题')" />
              <NInput v-model:value="draft.body" type="textarea" :autosize="{ minRows: 10, maxRows: 22 }" />
              <div v-if="draft.body_zh" class="zh">
                <button class="zh-toggle" @click="showZh = !showZh">{{ showZh ? t('收起中文翻译') : t('查看中文翻译') }}</button>
                <div v-if="showZh" class="zh-body"><b>{{ draft.subject_zh }}</b><p>{{ draft.body_zh }}</p></div>
              </div>
              <div class="draft-acts">
                <NButton @click="copyEmail"><template #icon><NIcon><CopyOutline /></NIcon></template>{{ t('复制') }}</NButton>
                <NButton v-if="waUrl" tag="a" :href="`${waUrl}${waUrl.includes('?') ? '&' : '?'}text=${encodeURIComponent(draft.body)}`" target="_blank" @click="logActivity('whatsapp', draft.body, false)">
                  <template #icon><NIcon><LogoWhatsapp /></NIcon></template>WhatsApp
                </NButton>
                <NButton type="primary" :disabled="!toEmail" @click="sendByMail"><template #icon><NIcon><MailOutline /></NIcon></template>{{ t('用邮箱发送') }}</NButton>
              </div>
              <p class="muted">{{ t('「用邮箱发送」会打开你电脑上的邮件软件，并把这条线索标记为已联系。建议从企业邮箱一对一发送，效果和送达率都比群发好。') }}</p>
            </div>
          </NSpin>
        </div>

        <!-- Timeline -->
        <div v-else class="pad">
          <div class="log">
            <NInput v-model:value="noteText" type="textarea" :autosize="{ minRows: 2, maxRows: 5 }" :placeholder="t('记录沟通情况，例如：已发样品，下周回访')" />
            <div class="log-acts">
              <NButton size="small" @click="logActivity('call')"><template #icon><NIcon><CallOutline /></NIcon></template>{{ t('记一次电话') }}</NButton>
              <NButton size="small" @click="logActivity('whatsapp')"><template #icon><NIcon><ChatbubbleEllipsesOutline /></NIcon></template>WhatsApp</NButton>
              <NButton size="small" type="primary" :disabled="!noteText.trim()" @click="logActivity('note')"><template #icon><NIcon><CreateOutline /></NIcon></template>{{ t('添加备注') }}</NButton>
            </div>
          </div>
          <div class="timeline">
            <div v-for="a in lead.activities" :key="a.id" class="ev">
              <div class="dot" :class="a.type" />
              <div class="ev-main">
                <div class="ev-title">{{ actText(a) }} <span class="muted">{{ formatDate(a.created_at) }}</span></div>
                <div v-if="a.body && a.type !== 'status'" class="ev-body">{{ a.body }}</div>
              </div>
            </div>
          </div>
        </div>
      </template>
    </NDrawerContent>
  </NDrawer>
</template>

<style scoped>
.hd { display: flex; gap: 14px; align-items: center; }
.hd-main { min-width: 0; }
.hd-name { display: flex; align-items: center; gap: 6px; font-family: var(--font-display); font-size: 18px; font-weight: 700; line-height: 1.3; }
.hd-sub { display: flex; flex-wrap: wrap; gap: 4px 12px; margin-top: 4px; font-size: 12.5px; color: var(--ink-muted); font-weight: 400; }
.seg { color: var(--brand-700); font-weight: 600; }
.star { border: 0; background: none; padding: 2px; cursor: pointer; color: #B7C2BC; display: inline-flex; }
.star.on { color: var(--brand-600); }
.bar { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 12px 24px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.bar-acts { display: flex; gap: 4px; flex-wrap: wrap; margin-left: auto; }
.remind { display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 10px; border-radius: 7px; border: 1px dashed #C5CEC9; background: #fff; font: inherit; font-size: 12.5px; color: #5C6963; cursor: pointer; }
.remind:hover { border-color: var(--brand-300); color: var(--brand-700); }
.remind.set { border-style: solid; border-color: var(--brand-100); background: var(--brand-50); color: var(--brand-700); }
.remind.overdue { border-color: var(--brand-500); color: var(--brand-800); font-weight: 600; }
.remind-menu { display: grid; gap: 2px; min-width: 180px; }
.remind-menu button { text-align: left; border: 0; background: none; padding: 6px 8px; border-radius: 6px; font: inherit; font-size: 13px; cursor: pointer; color: var(--ink); }
.remind-menu button:hover { background: var(--surface-muted); }
.remind-menu .clear { color: var(--ink-muted); }
.pick { display: grid; gap: 4px; padding: 6px 8px; font-size: 12px; color: var(--ink-muted); }
.pick input { font: inherit; font-size: 13px; color: var(--ink); border: 1px solid var(--border); border-radius: 6px; padding: 4px 6px; }
.eb { flex: none; font-size: 11px; padding: 1px 6px; border-radius: 4px; background: var(--surface-muted); color: var(--ink-muted); }
.eb.ok { background: var(--brand-50); color: var(--brand-700); }
.eb.warn { background: #fff; color: #5C6963; box-shadow: inset 0 0 0 1px #C5CEC9; }
.tabs { padding: 0 24px; }
.pad { padding: 18px 24px 28px; }
.summary { margin: 0 0 4px; font-size: 14px; line-height: 1.65; color: var(--ink); }
.sec { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--ink-muted); letter-spacing: .04em; margin: 22px 0 10px; }
.pad > .sec:first-child { margin-top: 0; }
.ai-tag { display: inline-flex; align-items: center; gap: 2px; padding: 1px 6px; border-radius: 99px; background: var(--brand-50); color: var(--brand-700); font-size: 11px; }
.reasons { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.reasons li { position: relative; padding-left: 20px; font-size: 13.5px; line-height: 1.5; }
.reasons li::before { content: '✓'; position: absolute; left: 2px; color: var(--brand-600); font-weight: 700; }
.reasons li.neg { color: var(--ink-muted); }
.reasons li.neg::before { content: '–'; color: #B7C2BC; }
.contacts { display: grid; gap: 2px; }
.c-row { display: flex; align-items: center; gap: 10px; min-height: 34px; padding: 0 8px; border-radius: 8px; }
.c-row:hover { background: var(--surface-muted); }
.c-ico { color: var(--brand-600); flex: none; }
.c-val { color: var(--ink); text-decoration: none; font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.c-val:hover { color: var(--brand-700); text-decoration: underline; }
.ext { color: var(--ink-muted); }
.ghost { margin-left: auto; border: 0; background: none; color: var(--ink-muted); cursor: pointer; padding: 4px; border-radius: 6px; display: inline-flex; }
.ghost:hover { background: #E8EEEA; color: var(--ink); }
.socials { display: flex; flex-wrap: wrap; gap: 6px; padding: 6px 8px 0; }
.chip { font-size: 12px; padding: 3px 10px; border-radius: 99px; border: 1px solid var(--border); color: var(--ink); text-decoration: none; }
.chip:hover { border-color: var(--brand-300); color: var(--brand-700); }
.facts { display: grid; gap: 6px; font-size: 13px; }
.facts > div { display: flex; gap: 12px; }
.facts span { width: 76px; flex: none; color: var(--ink-muted); }
.facts b { font-weight: 500; word-break: break-word; }
.muted { color: var(--ink-muted); font-size: 12px; line-height: 1.6; }
.hint { margin: 10px 0 0; }
.hint a { color: var(--brand-700); }
.draft-head { display: flex; justify-content: space-between; gap: 8px; }
.draft { display: grid; gap: 10px; margin-top: 14px; }
.to { font-size: 13px; color: var(--ink-muted); }
.to b { color: var(--ink); font-weight: 500; }
.zh-toggle { border: 0; background: none; color: var(--brand-700); cursor: pointer; padding: 0; font-size: 13px; }
.zh-body { margin-top: 8px; padding: 12px 14px; background: var(--surface-muted); border-radius: 10px; font-size: 13px; line-height: 1.7; }
.zh-body p { margin: 6px 0 0; white-space: pre-wrap; }
.draft-acts { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
.log { display: grid; gap: 8px; }
.log-acts { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
.timeline { margin-top: 20px; display: grid; gap: 0; }
.ev { display: flex; gap: 12px; position: relative; padding-bottom: 16px; }
.ev:not(:last-child)::after { content: ''; position: absolute; left: 4px; top: 14px; bottom: 0; width: 1px; background: var(--border); }
.dot { width: 9px; height: 9px; border-radius: 50%; background: #C5CEC9; margin-top: 5px; flex: none; }
.dot.email, .dot.whatsapp, .dot.call { background: var(--brand-600); }
.dot.status, .dot.reminder { background: var(--brand-300); }
.ev-title { font-size: 13.5px; }
.ev-body { margin-top: 4px; font-size: 13px; color: #3D4943; white-space: pre-wrap; line-height: 1.6; max-height: 160px; overflow: auto; }
</style>
