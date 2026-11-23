<script setup lang="ts">
/** Discounts: codes and automatic discounts are two kinds of the same thing — switch between them. */
import { RouterLink, useRoute } from 'vue-router'
import { t } from '@/i18n'

const route = useRoute()
</script>

<template>
  <div class="dswitch" role="tablist" :aria-label="t('折扣类型')">
    <RouterLink :to="{ name: 'marketing' }" role="tab" :aria-selected="route.name === 'marketing'" :class="{ on: route.name === 'marketing' }">{{ t('折扣码') }}</RouterLink>
    <RouterLink :to="{ name: 'promotions' }" role="tab" :aria-selected="route.name === 'promotions'" :class="{ on: route.name === 'promotions' }">{{ t('自动折扣') }}</RouterLink>
  </div>
</template>

<style scoped>
.dswitch { display: inline-flex; padding: 3px; border-radius: 9px; background: #F1F4F2; margin-bottom: 16px; }
.dswitch a { padding: 6px 16px; border-radius: 7px; font-size: 13px; color: #5C6963; text-decoration: none; }
.dswitch a.on { background: #fff; color: var(--ink); font-weight: 600; box-shadow: 0 1px 2px rgba(16, 24, 20, .08); }
</style>
