import axios from 'axios'
import { apiLocale } from '@/i18n'
import { t } from '@/i18n'

export const TOKEN_KEY = 'lumee_admin_token'
export const STORE_KEY = 'lumee_admin_store'

export const http = axios.create({ baseURL: '/api/admin', headers: { Accept: 'application/json' } })

http.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  const storeId = localStorage.getItem(STORE_KEY)
  if (token) config.headers.Authorization = `Bearer ${token}`
  if (storeId) config.headers['X-Store-Id'] = storeId
  config.headers['X-Admin-Locale'] = apiLocale   // API messages in the admin's language
  return config
})

http.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem(TOKEN_KEY)
      if (location.pathname !== '/login') location.href = '/login'
    }
    // Trial over / plan lapsed / suspended: only the billing page works until a plan is chosen
    if (err.response?.status === 402 && err.response.data?.code === 'billing_required' && location.pathname !== '/settings/billing') {
      import('@/router').then(({ router }) => router.push({ name: 'settings-billing' }))
    }
    return Promise.reject(err)
  },
)

export function errorMessage(err: unknown): string {
  const e = err as { response?: { data?: { message?: string } } }
  return e.response?.data?.message ?? t('请求失败，请稍后再试')
}
