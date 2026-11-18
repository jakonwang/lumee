<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { NModal, NForm, NFormItem, NSelect, NRadioGroup, NRadio, NCheckbox, NButton, NSpace, NAlert, useMessage } from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

const props = defineProps<{ productIds: number[] }>()
const emit = defineEmits<{ done: [] }>()
const show = defineModel<boolean>('show', { default: false })
const auth = useAuth()
const message = useMessage()

// Other stores where this member can manage products
const targets = computed(() => auth.stores.filter((s) => s.id !== auth.currentStore?.id
  && (s.role === 'owner' || (s.permissions ?? []).includes('products'))))
const targetId = ref<number | null>(null)
const target = computed(() => targets.value.find((s) => s.id === targetId.value))
const priceMode = ref<'same' | 'convert'>('same')
const images = ref(true)
const inventory = ref(false)
const keepStatus = ref(false)
const saving = ref(false)
const failed = ref<{ title: string; error: string }[]>([])

watch(show, (v) => {
  if (!v) return
  targetId.value = targets.value[0]?.id ?? null
  failed.value = []
})
const differentCurrency = computed(() => !!target.value && target.value.currency !== auth.currentStore?.currency)

async function copy() {
  if (!targetId.value) return
  saving.value = true
  failed.value = []
  try {
    const { data } = await http.post('/products/copy', {
      product_ids: props.productIds, target_store_id: targetId.value,
      price_mode: differentCurrency.value ? priceMode.value : 'same',
      images: images.value, inventory: inventory.value, status: keepStatus.value ? 'same' : 'draft',
    })
    failed.value = data.data.filter((r: { error: string | null }) => r.error)
    if (data.copied) message.success(t('已复制 {0} 件商品到「{1}」', [data.copied, data.target.name]))
    if (!failed.value.length) {
      show.value = false
      emit('done')
    }
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <NModal v-model:show="show" preset="card" :title="t('复制到其他店铺')" style="max-width: 480px" :mask-closable="!saving">
    <NAlert v-if="!targets.length" type="info" :bordered="false">
      {{ t('你还没有其他可以管理商品的店铺。可以在左上角的店铺菜单里新建店铺。') }}
    </NAlert>
    <NForm v-else label-placement="top">
      <p class="hint">{{ t('将复制 {0} 件商品，包括规格、SKU 和价格。分类不会复制。', [productIds.length]) }}</p>
      <NFormItem :label="t('目标店铺')">
        <NSelect v-model:value="targetId" :options="targets.map((s) => ({ label: `${s.name} · ${s.currency}`, value: s.id }))" />
      </NFormItem>
      <NFormItem v-if="differentCurrency" :label="t('价格')">
        <NRadioGroup v-model:value="priceMode">
          <NSpace vertical>
            <NRadio value="same">{{ t('数字不变（{0} 49.00 → {1} 49.00）', [auth.currentStore?.currency, target?.currency]) }}</NRadio>
            <NRadio value="convert">{{ t('按当前汇率换算成 {0}', [target?.currency]) }}</NRadio>
          </NSpace>
        </NRadioGroup>
      </NFormItem>
      <NFormItem :label="t('同时复制')" :show-feedback="false">
        <NSpace vertical>
          <NCheckbox v-model:checked="images">{{ t('商品图片') }}</NCheckbox>
          <NCheckbox v-model:checked="inventory">{{ t('库存数量（不勾选则为 0）') }}</NCheckbox>
          <NCheckbox v-model:checked="keepStatus">{{ t('保留上架状态（不勾选则复制为草稿）') }}</NCheckbox>
        </NSpace>
      </NFormItem>
      <NAlert v-if="failed.length" type="warning" :bordered="false" style="margin-top: 16px">
        <div v-for="f in failed" :key="f.title">{{ f.title }}：{{ f.error }}</div>
      </NAlert>
    </NForm>
    <template #footer>
      <NSpace justify="end">
        <NButton @click="show = false">{{ t('取消') }}</NButton>
        <NButton type="primary" :loading="saving" :disabled="!targetId" @click="copy">{{ t('复制') }}</NButton>
      </NSpace>
    </template>
  </NModal>
</template>

<style scoped>
.hint { margin: 0 0 16px; color: var(--ink-muted); font-size: 13px; line-height: 1.6; }
</style>
