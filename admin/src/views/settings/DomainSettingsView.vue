<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { NCard, NInput, NButton, NTag, NSpin, NIcon, useMessage, useDialog } from 'naive-ui'
import { CopyOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import SettingsNav from '@/components/SettingsNav.vue'
import { t } from '@/i18n'

interface Domain { id: number; host: string; type: 'platform' | 'custom'; is_primary: boolean; verified: boolean }

const message = useMessage()
const dialog = useDialog()
const auth = useAuth()
const loading = ref(true)
const adding = ref(false)
const busy = ref<number | null>(null)
const host = ref('')
const domains = ref<Domain[]>([])
const cnameTarget = ref('')
const serverIp = ref<string | null>(null)

function apply(data: { data: Domain[]; dns: { cname_target: string; server_ip: string | null } }) {
  domains.value = data.data
  cnameTarget.value = data.dns.cname_target
  serverIp.value = data.dns.server_ip
}

async function load() {
  loading.value = true
  try {
    apply((await http.get('/domains')).data)
  } finally {
    loading.value = false
  }
}

async function add() {
  adding.value = true
  try {
    apply((await http.post('/domains', { host: host.value })).data)
    host.value = ''
    message.success(t('已添加，请按下方说明设置 DNS 后点击「验证」'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    adding.value = false
  }
}

async function verify(d: Domain) {
  busy.value = d.id
  try {
    const { data } = await http.post(`/domains/${d.id}/verify`)
    apply(data)
    data.verified ? message.success(data.message) : message.warning(data.message, { duration: 8000 })
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    busy.value = null
  }
}

async function makePrimary(d: Domain) {
  busy.value = d.id
  try {
    apply((await http.post(`/domains/${d.id}/primary`)).data)
    await auth.fetchMe()   // "查看店铺" links now use the new domain
    message.success(t('{0} 已设为主域名', [d.host]))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    busy.value = null
  }
}

function remove(d: Domain) {
  dialog.warning({
    title: t('移除 {0}？', [d.host]),
    content: d.is_primary ? t('这是当前的主域名，移除后店铺会改用平台免费域名访问。') : t('移除后这个域名将无法访问店铺。'),
    positiveText: t('移除'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        apply((await http.delete(`/domains/${d.id}`)).data)
        await auth.fetchMe()
        message.success(t('已移除'))
      } catch (e) {
        message.error(errorMessage(e))
      }
    },
  })
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    message.success(t('已复制'))
  } catch {
    message.info(text)
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div>
      <div class="eyebrow">Settings</div>
      <h1 class="page-title">{{ t('设置') }}</h1>
    </div>
    <SettingsNav />

    <div v-if="loading" class="center"><NSpin /></div>
    <template v-else>
      <NCard :title="t('域名')" size="small" content-style="padding: 0">
        <div v-for="d in domains" :key="d.id" class="row">
          <div class="row-main">
            <a :href="`http://${d.host}`" target="_blank" class="host">{{ d.host }}</a>
            <div class="tags">
              <NTag v-if="d.is_primary" size="small" :bordered="false" type="success">{{ t('主域名') }}</NTag>
              <NTag v-if="d.type === 'platform'" size="small" :bordered="false">{{ t('平台免费域名') }}</NTag>
              <NTag v-else-if="d.verified" size="small" :bordered="false" type="success">{{ t('已验证') }}</NTag>
              <NTag v-else size="small" :bordered="false" type="warning">{{ t('待验证') }}</NTag>
            </div>
          </div>
          <div class="row-actions">
            <NButton v-if="!d.verified" size="small" type="primary" :loading="busy === d.id" @click="verify(d)">{{ t('验证') }}</NButton>
            <NButton v-else-if="!d.is_primary" size="small" secondary :loading="busy === d.id" @click="makePrimary(d)">{{ t('设为主域名') }}</NButton>
            <NButton v-if="d.type === 'custom'" size="small" quaternary type="error" @click="remove(d)">{{ t('移除') }}</NButton>
          </div>
        </div>
      </NCard>

      <NCard :title="t('绑定自己的域名')" size="small">
        <form class="add" @submit.prevent="add">
          <NInput v-model:value="host" :placeholder="t('例如 shop.yourbrand.com')" />
          <NButton type="primary" attr-type="submit" :loading="adding" :disabled="!host.trim()">{{ t('添加域名') }}</NButton>
        </form>

        <div class="steps">
          <p class="step-title">{{ t('如何设置 DNS') }}</p>
          <ol>
            <li>{{ t('登录你购买域名的网站（如阿里云、腾讯云、GoDaddy、Namecheap），找到「DNS 解析」。') }}</li>
            <li>
              {{ t('添加一条') }} <strong>CNAME</strong> {{ t('记录：') }}
              <table class="dns">
                <thead><tr><th>{{ t('类型') }}</th><th>{{ t('主机记录') }}</th><th>{{ t('记录值') }}</th></tr></thead>
                <tbody><tr>
                  <td>CNAME</td>
                  <td><code>shop</code><span class="muted">{{ t('（对应 shop.yourbrand.com）') }}</span></td>
                  <td><code>{{ cnameTarget }}</code>
                    <button type="button" class="copy" :aria-label="t('复制')" @click="copy(cnameTarget)"><NIcon size="14"><CopyOutline /></NIcon></button></td>
                </tr></tbody>
              </table>
              <p v-if="serverIp" class="muted">{{ t('根域名（如 yourbrand.com）不能用 CNAME，请改为添加 A 记录指向') }} <code>{{ serverIp }}</code>{{ t('。') }}</p>
            </li>
            <li>{{ t('回到这里点击「验证」。DNS 生效通常需要几分钟，最长 48 小时。验证成功后，这个域名就能打开你的店铺。') }}</li>
            <li>{{ t('HTTPS 证书会在验证成功后自动申请和续期，第一次打开可能需要多等几秒，不需要你做任何操作。') }}</li>
          </ol>
        </div>
      </NCard>
    </template>
  </div>
</template>

<style scoped>
.page { max-width: 760px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; flex-wrap: wrap; }
.row + .row { border-top: 1px solid var(--border); }
.row-main { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.host { font-weight: 500; color: var(--ink); text-decoration: none; }
.host:hover { color: var(--brand-700); text-decoration: underline; }
.tags { display: flex; gap: 6px; }
.row-actions { display: flex; gap: 6px; }
.add { display: flex; gap: 8px; }
.steps { margin-top: 20px; font-size: 14px; line-height: 1.7; }
.step-title { font-weight: 600; margin: 0 0 6px; }
.steps ol { margin: 0; padding-left: 20px; display: grid; gap: 10px; }
.dns { border-collapse: collapse; margin: 8px 0; font-size: 13px; width: 100%; }
.dns th, .dns td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
.dns th { background: var(--surface-muted); font-weight: 500; }
code { font-family: ui-monospace, monospace; background: var(--surface-muted); padding: 1px 6px; border-radius: 4px; }
.copy { border: none; background: none; cursor: pointer; color: var(--ink-muted); vertical-align: middle; padding: 2px 4px; }
.copy:hover { color: var(--brand-700); }
.muted { color: var(--ink-muted); font-size: 12px; }
</style>
