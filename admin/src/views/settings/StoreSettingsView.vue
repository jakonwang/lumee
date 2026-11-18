<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { NCard, NForm, NFormItem, NInput, NSelect, NButton, NSpin, NAlert, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import SettingsNav from '@/components/SettingsNav.vue'
import SeoCard from '@/components/SeoCard.vue'
import { t } from '@/i18n'

const message = useMessage()
const auth = useAuth()
const loading = ref(true)
const saving = ref(false)
const currencies = ref<string[]>([])
const hasOrders = ref(false)
const originalCurrency = ref('')
const form = reactive({ name: '', currency: 'USD', timezone: 'UTC', seo: { title: null as string | null, description: null as string | null } })

// Common store time zones first; any IANA zone the browser knows is available
const zones = (Intl as unknown as { supportedValuesOf?: (k: string) => string[] }).supportedValuesOf?.('timeZone') ?? ['UTC']
const timezoneOptions = computed(() => ['UTC', ...zones.filter((z) => z !== 'UTC')].map((z) => ({ label: z, value: z })))
const currencyOptions = computed(() => currencies.value.map((c) => ({ label: c, value: c })))
const currencyChanged = computed(() => originalCurrency.value && form.currency !== originalCurrency.value)

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/settings/store')
    apply(data)
  } finally {
    loading.value = false
  }
}

function apply(data: { store: { name: string; currency: string; timezone: string; seo?: { title: string | null; description: string | null } }; currencies: string[]; has_orders: boolean }) {
  Object.assign(form, {
    name: data.store.name, currency: data.store.currency, timezone: data.store.timezone,
    seo: { title: data.store.seo?.title ?? null, description: data.store.seo?.description ?? null },
  })
  originalCurrency.value = data.store.currency
  currencies.value = data.currencies
  hasOrders.value = data.has_orders
}

async function save() {
  saving.value = true
  try {
    const { data } = await http.put('/settings/store', form)
    apply(data)
    await auth.fetchMe()   // refresh the store name in the sidebar
    message.success(t('已保存'))
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
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
    <NCard v-else :title="t('店铺信息')" size="small">
      <NForm label-placement="top" @submit.prevent="save">
        <NFormItem :label="t('店铺名称')">
          <NInput v-model:value="form.name" maxlength="100" />
        </NFormItem>
        <div class="grid">
          <NFormItem :label="t('结算货币')">
            <NSelect v-model:value="form.currency" :options="currencyOptions" filterable />
          </NFormItem>
          <NFormItem :label="t('时区')">
            <NSelect v-model:value="form.timezone" :options="timezoneOptions" filterable />
          </NFormItem>
        </div>
        <NAlert v-if="currencyChanged" type="warning" :bordered="false" style="margin-bottom: 16px">
          {{ t('更换货币不会换算价格：商品上的数字保持不变，只是货币单位变成 {0}。请保存后检查商品价格。{1}', [form.currency, hasOrders ? t('已有订单仍按原货币记录。') : '']) }}
        </NAlert>
        <NButton type="primary" attr-type="submit" :loading="saving">{{ t('保存') }}</NButton>
      </NForm>
    </NCard>
    <template v-if="!loading">
      <SeoCard v-model="form.seo" path="/" :fallback-title="form.name" fallback-description="" />
      <div><NButton type="primary" :loading="saving" @click="save">{{ t('保存首页 SEO') }}</NButton></div>
    </template>
  </div>
</template>

<style scoped>
.page { max-width: 760px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0 16px; }
</style>
