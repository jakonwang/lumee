export type FieldType = 'text' | 'textarea' | 'url' | 'color' | 'select' | 'range' | 'checkbox' | 'image' | 'collection'

export interface Field {
  type: FieldType
  label: string
  default?: unknown
  options?: Record<string, string>
  min?: number
  max?: number
  step?: number
  unit?: string
}

export interface SectionDef {
  label: string
  max_per_page?: number
  fields: Record<string, Field>
}

export interface ThemeSchema {
  name: string
  description: string
  settings: Record<string, { label: string; fields: Record<string, Field> }>
  sections: Record<string, SectionDef>
  max_sections: number
}

export interface Section {
  id: string
  type: string
  enabled: boolean
  settings: Record<string, unknown>
}

export interface ThemeConfig {
  settings: Record<string, Record<string, unknown>>
  sections: Section[]
}

export interface ThemeInfo {
  key: string
  name: string
  description: string
  swatches: string[]
}

export interface ThemeResponse {
  id: number
  name: string
  live: boolean
  theme: string
  active_theme: string
  themes: ThemeInfo[]
  schema: ThemeSchema
  config: ThemeConfig
  media: { id: number; url: string }[]
  has_unpublished_changes: boolean
  published_at: string | null
  preview_url: string
  storefront_url: string
}

/** One theme in the store's theme library */
export interface LibraryTheme {
  id: number
  name: string
  theme: string
  template: string
  source: 'template' | 'copy' | 'import' | 'shopify' | 'market'
  live: boolean
  swatches: string[]
  sections: number
  has_unpublished_changes: boolean
  updated_at: string
  published_at: string | null
  preview_url: string
}

export interface ImportReport {
  sections: { from: string; to: string | null }[]
  settings: string[]
  images?: number
  notes: string[]
}

export function newSection(schema: ThemeSchema, type: string): Section {
  const def = schema.sections[type]!
  const settings: Record<string, unknown> = {}
  for (const [k, f] of Object.entries(def.fields)) settings[k] = f.default ?? null
  return { id: `${type}-${Math.random().toString(36).slice(2, 8)}`, type, enabled: true, settings }
}
