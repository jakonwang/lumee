<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NForm, NFormItem, NInput, NButton, NSpin, NResult, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

interface Invite { store_name: string; email: string; inviter: string | null; permissions: string[]; has_account: boolean }

const route = useRoute()
const router = useRouter()
const message = useMessage()
const auth = useAuth()

const token = String(route.params.token)
const loading = ref(true)
const invalid = ref('')
const invite = ref<Invite | null>(null)
const saving = ref(false)
const form = reactive({ name: '', password: '' })

onMounted(async () => {
  try {
    invite.value = (await http.get(`/invitations/${token}`)).data
  } catch (e) {
    invalid.value = errorMessage(e)
  } finally {
    loading.value = false
  }
})

async function accept() {
  saving.value = true
  try {
    const { data } = await http.post(`/invitations/${token}/accept`, form)
    auth.setSession(data)
    auth.switchStore(data.store_id)   // open the store they just joined
    message.success(t('欢迎加入「{0}」', [invite.value?.store_name]))
    router.replace('/')
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card">
      <div class="brand"><img src="/platform-mark.svg" alt="" /> Lumée</div>

      <div v-if="loading" class="center"><NSpin /></div>

      <NResult v-else-if="invalid" status="warning" :title="t('邀请不可用')" :description="invalid">
        <template #footer><NButton @click="router.push('/login')">{{ t('去登录') }}</NButton></template>
      </NResult>

      <template v-else-if="invite">
        <h1 class="display">{{ t('加入「{0}」', [invite.store_name]) }}</h1>
        <p class="sub">
          {{ t('{0} 邀请你管理：{1}', [invite.inviter ?? t('店铺所有者'), invite.permissions.join(t('、'))]) }}
        </p>

        <NForm label-placement="top" size="large" @submit.prevent="accept">
          <NFormItem :label="t('邮箱')">
            <NInput :value="invite.email" disabled />
          </NFormItem>
          <NFormItem v-if="!invite.has_account" :label="t('你的名字')">
            <NInput v-model:value="form.name" :placeholder="t('同事会看到这个名字')" />
          </NFormItem>
          <NFormItem :label="invite.has_account ? t('输入你的 Lumée 密码') : t('设置密码（至少 8 位）')">
            <NInput v-model:value="form.password" type="password" show-password-on="click" />
          </NFormItem>
          <NButton type="primary" block size="large" attr-type="submit" :loading="saving">
            {{ invite.has_account ? t('登录并加入') : t('创建账号并加入') }}
          </NButton>
        </NForm>
        <p v-if="invite.has_account" class="hint">{{ t('这个邮箱已经有 Lumée 账号，加入后可以在两家店铺之间切换。') }}</p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 32px 16px; background: var(--bg); }
.card { width: 100%; max-width: 420px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 32px; }
.brand { display: flex; align-items: center; gap: 8px; font-family: var(--font-display); font-weight: 700; font-size: 18px; margin-bottom: 24px; }
.brand img { width: 28px; height: 28px; }
.center { display: flex; justify-content: center; padding: 32px; }
h1 { font-size: 24px; margin: 0 0 6px; }
.sub { color: var(--ink-muted); font-size: 14px; margin: 0 0 24px; line-height: 1.6; }
.hint { color: var(--ink-muted); font-size: 12px; margin: 14px 0 0; text-align: center; }
</style>
