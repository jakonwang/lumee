import axios from 'axios'
import { TOKEN_KEY } from '@/api/http'
import { apiLocale } from '@/i18n'
import { t } from '@/i18n'

/** Ops console API (/api/ops) — platform admins only. Same login token, no store header. */
export const ops = axios.create({ baseURL: '/api/ops', headers: { Accept: 'application/json' } })

ops.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) config.headers.Authorization = `Bearer ${token}`
  config.headers['X-Admin-Locale'] = apiLocale
  return config
})

export const stateLabel: Record<string, { label: string; type: 'success' | 'warning' | 'error' | 'info' | 'default' }> = {
  trialing: { label: t('试用中'), type: 'info' },
  active: { label: t('付费中'), type: 'success' },
  past_due: { label: t('扣款失败'), type: 'warning' },
  expired: { label: t('已到期'), type: 'default' },
  suspended: { label: t('已暂停'), type: 'error' },
}
