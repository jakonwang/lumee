import { http } from './http'

export interface CollectionSummary {
  id: number
  title: string
  handle: string
  published: boolean
  products_count: number
  image_url: string | null
}

export interface CollectionProduct {
  id: number
  title: string
  status: 'active' | 'draft' | 'archived'
  featured_image: string | null
  price: string
  inventory: number
}

export interface CollectionRule { field: string; op: string; value: string | number | null }
export interface CollectionDetail {
  id: number
  title: string
  handle: string
  description: string | null
  image_id: number | null
  image_url: string | null
  sort_order: string
  published: boolean
  seo: { title: string | null; description: string | null }
  sorts: Record<string, string>
  products: CollectionProduct[]
  rules?: CollectionRule[]
  rules_match?: 'all' | 'any'
  smart?: boolean
}

// Small shared cache: several screens (theme editor fields, product editor) need the list
let cache: Promise<CollectionSummary[]> | null = null

export function listCollections(force = false): Promise<CollectionSummary[]> {
  if (!cache || force) {
    cache = http.get('/collections-list').then((r) => r.data.data).catch((e) => { cache = null; throw e })
  }
  return cache
}

export function invalidateCollections() {
  cache = null
}
