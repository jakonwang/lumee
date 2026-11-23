import { describe, expect, it } from 'vitest'
import { newSection, type ThemeSchema } from '../src/api/theme'

const schema = {
  settings: {},
  sections: {
    hero: { label: 'Hero', fields: {
      heading: { type: 'text', label: 'Heading', default: 'Hello' },
      image: { type: 'image', label: 'Image' },
      height: { type: 'select', label: 'Height', options: { small: 'S', large: 'L' }, default: 'large' },
    } },
  },
} as unknown as ThemeSchema

describe('newSection', () => {
  it('starts from the schema defaults, enabled, with a unique id', () => {
    const a = newSection(schema, 'hero')
    const b = newSection(schema, 'hero')
    expect(a.type).toBe('hero')
    expect(a.enabled).toBe(true)
    expect(a.settings).toEqual({ heading: 'Hello', image: null, height: 'large' })
    expect(a.id).toMatch(/^hero-[a-z0-9]+$/)
    expect(a.id).not.toBe(b.id)
  })
})
