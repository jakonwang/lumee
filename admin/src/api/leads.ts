import { t } from '@/i18n'

export interface LeadRow {
  id: number; name: string; category: string | null; segment: Segment; country: string | null; city: string | null
  phone: string | null; website: string | null; domain: string | null; emails: string[]; socials: Record<string, string>
  summary: string | null; rating: number | null; reviews_count: number | null; followers: number | null; score: number; score_reasons: string[]
  ai_scored: boolean; status: LeadStatus; starred: boolean; enrich_status: string; source: string; company_id: number | null
  contacted_at: string | null; created_at: string; next_follow_up_at: string | null
  email_checks: Record<string, EmailCheck>; lat: number | null; lng: number | null
}
export interface EmailCheck { mx: boolean; kind: 'role' | 'personal' | 'free'; guessed: boolean }
export interface MapLead { id: number; name: string; score: number; segment: Segment; status: LeadStatus; lat: number; lng: number; city: string | null; country: string | null; has_email: boolean; has_phone: boolean }
export interface LeadDetail extends LeadRow {
  address: string | null; phones: string[]; lat: number | null; lng: number | null; signals: string[]; enrich_error: string | null
  note: string | null; search: { id: number; name: string; industry: string } | null; has_site_text: boolean
  contacts: { email: string; name: string | null; position: string | null; type: string | null }[]
  activities: { id: number; type: string; body: string | null; created_at: string }[]
}
export interface LeadSearch {
  id: number; name: string; industry: string; keywords: string[]; country: string; cities: string[]; source: 'google' | 'osm' | 'web' | 'manual'
  channels: WebChannel[] | null
  status: 'queued' | 'running' | 'done' | 'failed'; stage: string | null; found: number; new_leads: number; enriched: number; to_enrich: number
  error: string | null; created_at: string; finished_at: string | null
}
export interface LeadStats { total: number; with_email: number; with_phone: number; grade_a: number; contacted: number; replied: number; won: number; this_week: number; due_today: number; mapped: number; guessed_email: number }
export interface Sender { company: string; name: string; title: string; products: string; advantages: string; moq: string; website: string; whatsapp: string; email: string }
export interface LeadSettings {
  google_key_set: boolean; anthropic_key_set: boolean; google_platform: boolean; anthropic_platform: boolean
  google_ready: boolean; ai_ready: boolean; ai_auto: boolean; anthropic_workspace: string | null; sender: Sender
  serp_ready: boolean; meta_token_set: boolean; ig_user_id: string | null; instagram_ready: boolean; hunter_key_set: boolean; hunter_ready: boolean
}
/** Where a search looks: maps (Google / OpenStreetMap) or Google web results of company sites and public social profiles. */
export type WebChannel = 'web' | 'facebook' | 'instagram' | 'linkedin' | 'tiktok'
export type Channel = 'maps' | WebChannel
export const CHANNEL_LABELS: Record<Channel, string> = {
  maps: t('地图商家'), web: t('公司官网'), facebook: 'Facebook', instagram: 'Instagram', linkedin: 'LinkedIn', tiktok: 'TikTok',
}
/** "12.5K" */
export function compactNumber(n: number | null | undefined): string {
  if (!n) return ''
  return n >= 1e6 ? `${+(n / 1e6).toFixed(1)}M` : n >= 1e3 ? `${+(n / 1e3).toFixed(1)}K` : String(n)
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

export const GRADE_COLORS = { A: '#0A7350', B: '#14A06E', C: '#7CC9A6', D: '#B9C4BE' } as const

export function emailBadge(c: EmailCheck | undefined): { label: string; tone: 'ok' | 'muted' | 'warn' } | null {
  if (!c) return null
  if (c.guessed) return { label: t('推测'), tone: 'warn' }
  if (!c.mx) return { label: t('域名不收信'), tone: 'warn' }
  return { label: c.kind === 'personal' ? t('个人邮箱') : c.kind === 'free' ? t('免费邮箱') : t('已验证'), tone: c.kind === 'role' ? 'ok' : 'muted' }
}

/** "今天" / "明天" / "已逾期 2 天" / "11月28日" */
export function dueText(iso: string | null): { text: string; overdue: boolean } | null {
  if (!iso) return null
  const d = new Date(iso)
  const day = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
  const diff = Math.round((day(d) - day(new Date())) / 864e5)
  if (diff < 0) return { text: t('逾期 {0} 天', [-diff]), overdue: true }
  if (diff === 0) return { text: t('今天跟进'), overdue: true }
  if (diff === 1) return { text: t('明天跟进'), overdue: false }
  return { text: t('{0} 天后跟进', [diff]), overdue: false }
}
