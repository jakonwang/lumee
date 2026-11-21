import { t } from '@/i18n'

export interface LeadRow {
  id: number; name: string; category: string | null; segment: Segment; country: string | null; city: string | null
  phone: string | null; website: string | null; domain: string | null; emails: string[]; socials: Record<string, string>
  summary: string | null; rating: number | null; reviews_count: number | null; score: number; score_reasons: string[]
  ai_scored: boolean; status: LeadStatus; starred: boolean; enrich_status: string; source: string; company_id: number | null
  contacted_at: string | null; created_at: string
}
export interface LeadDetail extends LeadRow {
  address: string | null; phones: string[]; lat: number | null; lng: number | null; signals: string[]; enrich_error: string | null
  note: string | null; search: { id: number; name: string; industry: string } | null; has_site_text: boolean
  activities: { id: number; type: string; body: string | null; created_at: string }[]
}
export interface LeadSearch {
  id: number; name: string; industry: string; keywords: string[]; country: string; cities: string[]; source: 'google' | 'osm' | 'manual'
  status: 'queued' | 'running' | 'done' | 'failed'; stage: string | null; found: number; new_leads: number; enriched: number; to_enrich: number
  error: string | null; created_at: string; finished_at: string | null
}
export interface LeadStats { total: number; with_email: number; with_phone: number; grade_a: number; contacted: number; replied: number; won: number; this_week: number }
export interface Sender { company: string; name: string; title: string; products: string; advantages: string; moq: string; website: string; whatsapp: string; email: string }
export interface LeadSettings {
  google_key_set: boolean; anthropic_key_set: boolean; google_platform: boolean; anthropic_platform: boolean
  google_ready: boolean; ai_ready: boolean; ai_auto: boolean; sender: Sender
}
export interface LeadMeta {
  profiles: { key: string; label: string; pitch: string; buyers: string[] }[]
  countries: { code: string; name: string; en: string; cities: string[] }[]
  keywords: Record<string, Record<string, string[]>>
  settings: LeadSettings
  segments: Record<Segment, string>
}

export type Segment = 'wholesale' | 'repair' | 'ecommerce' | 'retail' | 'brand' | 'other'
export type LeadStatus = 'new' | 'contacted' | 'replied' | 'qualified' | 'won' | 'lost'

export const STATUS_LABELS: Record<LeadStatus, string> = {
  new: t('新线索'), contacted: t('已联系'), replied: t('已回复'), qualified: t('有意向'), won: t('已成交'), lost: t('无效'),
}
export const SEGMENT_LABELS: Record<Segment, string> = {
  wholesale: t('批发 / 分销商'), repair: t('维修店'), ecommerce: t('电商卖家'), retail: t('零售门店'), brand: t('品牌商'), other: t('其他'),
}
export const STATUS_OPTIONS = (Object.keys(STATUS_LABELS) as LeadStatus[]).map((v) => ({ label: STATUS_LABELS[v], value: v }))
export const SEGMENT_OPTIONS = (Object.keys(SEGMENT_LABELS) as Segment[]).map((v) => ({ label: SEGMENT_LABELS[v], value: v }))

export function grade(score: number): 'A' | 'B' | 'C' | 'D' {
  return score >= 75 ? 'A' : score >= 55 ? 'B' : score >= 35 ? 'C' : 'D'
}

/** Regional-indicator flag for an ISO country code. */
const NO_FLAGS = typeof navigator !== 'undefined' && /Win/i.test(navigator.userAgent)   // Windows shows the letters, not a flag
export function flag(code: string | null): string {
  if (!code || code.length !== 2 || NO_FLAGS) return ''
  return String.fromCodePoint(...[...code.toUpperCase()].map((c) => 0x1f1a5 + c.charCodeAt(0)))
}

export const SOCIAL_LABELS: Record<string, string> = {
  whatsapp: 'WhatsApp', facebook: 'Facebook', instagram: 'Instagram', linkedin: 'LinkedIn', twitter: 'X', tiktok: 'TikTok', youtube: 'YouTube', telegram: 'Telegram',
}

export function whatsappLink(lead: { socials: Record<string, string>; phone: string | null }): string | null {
  if (lead.socials.whatsapp) return lead.socials.whatsapp
  return null
}
