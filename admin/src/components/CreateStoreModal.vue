<script setup lang="ts">
import { ref, watch } from 'vue'
import { NModal, NForm, NFormItem, NInput, NSelect, NButton, NSpace, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'
import { STORE_CURRENCIES } from '@/constants/currencies'


const show = defineModel<boolean>('show', { default: false })
const auth = useAuth()
const message = useMessage()
const name = ref('')
const currency = ref('USD')
const saving = ref(false)

watch(show, (v) => {
  if (v) { name.value = ''; currency.value = auth.currentStore?.currency ?? 'USD' }
})

async function create() {
  saving.value = true
  try {
    const { data } = await http.post('/stores', { name: name.value.trim(), currency: currency.value })
    auth.stores = data.stores
    auth.switchStore(data.data.id)
    message.success(t('店铺已创建，正在进入新店铺'))
    window.location.href = '/'   // fresh load in the new store (trial starts now)
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <NModal v-model:show="show" preset="card" :title="t('新建店铺')" style="max-width: 440px" :mask-closable="!saving">
    <p class="hint">{{ t('每家店铺有独立的商品、订单、域名和套餐，并单独享有 14 天免费试用。') }}</p>
    <NForm label-placement="top" @submit.prevent="create">
      <NFormItem :label="t('店铺名称')">
        <NInput v-model:value="name" maxlength="100" :placeholder="t('例如：Aurora Europe')" />
      </NFormItem>
      <NFormItem :label="t('结算币种')">
        <NSelect v-model:value="currency" :options="STORE_CURRENCIES.map((c) => ({ label: c, value: c }))" filterable />
      </NFormItem>
    </NForm>
    <template #footer>
      <NSpace justify="end">
        <NButton @click="show = false">{{ t('取消') }}</NButton>
        <NButton type="primary" :loading="saving" :disabled="!name.trim()" @click="create">{{ t('创建店铺') }}</NButton>
      </NSpace>
    </template>
  </NModal>
</template>

<style scoped>
.hint { margin: 0 0 16px; color: var(--ink-muted); font-size: 13px; line-height: 1.6; }
</style>
