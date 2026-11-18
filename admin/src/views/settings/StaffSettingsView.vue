<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import {
  NCard, NButton, NTag, NModal, NForm, NFormItem, NInput, NCheckbox, NCheckboxGroup, NSpin, NAvatar, NEmpty,
  useMessage, useDialog,
} from 'naive-ui'
import { http, errorMessage } from '@/api/http'
import { useAuth } from '@/stores/auth'
import { formatDate } from '@/api/orders'
import SettingsNav from '@/components/SettingsNav.vue'
import { t } from '@/i18n'

interface Member { id: number; name: string; email: string; role: string; permissions: string[]; joined_at: string }
interface Invitation { id: number; email: string; permissions: string[]; expires_at: string; expired: boolean }
interface Perm { key: string; label: string; description: string }

const message = useMessage()
const dialog = useDialog()
const auth = useAuth()

const loading = ref(true)
const members = ref<Member[]>([])
const invitations = ref<Invitation[]>([])
const perms = ref<Perm[]>([])

const modal = reactive({ show: false, saving: false, mode: 'invite' as 'invite' | 'edit', userId: 0, name: '', email: '', permissions: [] as string[] })

const label = (key: string) => perms.value.find((p) => p.key === key)?.label ?? key

function apply(data: { members: Member[]; invitations: Invitation[]; permissions: Perm[] }) {
  members.value = data.members
  invitations.value = data.invitations
  perms.value = data.permissions
}

async function load() {
  loading.value = true
  try {
    apply((await http.get('/staff')).data)
  } finally {
    loading.value = false
  }
}

function openInvite() {
  Object.assign(modal, { show: true, mode: 'invite', email: '', name: '', userId: 0, permissions: ['orders', 'products'] })
}

function openEdit(m: Member) {
  Object.assign(modal, { show: true, mode: 'edit', email: m.email, name: m.name, userId: m.id, permissions: [...m.permissions] })
}

async function submit() {
  modal.saving = true
  try {
    if (modal.mode === 'invite') {
      apply((await http.post('/staff/invitations', { email: modal.email, permissions: modal.permissions })).data)
      message.success(t('邀请已发送到 {0}', [modal.email]))
    } else {
      apply((await http.put(`/staff/${modal.userId}`, { permissions: modal.permissions })).data)
      message.success(t('权限已更新，立即生效'))
    }
    modal.show = false
  } catch (e) {
    message.error(errorMessage(e))
  } finally {
    modal.saving = false
  }
}

function remove(m: Member) {
  dialog.warning({
    title: t('移除 {0}？', [m.name]),
    content: t('{0} 将立即失去这家店铺后台的访问权限。', [m.email]),
    positiveText: t('移除'),
    negativeText: t('取消'),
    onPositiveClick: async () => {
      try {
        apply((await http.delete(`/staff/${m.id}`)).data)
        message.success(t('已移除'))
      } catch (e) {
        message.error(errorMessage(e))
      }
    },
  })
}

async function resend(i: Invitation) {
  try {
    apply((await http.post(`/staff/invitations/${i.id}/resend`)).data)
    message.success(t('已重新发送，旧链接已失效'))
  } catch (e) {
    message.error(errorMessage(e))
  }
}

async function cancel(i: Invitation) {
  try {
    apply((await http.delete(`/staff/invitations/${i.id}`)).data)
    message.success(t('邀请已取消'))
  } catch (e) {
    message.error(errorMessage(e))
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
      <NCard size="small" content-style="padding: 0">
        <template #header>{{ t('店铺成员') }} <span class="count">{{ members.length }}</span></template>
        <template #header-extra><NButton type="primary" size="small" @click="openInvite">{{ t('邀请员工') }}</NButton></template>

        <div v-for="m in members" :key="m.id" class="row">
          <NAvatar round :size="36" color="var(--brand-100)" style="color: var(--brand-700); flex: none">{{ m.name[0] }}</NAvatar>
          <div class="who">
            <div class="name">
              {{ m.name }}
              <NTag v-if="m.role === 'owner'" size="tiny" :bordered="false" type="success">{{ t('所有者') }}</NTag>
              <NTag v-if="m.id === auth.user?.id" size="tiny" :bordered="false">{{ t('你') }}</NTag>
            </div>
            <div class="muted">{{ m.email }}</div>
            <div class="perm-tags">
              <template v-if="m.role === 'owner'"><span class="perm-all">{{ t('全部权限，包括管理员工') }}</span></template>
              <span v-for="p in m.permissions" v-else :key="p" class="perm">{{ label(p) }}</span>
            </div>
          </div>
          <div v-if="m.role !== 'owner'" class="actions">
            <NButton size="small" quaternary @click="openEdit(m)">{{ t('编辑权限') }}</NButton>
            <NButton size="small" quaternary type="error" @click="remove(m)">{{ t('移除') }}</NButton>
          </div>
        </div>
      </NCard>

      <NCard :title="t('待接受的邀请')" size="small" content-style="padding: 0">
        <NEmpty v-if="!invitations.length" :description="t('没有待接受的邀请')" size="small" style="padding: 24px 0" />
        <div v-for="i in invitations" :key="i.id" class="row">
          <div class="who">
            <div class="name">
              {{ i.email }}
              <NTag v-if="i.expired" size="tiny" :bordered="false" type="warning">{{ t('已过期') }}</NTag>
              <NTag v-else size="tiny" :bordered="false" type="info">{{ t('等待接受') }}</NTag>
            </div>
            <div class="muted">{{ i.expired ? t('已于') : t('链接有效至') }} {{ formatDate(i.expires_at) }}{{ i.expired ? t(' 过期') : '' }}</div>
            <div class="perm-tags"><span v-for="p in i.permissions" :key="p" class="perm">{{ label(p) }}</span></div>
          </div>
          <div class="actions">
            <NButton size="small" quaternary @click="resend(i)">{{ t('重新发送') }}</NButton>
            <NButton size="small" quaternary type="error" @click="cancel(i)">{{ t('取消') }}</NButton>
          </div>
        </div>
      </NCard>
    </template>

    <NModal v-model:show="modal.show" preset="card" :title="modal.mode === 'invite' ? t('邀请员工') : t('编辑 {0} 的权限', [modal.name])" style="width: min(520px, 94vw)">
      <NForm label-placement="top" @submit.prevent="submit">
        <NFormItem v-if="modal.mode === 'invite'" :label="t('员工邮箱')">
          <NInput v-model:value="modal.email" placeholder="name@example.com" />
        </NFormItem>
        <NFormItem :label="t('可以管理')">
          <NCheckboxGroup v-model:value="modal.permissions">
            <div class="perm-list">
              <NCheckbox v-for="p in perms" :key="p.key" :value="p.key">
                <span class="perm-label">{{ p.label }}</span>
                <span class="perm-desc">{{ p.description }}</span>
              </NCheckbox>
            </div>
          </NCheckboxGroup>
        </NFormItem>
        <p class="hint">{{ t('员工无法管理其他员工；邀请链接 7 天内有效。') }}</p>
        <NButton type="primary" block attr-type="submit" :loading="modal.saving" :disabled="!modal.permissions.length">
          {{ modal.mode === 'invite' ? t('发送邀请') : t('保存') }}
        </NButton>
      </NForm>
    </NModal>
  </div>
</template>

<style scoped>
.page { max-width: 820px; display: grid; gap: 16px; }
.page-title { margin: 0; }
.center { display: flex; justify-content: center; padding: 48px; }
.count { font-weight: 400; color: var(--ink-muted); font-size: 13px; margin-left: 4px; }
.row { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; flex-wrap: wrap; }
.row + .row { border-top: 1px solid var(--border); }
.who { flex: 1; min-width: 220px; }
.name { display: flex; align-items: center; gap: 6px; font-weight: 500; }
.muted { color: var(--ink-muted); font-size: 13px; }
.perm-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.perm { font-size: 12px; padding: 2px 8px; border-radius: 6px; background: var(--surface-muted); color: var(--ink); }
.perm-all { font-size: 12px; color: var(--brand-700); }
.actions { display: flex; gap: 4px; }
.perm-list { display: grid; gap: 10px; }
.perm-label { font-weight: 500; margin-right: 8px; }
.perm-desc { color: var(--ink-muted); font-size: 12px; }
.hint { font-size: 12px; color: var(--ink-muted); margin: 0 0 12px; }
</style>
