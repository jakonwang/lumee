import { defineStore } from 'pinia'
import { http, TOKEN_KEY, STORE_KEY } from '@/api/http'

export interface Store {
  id: number
  name: string
  handle: string
  currency: string
  storefront_url: string
  role?: 'owner' | 'staff' | string
  /** Areas this member may use (owners: all + "staff"). Server enforces the same rules. */
  permissions?: string[]
  billing?: StoreBilling
}
export type BillingState = 'trialing' | 'active' | 'past_due' | 'expired' | 'suspended'
export interface StoreBilling {
  state: BillingState
  plan: string | null
  plan_name: string | null
  trial_ends_at: string | null
  trial_days_left: number | null
  cancel_at_period_end: boolean
  current_period_end: string | null
}
export interface User { id: number; name: string; email: string; is_platform_admin?: boolean }

export const useAuth = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) as string | null,
    user: null as User | null,
    stores: [] as Store[],
    currentStoreId: Number(localStorage.getItem(STORE_KEY)) || null,
  }),
  getters: {
    isLoggedIn: (s) => !!s.token,
    currentStore: (s) => s.stores.find((x) => x.id === s.currentStoreId) ?? s.stores[0] ?? null,
    isOwner(): boolean {
      return this.currentStore?.role === 'owner'
    },
  },
  actions: {
    setSession(data: { token: string; user: User; stores: Store[] }) {
      this.token = data.token
      this.user = data.user
      this.stores = data.stores
      localStorage.setItem(TOKEN_KEY, data.token)
      if (data.stores[0]) this.switchStore(data.stores[0].id)
    },
    switchStore(id: number) {
      this.currentStoreId = id
      localStorage.setItem(STORE_KEY, String(id))
    },
    /** Returns a challenge when the account uses two-step sign-in (then call verifyTwoFactor). */
    async login(email: string, password: string): Promise<string | null> {
      const { data } = await http.post('/auth/login', { email, password })
      if (data.two_factor) return data.challenge as string
      this.setSession(data)
      return null
    },
    async verifyTwoFactor(challenge: string, code: string, recovery = false) {
      const { data } = await http.post('/auth/two-factor', recovery ? { challenge, recovery_code: code } : { challenge, code })
      this.setSession(data)
    },
    async register(payload: { name: string; email: string; password: string; store_name: string }) {
      const { data } = await http.post('/auth/register', payload)
      this.setSession(data)
    },
    /** Permission check for the current store (UI only — the API enforces it too). */
    can(permission?: string | string[]): boolean {
      if (!permission) return true
      const perms = this.currentStore?.permissions ?? []
      return (Array.isArray(permission) ? permission : [permission]).some((p) => perms.includes(p))
    },
    async fetchMe() {
      const { data } = await http.get('/me')
      this.user = data.user
      this.stores = data.stores
      if (!this.stores.some((s) => s.id === this.currentStoreId) && this.stores[0]) {
        this.switchStore(this.stores[0].id)
      }
    },
    async logout() {
      try { await http.post('/auth/logout') } catch { /* ignore */ }
      this.token = null
      this.user = null
      this.stores = []
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem(STORE_KEY)
    },
  },
})
