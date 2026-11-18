<script setup lang="ts">
import { NConfigProvider, NMessageProvider, NDialogProvider, zhCN, dateZhCN, enUS, dateEnUS } from 'naive-ui'
import { themeOverrides } from './theme'
import { locale } from './i18n'

// Naive UI's own texts (date pickers, pagination, empty states) follow the admin language
const uiLocale = locale === 'en' ? enUS : zhCN
const uiDateLocale = locale === 'en' ? dateEnUS : dateZhCN
</script>

<template>
  <NConfigProvider :theme-overrides="themeOverrides" :locale="uiLocale" :date-locale="uiDateLocale">
    <NMessageProvider>
      <NDialogProvider>
        <RouterView />
      </NDialogProvider>
    </NMessageProvider>
  </NConfigProvider>
</template>
