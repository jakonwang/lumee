import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { appsSection, pageOf, sectionOf, sections, settingsGroups } from '../src/navigation'

/** Route names and their meta.menu, read from the router source (importing it would load every page). */
const src = readFileSync(join(__dirname, '..', 'src', 'router', 'index.ts'), 'utf8')
const routes = src.split('\n').flatMap((line) => {
  const name = line.match(/name: '([^']+)'/)?.[1]
  return name ? [{ name, menu: line.match(/menu: '([^']+)'/)?.[1] }] : []
})
const names = new Set(routes.map((r) => r.name))

const navPages = [...sections, appsSection].flatMap((s) => s.pages.flatMap((p) => [p.name, ...(p.also ?? [])]))
const settingsPages = settingsGroups.flatMap((g) => g.pages.map((p) => p.name))

/** Pages that are deliberately outside the menu (sign-in flows, the platform operators' area, the developer portal…). */
const OUTSIDE = new Set(['invite', 'oauth-authorize', 'login', 'settings', 'stores', 'partner-apps', 'partner-themes', 'partner-payouts'])

describe('navigation', () => {
  it('every menu entry is a real page', () => {
    for (const name of [...navPages, ...settingsPages]) expect(names, name).toContain(name)
  })

  it('every admin page can be reached from the menu', () => {
    const unreachable = routes
      .filter((r) => !r.name.startsWith('ops') && !OUTSIDE.has(r.name))
      .filter((r) => !sectionOf(r.menu ?? r.name))
      .map((r) => r.name)
    expect(unreachable).toEqual([])
  })

  it('no page sits in two places', () => {
    const all = [...navPages, ...settingsPages]
    expect(all.filter((n, i) => all.indexOf(n) !== i)).toEqual([])
  })

  it('finds the section and tab of list and detail pages', () => {
    expect(sectionOf('promotions')?.key).toBe('marketing')
    expect(pageOf('promotions')?.name).toBe('marketing')   // automatic discounts are a kind of discount
    expect(sectionOf('translations')?.key).toBe('settings')
    expect(sectionOf('settings-tracking')?.key).toBe('settings')
    expect(sectionOf('leads')?.key).toBe('marketing')
    expect(sectionOf('app-embedded')?.key).toBe('apps')
    expect(sections.filter((s) => !s.channel)).toHaveLength(7)
    expect(sections.filter((s) => s.channel).map((s) => s.key)).toEqual(['online-store', 'b2b'])
  })
})
