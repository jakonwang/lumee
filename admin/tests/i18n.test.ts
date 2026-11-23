import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import en from '../src/i18n/en.json'

/** The admin picks its language once, when the i18n module loads: reload it with a saved choice. */
async function load(saved: string | null) {
  vi.resetModules()
  localStorage.clear()
  if (saved) localStorage.setItem('lumee_admin_locale', saved)
  return import('../src/i18n')
}

afterEach(() => localStorage.clear())

describe('t()', () => {
  it('returns the Chinese source text in Chinese', async () => {
    const { t, apiLocale } = await load('zh-CN')
    expect(t('订单')).toBe('订单')
    expect(t('共 {0} 个', [3])).toBe('共 3 个')
    expect(apiLocale).toBe('zh_CN')
  })

  it('translates to English and fills placeholders', async () => {
    const { t, apiLocale } = await load('en')
    expect(t('装修效果')).toBe('Design performance')
    expect(t('本月已回答 {0} 次', [7])).toBe('7 answers this month')
    expect(t('{name} 的店铺', { name: 'Lumée' })).toBe('Lumée 的店铺')   // untranslated text still fills placeholders
    expect(apiLocale).toBe('en')
  })

  it('keeps a placeholder it has no value for', async () => {
    const { t } = await load('zh-CN')
    expect(t('{0} 和 {1}', ['A'])).toBe('A 和 {1}')
  })
})

describe('translations', () => {
  it('every Chinese t() text in src/ has an English translation', () => {
    const root = join(__dirname, '..', 'src')
    const dict = en as Record<string, string>
    const call = /\bt\(\s*'((?:[^'\\]|\\.)*)'/g
    const missing: string[] = []
    const walk = (dir: string) => {
      for (const name of readdirSync(dir)) {
        const p = join(dir, name)
        if (statSync(p).isDirectory()) {
          if (name !== 'i18n') walk(p)
        } else if (/\.(vue|ts)$/.test(name) && !name.endsWith('.d.ts')) {
          for (const m of readFileSync(p, 'utf8').matchAll(call)) {
            const key = m[1]!.replace(/\\'/g, "'").replace(/\\n/g, '\n').replace(/\\\\/g, '\\')
            if (/[一-鿿]/.test(key) && !(key in dict)) missing.push(`${key}  (${p.slice(root.length + 1)})`)
          }
        }
      }
    }
    walk(root)
    expect(missing).toEqual([])
  })
})
