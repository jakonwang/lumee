export interface ProductImage {
  id: number
  url: string
  alt: string | null
  width?: number | null
  height?: number | null
}

export interface ProductOption {
  name: string
  values: string[]
}

export interface Variant {
  id?: number
  title?: string
  options: Record<string, string>
  price: string | number
  compare_at_price: string | number | null
  sku: string | null
  inventory: number
  weight: number
  image_id: number | null
}

export interface ProductDetail {
  id: number
  title: string
  handle: string
  description: string | null
  status: 'active' | 'draft' | 'archived'
  product_type: string | null
  vendor: string | null
  tags: string[]
  taxable: boolean
  is_gift_card?: boolean
  b2b_only?: boolean
  size_chart?: string | null
  supplier_id?: number | null
  subscription?: { enabled: boolean; discount: number; intervals: string[]; only: boolean }
  preorder?: { enabled: boolean; ships_at: string | null; note: string | null; limit: number | null }
  digital?: boolean
  files?: { id: number; filename: string; size: number; created_at: string }[]
  seo: { title: string | null; description: string | null }
  featured_image: string | null
  images: ProductImage[]
  collection_ids: number[]
  options: ProductOption[]
  variants: Variant[]
  stock_alerts?: number
}

/** Stable key for a combination of option values, used to keep edits when options change. */
export function comboKey(options: ProductOption[], values: Record<string, string>): string {
  return options.map((o) => values[o.name] ?? '').join('\u001f').toLowerCase()
}

/** Cartesian product of all option values, in option order. */
export function combinations(options: ProductOption[]): Record<string, string>[] {
  return options.reduce<Record<string, string>[]>(
    (acc, opt) => acc.flatMap((combo) => opt.values.map((v) => ({ ...combo, [opt.name]: v }))),
    [{}],
  )
}
