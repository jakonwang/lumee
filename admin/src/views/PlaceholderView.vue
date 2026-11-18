<script setup lang="ts">
import { NEmpty } from 'naive-ui'
import { useRoute } from 'vue-router'
const route = useRoute()
</script>

<template>
  <div>
    <h1 class="page-title">{{ route.meta.title }}</h1>
    <p class="page-sub">{{ t('该模块将在后续阶段开发') }}</p>
    <NEmpty :description="t('即将上线')" style="margin-top: 80px" />
  </div>
</template>
