<script setup lang="ts">
/** Friendly empty list: what this is, what to do next, and the button for it. Use in a table's #empty slot. */
defineProps<{ title: string; text?: string }>()
</script>

<template>
  <div class="empty-state">
    <strong>{{ title }}</strong>
    <p v-if="text">{{ text }}</p>
    <div v-if="$slots.default" class="actions"><slot /></div>
  </div>
</template>

<style scoped>
.empty-state { display: grid; justify-items: center; gap: 6px; padding: 40px 16px; text-align: center; }
.empty-state strong { font-size: 15px; color: var(--ink); }
.empty-state p { margin: 0; max-width: 420px; font-size: 13px; line-height: 1.6; color: var(--ink-muted); }
.actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; justify-content: center; }
</style>
