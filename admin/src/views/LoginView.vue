<script setup lang="ts">
import { locale, setLocale } from '@/i18n'
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NForm, NFormItem, NInput, NButton, useMessage } from 'naive-ui'
import { useAuth } from '@/stores/auth'
import { errorMessage } from '@/api/http'

const auth = useAuth()
const router = useRouter()
const route = useRoute()
// Back to where they were going (e.g. an app's install screen); only same-site paths
const next = () => { const r = String(route.query.redirect ?? ''); return r.startsWith('/') && !r.startsWith('//') ? r : '/' }
const message = useMessage()

const mode = ref<'login' | 'register'>('login')
const loading = ref(false)
const form = reactive({ name: '', email: '', password: '', store_name: '' })

// Two-step sign-in: after the password, a 6-digit code from the authenticator app (or a recovery code)
const challenge = ref<string | null>(null)
const code = ref('')
const useRecovery = ref(false)

async function submit() {
  if (loading.value) return
  loading.value = true
  try {
    if (challenge.value) {
      await auth.verifyTwoFactor(challenge.value, code.value, useRecovery.value)
    } else if (mode.value === 'login') {
      challenge.value = await auth.login(form.email, form.password)
      if (challenge.value) return
    } else {
      await auth.register({ ...form })
    }
    router.push(next())
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="page">
    <aside class="visual">
      <div class="glow" />
      <div class="visual-inner">
        <div class="brand"><img src="/platform-mark.svg" alt="" /> Lumée</div>
        <h2 class="display">{{ t('几分钟开一家') }}<br />{{ t('卖向全球的独立站') }}</h2>
        <ul class="points">
          <li>{{ t('一个国家一个站点，币种、语言、价格各自设置') }}</li>
          <li>{{ t('AI 按当地习惯装修店铺') }}</li>
          <li>{{ t('选品、收款、物流、营销一站搞定') }}</li>
        </ul>
      </div>
    </aside>
    <main class="panel">
      <div class="card">
        <div class="eyebrow">{{ mode === 'login' ? 'Welcome back' : 'Start your store' }}</div>
        <h1 class="display">{{ mode === 'login' ? t('登录商家后台') : t('免费开店') }}</h1>
        <p class="sub">{{ mode === 'login' ? t('管理你的店铺、商品与订单') : t('14 天免费试用，无需信用卡') }}</p>

        <NForm v-if="challenge" @submit.prevent="submit" label-placement="top" size="large">
          <p class="sub">{{ useRecovery ? t('输入一个恢复码（每个只能用一次）。') : t('打开手机上的验证器应用，输入 6 位验证码。') }}</p>
          <NFormItem :label="useRecovery ? t('恢复码') : t('验证码')">
            <NInput v-model:value="code" :placeholder="useRecovery ? 'xxxx-xxxx' : '123456'" :input-props="{ autocomplete: 'one-time-code', inputmode: useRecovery ? 'text' : 'numeric' }" autofocus />
          </NFormItem>
          <NButton type="primary" block size="large" :loading="loading" attr-type="submit" class="metal" :disabled="!code.trim()">{{ t('验证并登录') }}</NButton>
          <p class="switch">
            <a @click="useRecovery = !useRecovery; code = ''">{{ useRecovery ? t('使用验证器应用') : t('手机不在身边？使用恢复码') }}</a>
            · <a @click="challenge = null; code = ''">{{ t('返回') }}</a>
          </p>
        </NForm>
        <NForm v-else @submit.prevent="submit" label-placement="top" size="large">
          <template v-if="mode === 'register'">
            <NFormItem :label="t('店铺名称')"><NInput v-model:value="form.store_name" :placeholder="t('例如：Bloom Home')" /></NFormItem>
            <NFormItem :label="t('你的名字')"><NInput v-model:value="form.name" /></NFormItem>
          </template>
          <NFormItem :label="t('邮箱')"><NInput v-model:value="form.email" placeholder="you@example.com" /></NFormItem>
          <NFormItem :label="t('密码')">
            <NInput v-model:value="form.password" type="password" show-password-on="click" />
          </NFormItem>
          <NButton type="primary" block size="large" :loading="loading" attr-type="submit" class="metal">
            {{ mode === 'login' ? t('登录') : t('创建店铺') }}
          </NButton>
        </NForm>

        <p class="switch">
          <template v-if="mode === 'login'">{{ t('还没有店铺？') }}<a @click="mode = 'register'">{{ t('免费开店') }}</a></template>
          <template v-else>{{ t('已有账号？') }}<a @click="mode = 'login'">{{ t('直接登录') }}</a></template>
        </p>
        <p class="lang-switch">
          <a :class="{ on: locale === 'zh-CN' }" @click="setLocale('zh-CN')">中文</a>
          <span aria-hidden="true">·</span>
          <a :class="{ on: locale === 'en' }" @click="setLocale('en')">English</a>
        </p>
      </div>
    </main>
  </div>
</template>

<style scoped>
.page { display: grid; grid-template-columns: 1.1fr 1fr; min-height: 100vh; }
.visual {
  position: relative; overflow: hidden;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  background: var(--brand-50);
  border-right: 1px solid var(--border);
  color: var(--ink);
}
.glow { display: none; }
.visual-inner { position: relative; max-width: 420px; padding: 48px 32px; }
.brand { display: flex; align-items: center; gap: 10px; font-family: var(--font-display); font-weight: 700; font-size: 22px; margin-bottom: 40px; color: var(--ink); }
.brand img { width: 36px; height: 36px; }
.visual h2 { font-size: clamp(26px, 3vw, 34px); line-height: 1.3; margin: 0 0 28px; color: var(--ink); }
.points { list-style: none; padding: 0; margin: 0; display: grid; gap: 14px; font-size: 15px; color: var(--ink-muted); }
.points li { display: flex; align-items: center; gap: 10px; }
.points li::before { content: '✓'; width: 22px; height: 22px; border-radius: 50%; background: var(--brand-100); color: var(--brand-700); display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex: none; }
.panel { display: flex; align-items: center; justify-content: center; background: var(--surface); padding: 32px 16px; }
.card { width: 100%; max-width: 380px; }
h1 { font-size: 36px; margin: 8px 0 4px; color: var(--ink); }
.sub { color: var(--ink-muted); margin: 0 0 28px; font-size: 14px; }
.metal { margin-top: 8px; }
.switch { text-align: center; font-size: 14px; color: var(--ink-muted); margin-top: 24px; }
.switch a { color: var(--brand-700); cursor: pointer; font-weight: 500; }
@media (max-width: 860px) {
  .page { grid-template-columns: 1fr; }
  .visual { min-height: auto; }
  .visual-inner { padding: 28px 20px; }
  .brand { margin-bottom: 16px; }
  .visual h2 { font-size: 22px; margin-bottom: 0; }
  .points { display: none; }
}
.lang-switch { margin-top: 18px; text-align: center; font-size: 13px; color: var(--ink-muted); display: flex; gap: 8px; justify-content: center; }
.lang-switch a { cursor: pointer; color: var(--ink-muted); }
.lang-switch a.on { color: var(--brand-700); font-weight: 600; }
</style>
