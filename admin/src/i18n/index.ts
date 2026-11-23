/**
 * Admin UI language. Chinese is the source: t('订单') returns the Chinese text, or the English
 * translation from en.json when the admin is in English. Placeholders: t('共 {0} 个', [n]) or
 * t('{name} 的店铺', { name }). Switching language saves the choice and reloads the app, so
 * module-level labels (menus, status maps) are built once in the right language.
 */

export type Locale = 'zh-CN' | 'en'

const KEY = 'lumee_admin_locale'

function initial(): Locale {
  try {
    const saved = localStorage.getItem(KEY)
    if (saved === 'en' || saved === 'zh-CN') return saved
  } catch { /* storage blocked: fall through */ }
  // First visit: Chinese browsers get Chinese, everyone else English
  return (navigator.language || '').toLowerCase().startsWith('zh') ? 'zh-CN' : 'en'
}

export const locale: Locale = initial()

// English texts load on their own (they're ~200 KB): Chinese admins never download them. main.ts waits for
// loadLocale() before importing the app, so module-level labels (menus, page titles) are translated too.
let dict: Record<string, string> = {}
export async function loadLocale(): Promise<void> {
  if (locale === 'en') dict = (await import('./en.json')).default as Record<string, string>
}

const warned = new Set<string>()

export function t(text: string, params?: Record<string, unknown> | unknown[]): string {
  // Dev aid: Chinese text without an English translation shows up in the console once
  if (import.meta.env.DEV && locale === 'en' && !(text in dict) && /[一-鿿]/.test(text) && !warned.has(text)) {
    warned.add(text)
    console.warn('[i18n] missing English for:', text)
  }
  const s = dict[text] ?? text
  if (!params) return s
  return s.replace(/\{(\w+)\}/g, (m, k: string) => {
    const v = Array.isArray(params) ? params[Number(k)] : params[k]
    return v === undefined || v === null ? m : String(v)
  })
}

export function setLocale(l: Locale) {
  try { localStorage.setItem(KEY, l) } catch { /* ignore */ }
  location.reload()
}

/** Header value for the API (Laravel locale codes). */
export const apiLocale = locale === 'en' ? 'en' : 'zh_CN'

/** Date/time formatting locale. */
export const dateLocale = locale === 'en' ? 'en-US' : 'zh-CN'
