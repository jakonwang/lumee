<script setup lang="ts">
/** Lead finder settings: data source + AI keys, auto AI analysis, and "about us" used in outreach emails. */
import { reactive, ref, watch } from 'vue'
import { NButton, NForm, NFormItem, NGrid, NGi, NInput, NModal, NSwitch, NTag, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import type { LeadSettings, Sender } from '@/api/leads'
import { t } from '@/i18n'

const props = defineProps<{ show: boolean; settings: LeadSettings }>()
const emit = defineEmits<{ (e: 'update:show', v: boolean): void; (e: 'saved', s: LeadSettings): void }>()
const message = useMessage()

const form = reactive({ google_key: '', anthropic_key: '', anthropic_workspace: '', ai_auto: true, sender: {} as Sender,
  serpapi_key: '', meta_token: '', ig_user_id: '', hunter_key: '' })
const clear = reactive({ google: false, anthropic: false, serpapi: false, meta: false, hunter: false })
const saving = ref(false)
watch(() => props.show, (v) => {
  if (!v) return
  Object.assign(form, { google_key: '', anthropic_key: '', anthropic_workspace: props.settings.anthropic_workspace ?? '', ai_auto: props.settings.ai_auto, sender: { ...props.settings.sender },
    serpapi_key: '', meta_token: '', ig_user_id: props.settings.ig_user_id ?? '', hunter_key: '' })
  Object.assign(clear, { google: false, anthropic: false, serpapi: false, meta: false, hunter: false })
})

async function persist(): Promise<LeadSettings> {
  const { data } = await http.put('/lead-finder/settings', {
    google_key: form.google_key || null, anthropic_key: form.anthropic_key || null,
    clear_google_key: clear.google, clear_anthropic_key: clear.anthropic, anthropic_workspace: form.anthropic_workspace.trim() || null,
    ai_auto: form.ai_auto, sender: form.sender,
    serpapi_key: form.serpapi_key || null, clear_serpapi_key: clear.serpapi,
    meta_token: form.meta_token || null, clear_meta_token: clear.meta, ig_user_id: form.ig_user_id.trim(),
    hunter_key: form.hunter_key || null, clear_hunter_key: clear.hunter,
  })
  emit('saved', data.settings)
  return data.settings
}
async function save() {
  saving.value = true
  try {
    await persist()
    emit('update:show', false)
    message.success(t('已保存'))
  } catch (e) { message.error(errorMessage(e)) } finally { saving.value = false }
}

// "Test connection": saves a newly typed key first, then makes one real request with it
type Service = 'google' | 'anthropic' | 'serpapi' | 'instagram' | 'hunter'
const testing = ref<'' | Service>('')
const result = reactive<Record<string, { ok: boolean; message: string } | null>>({ google: null, anthropic: null, serpapi: null, instagram: null, hunter: null })
async function test(service: Service) {
  testing.value = service
  result[service] = null
  try {
    if (form.google_key || form.anthropic_key || form.serpapi_key || form.meta_token || form.hunter_key
      || form.anthropic_workspace.trim() !== (props.settings.anthropic_workspace ?? '') || form.ig_user_id.trim() !== (props.settings.ig_user_id ?? '')) {
      await persist()
      Object.assign(form, { google_key: '', anthropic_key: '', serpapi_key: '', meta_token: '', hunter_key: '' })
    }
    result[service] = (await http.post('/lead-finder/settings/test', { service }, { timeout: 90000 })).data
  } catch (e) { result[service] = { ok: false, message: errorMessage(e) } } finally { testing.value = '' }
}
</script>

<template>
  <NModal :show="show" preset="card" :title="t('获客设置')" style="width: 680px; max-width: calc(100vw - 32px)" @update:show="emit('update:show', $event)">
    <div class="sec">{{ t('数据来源') }}</div>
    <div class="src">
      <div class="src-head">
        <div><b>Google {{ t('地图') }}</b> <NTag size="tiny" :bordered="false" type="success">{{ t('推荐') }}</NTag></div>
        <span class="state" :class="{ on: settings.google_ready }">{{ settings.google_key_set ? t('已配置') : settings.google_platform ? t('使用平台额度') : t('未配置') }}</span>
      </div>
      <p class="muted">{{ t('覆盖全球的商家资料：电话、官网、评分和评价数，数据最全。需要在 Google Cloud 开通 Places API (New) 并创建 API Key，按调用次数付费。') }}</p>
      <div class="key-row">
        <NInput v-model:value="form.google_key" type="password" show-password-on="click" :placeholder="settings.google_key_set ? t('已保存（留空则不修改）') : 'AIza…'" :disabled="clear.google" />
        <NButton v-if="settings.google_key_set" quaternary size="small" @click="clear.google = !clear.google">{{ clear.google ? t('撤销') : t('移除') }}</NButton>
        <NButton size="small" :loading="testing === 'google'" :disabled="clear.google || (!settings.google_ready && !form.google_key)" @click="test('google')">{{ t('测试连接') }}</NButton>
      </div>
      <p v-if="result.google" class="result" :class="{ ok: result.google.ok }">{{ result.google.message }}</p>
    </div>
    <div class="src">
      <div class="src-head">
        <div><b>OpenStreetMap</b></div>
        <span class="state on">{{ t('免费，无需配置') }}</span>
      </div>
      <p class="muted">{{ t('开源地图的商家数据，适合先体验。欧美城市覆盖较好，电话和官网信息比 Google 少。') }}</p>
    </div>

    <div class="sec">{{ t('官网和社媒渠道') }}</div>
    <div class="src">
      <div class="src-head">
        <div><b>SerpApi</b> <span class="muted">· Google {{ t('搜索') }}</span></div>
        <span class="state" :class="{ on: settings.serp_ready }">{{ settings.serp_ready ? t('已配置') : t('未配置') }}</span>
      </div>
      <p class="muted">{{ t('用 Google 搜索结果找公司官网，以及公开的 Facebook 主页、Instagram、LinkedIn 公司页和 TikTok 账号，读取简介里的联系方式。和「选品雷达」共用同一个 Key（serpapi.com），按搜索次数付费。') }}</p>
      <div class="key-row">
        <NInput v-model:value="form.serpapi_key" type="password" show-password-on="click" :placeholder="settings.serp_ready ? t('已保存（留空则不修改）') : 'SerpApi Key'" :disabled="clear.serpapi" />
        <NButton v-if="settings.serp_ready" quaternary size="small" @click="clear.serpapi = !clear.serpapi">{{ clear.serpapi ? t('撤销') : t('移除') }}</NButton>
        <NButton size="small" :loading="testing === 'serpapi'" :disabled="clear.serpapi || (!settings.serp_ready && !form.serpapi_key)" @click="test('serpapi')">{{ t('测试连接') }}</NButton>
      </div>
      <p v-if="result.serpapi" class="result" :class="{ ok: result.serpapi.ok }">{{ result.serpapi.message }}</p>
    </div>
    <div class="src">
      <div class="src-head">
        <div><b>Instagram</b> <span class="muted">· Business Discovery</span></div>
        <span class="state" :class="{ on: settings.instagram_ready }">{{ settings.instagram_ready ? t('已配置') : t('可选') }}</span>
      </div>
      <p class="muted">{{ t('在 Instagram 上找到的商家，读取它的简介、官网链接和粉丝数（只适用于商业账号和创作者账号）。需要你自己的 Instagram 商业账号 ID 和 Meta 开发者平台的访问令牌。') }}</p>
      <div class="key-row">
        <NInput v-model:value="form.meta_token" type="password" show-password-on="click" :placeholder="settings.meta_token_set ? t('访问令牌已保存（留空则不修改）') : t('Meta 访问令牌')" :disabled="clear.meta" />
        <NInput v-model:value="form.ig_user_id" :placeholder="t('Instagram 账号 ID')" style="max-width: 190px" :disabled="clear.meta" />
        <NButton v-if="settings.meta_token_set" quaternary size="small" @click="clear.meta = !clear.meta">{{ clear.meta ? t('撤销') : t('移除') }}</NButton>
        <NButton size="small" :loading="testing === 'instagram'" :disabled="clear.meta || (!settings.instagram_ready && !(form.meta_token || settings.meta_token_set))" @click="test('instagram')">{{ t('测试连接') }}</NButton>
      </div>
      <p v-if="result.instagram" class="result" :class="{ ok: result.instagram.ok }">{{ result.instagram.message }}</p>
    </div>
    <div class="src">
      <div class="src-head">
        <div><b>Hunter.io</b> <span class="muted">· {{ t('邮箱查找') }}</span></div>
        <span class="state" :class="{ on: settings.hunter_ready }">{{ settings.hunter_ready ? t('已配置') : t('可选') }}</span>
      </div>
      <p class="muted">{{ t('官网没有公开邮箱时，按公司域名查找网上公开过的工作邮箱，包括采购、销售负责人的姓名和职位。按查询次数计费，有免费额度。') }}</p>
      <div class="key-row">
        <NInput v-model:value="form.hunter_key" type="password" show-password-on="click" :placeholder="settings.hunter_key_set ? t('已保存（留空则不修改）') : 'Hunter.io API Key'" :disabled="clear.hunter" />
        <NButton v-if="settings.hunter_key_set" quaternary size="small" @click="clear.hunter = !clear.hunter">{{ clear.hunter ? t('撤销') : t('移除') }}</NButton>
        <NButton size="small" :loading="testing === 'hunter'" :disabled="clear.hunter || (!settings.hunter_ready && !form.hunter_key)" @click="test('hunter')">{{ t('测试连接') }}</NButton>
      </div>
      <p v-if="result.hunter" class="result" :class="{ ok: result.hunter.ok }">{{ result.hunter.message }}</p>
    </div>

    <div class="sec">{{ t('AI 分析与开发信') }}</div>
    <div class="src">
      <div class="src-head">
        <div><b>Claude AI</b></div>
        <span class="state" :class="{ on: settings.ai_ready }">{{ settings.anthropic_key_set ? t('已配置') : settings.anthropic_platform ? t('使用平台额度') : t('未配置') }}</span>
      </div>
      <p class="muted">{{ t('AI 会阅读每家公司的官网，判断它是不是你的目标客户、属于哪类买家，并用对方的语言写个性化开发信（附中文翻译）。') }}</p>
      <div class="key-row">
        <NInput v-model:value="form.anthropic_key" type="password" show-password-on="click" :placeholder="settings.anthropic_key_set ? t('已保存（留空则不修改）') : 'sk-ant-…'" :disabled="clear.anthropic" />
        <NButton v-if="settings.anthropic_key_set" quaternary size="small" @click="clear.anthropic = !clear.anthropic">{{ clear.anthropic ? t('撤销') : t('移除') }}</NButton>
        <NButton size="small" :loading="testing === 'anthropic'" :disabled="clear.anthropic || (!settings.ai_ready && !form.anthropic_key)" @click="test('anthropic')">{{ t('测试连接') }}</NButton>
      </div>
      <div class="ws">
        <NInput v-model:value="form.anthropic_workspace" size="small" placeholder="wrkspc_…" :disabled="clear.anthropic" />
        <span class="muted">{{ t('Workspace ID（可选）：Key 不属于某个工作区时才需要填写，在 console.anthropic.com › Settings › Workspaces 里查看。') }}</span>
      </div>
      <p v-if="result.anthropic" class="result" :class="{ ok: result.anthropic.ok }">{{ result.anthropic.message }}</p>
      <div class="toggle"><div><div>{{ t('搜索完成后自动 AI 分析') }}</div><div class="muted">{{ t('关闭后，只对你手动选中的线索做 AI 分析，更省费用。') }}</div></div><NSwitch v-model:value="form.ai_auto" /></div>
    </div>

    <div class="sec">{{ t('我的公司（用于开发信）') }}</div>
    <NForm label-placement="top" size="small">
      <NGrid :cols="2" :x-gap="12" responsive="screen" item-responsive>
        <NGi span="2 m:1"><NFormItem :label="t('公司名称（英文）')"><NInput v-model:value="form.sender.company" placeholder="Shenzhen XX Technology Co., Ltd." /></NFormItem></NGi>
        <NGi span="2 m:1"><NFormItem :label="t('联系人 / 职位')"><div class="pair"><NInput v-model:value="form.sender.name" placeholder="Fiona Wang" /><NInput v-model:value="form.sender.title" placeholder="Sales Manager" /></div></NFormItem></NGi>
        <NGi span="2"><NFormItem :label="t('主营产品（英文更好）')"><NInput v-model:value="form.sender.products" placeholder="Tempered glass & hydrogel screen protectors, privacy film, camera lens protectors" /></NFormItem></NGi>
        <NGi span="2"><NFormItem :label="t('优势')"><NInput v-model:value="form.sender.advantages" type="textarea" :autosize="{ minRows: 2, maxRows: 4 }" placeholder="Factory direct, 10+ years OEM, 3000+ models in stock, ships in 48h, custom packaging" /></NFormItem></NGi>
        <NGi span="2 m:1"><NFormItem :label="t('起订量 / 合作条件')"><NInput v-model:value="form.sender.moq" placeholder="MOQ 100 pcs per model" /></NFormItem></NGi>
        <NGi span="2 m:1"><NFormItem :label="t('官网')"><NInput v-model:value="form.sender.website" placeholder="https://" /></NFormItem></NGi>
        <NGi span="2 m:1"><NFormItem label="WhatsApp"><NInput v-model:value="form.sender.whatsapp" placeholder="+86 138 0000 0000" /></NFormItem></NGi>
        <NGi span="2 m:1"><NFormItem :label="t('邮箱')"><NInput v-model:value="form.sender.email" placeholder="sales@yourfactory.com" /></NFormItem></NGi>
      </NGrid>
    </NForm>

    <template #footer>
      <div class="foot">
        <span class="muted">{{ t('API Key 加密保存，不会显示在页面上。') }}</span>
        <div class="acts"><NButton @click="emit('update:show', false)">{{ t('取消') }}</NButton><NButton type="primary" :loading="saving" @click="save">{{ t('保存') }}</NButton></div>
      </div>
    </template>
  </NModal>
</template>

<style scoped>
.sec { font-size: 12px; font-weight: 600; color: var(--ink-muted); letter-spacing: .04em; margin: 4px 0 10px; }
.sec:not(:first-child) { margin-top: 20px; }
.src { border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; }
.src-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.src p { margin: 6px 0 0; }
.state { font-size: 12px; color: var(--ink-muted); }
.state.on { color: var(--brand-700); font-weight: 500; }
.key-row { display: flex; gap: 8px; align-items: center; margin-top: 10px; flex-wrap: wrap; }
.key-row > .n-input:first-child { flex: 1 1 220px; }
.toggle { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
.ws { display: grid; grid-template-columns: 200px 1fr; gap: 10px; align-items: center; margin-top: 8px; }
@media (max-width: 560px) { .ws { grid-template-columns: 1fr; } }
.result { margin: 8px 0 0; padding: 8px 10px; border-radius: 8px; font-size: 12.5px; line-height: 1.6; background: var(--surface-muted); color: #3D4943; word-break: break-word; }
.result.ok { background: var(--brand-50); color: var(--brand-800); }
.pair { display: flex; gap: 8px; width: 100%; }
.muted { color: var(--ink-muted); font-size: 12px; line-height: 1.6; }
.foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.acts { display: flex; gap: 8px; }
</style>
