export type FieldType = 'text' | 'textarea' | 'url' | 'color' | 'select' | 'range' | 'checkbox' | 'image' | 'collection' | 'product' | 'datetime'

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
  /** Where the block may go: home, page (campaign pages), product, collection, cart */
  on: string[]
  fields: Record<string, Field>
}

export interface StyleKit { name: string; description: string; settings: Record<string, string | string[]> }

export interface ThemeSchema {
  name: string
  description: string
  settings: Record<string, { label: string; fields: Record<string, Field> }>
  sections: Record<string, SectionDef>
  max_sections: number
  /** Page templates; "fixed" = the block that's always on the page (product info, product grid, the bag) */
  templates: Record<string, { label: string; fixed?: string; default: string[] }>
  kits?: Record<string, StyleKit>
}

export interface Section {
  id: string
  type: string
  enabled: boolean
  settings: Record<string, unknown>
}

export interface ThemeConfig {
  settings: Record<string, Record<string, unknown>>
  /** Home page */
  sections: Section[]
  /** Product / collection / cart page blocks */
  templates: Record<string, Section[]>
  /** Campaign landing pages, by page id */
  pages: Record<string, Section[]>
}

/** Something that can be designed: the home page, a page template or a landing page ("page:12") */
export interface ThemePlace { key: string; place: string; label: string; preview_url: string }

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
  /** Markets and whether each has its own design on this theme */
  markets: { id: number; name: string; currency: string; locale: string; enabled: boolean; customized: boolean; has_unpublished_changes: boolean }[]
  /** The market being edited (null = the main site) */
  market: { id: number; name: string; locale: string; own_sections: boolean } | null
  /** In a market: the main site's config, to show and restore what the market changed */
  base: ThemeConfig | null
  places: ThemePlace[]
  /** AI design: Claude key set up, requests used this month, allowance, image service connected */
  ai: { ready: boolean; used: number; quota: number; images: boolean }
  /** A design A/B test running on this site (B = the draft as it was when the test started) */
  experiment: { id: number; name: string; started_at: string } | null
}

/** One version's numbers in a design A/B test */
export interface DesignArm { sessions: number; carts: number; orders: number; revenue: number; cart_rate: number; conversion: number; revenue_per_session: number }
export interface DesignStats { a: DesignArm; b: DesignArm; p_value: number | null; p_cart: number | null; uplift: number | null; leader: 'a' | 'b' | null; ready: boolean; min: number }

/** A site's visit numbers for a period (装修效果) */
export interface SiteMetrics {
  sessions: number; mobile_share: number; bounce: number; home_bounce: number | null; product_view: number; cart: number; checkout: number
  conversion: number; revenue_per_session: number; orders: number; revenue: number
  mobile: { sessions: number; bounce: number; conversion: number }; desktop: { sessions: number; bounce: number; conversion: number }
}
export interface DesignPerformance {
  days: number
  currency: string
  /** The live theme (editor links) */
  theme_id: number
  sites: { market: { id: number; name: string; currency: string } | null; now: SiteMetrics; before: SiteMetrics; changes: { id: number; source: string; note: string | null; created_at: string }[] }[]
  experiments: { id: number; name: string; status: 'running' | 'finished' | 'stopped'; winner: 'a' | 'b' | null; note: string | null; started_at: string; ended_at: string | null; market_id: number | null; market: string | null; stats: DesignStats }[]
  insights: { id: number; market_id: number | null; market: string | null; week: string; updated_at: string
    findings: { level: 'info' | 'warning' | 'error'; code: string; message: string }[]; suggestions: { title: string; detail: string }[] }[]
  ai: { ready: boolean; used: number; quota: number; images: boolean }
}

export interface ThemeVersion { id: number; source: string; note: string | null; by: string | null; created_at: string }

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
