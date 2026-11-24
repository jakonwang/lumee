/**
 * The admin's menu, defined once: the sidebar shows the sections, a section's pages appear as tabs above the page,
 * Settings has its own grouped menu, and Ctrl+K searches all of them. Route names only — URLs don't change.
 *
 * To add a page: put it in the `pages` of the section (or settings group) it belongs to. Don't add sidebar items.
 */
import type { Component } from 'vue'
import {
  HomeOutline, ReceiptOutline, PricetagOutline, PeopleOutline, MegaphoneOutline, BarChartOutline,
  StorefrontOutline, SettingsOutline, AppsOutline, BulbOutline, BriefcaseOutline,
} from '@vicons/ionicons5'
import { t } from '@/i18n'

export interface NavPage {
  name: string
  label: string
  /** Other route names shown as this tab (e.g. automatic discounts inside "Discounts") */
  also?: string[]
  /** Extra words people may search for (Ctrl+K) */
  keywords?: string   // Chinese and English, not translated: search works in either language
}
export interface NavSection {
  key: string
  label: string
  icon: Component
  pages: NavPage[]
  /** Printed under a divider: sales channels */
  channel?: boolean
  /** Route names that belong here without being a tab (embedded apps, charges…) */
  also?: string[]
}

export const sections: NavSection[] = [
  { key: 'home', label: t('首页'), icon: HomeOutline, pages: [{ name: 'dashboard', label: t('首页') }] },
  {
    key: 'orders', label: t('订单'), icon: ReceiptOutline,
    pages: [
      { name: 'orders', label: t('全部订单') },
      { name: 'abandoned-checkouts', label: t('弃单'), keywords: '未完成结账 挽回 abandoned checkout recovery' },
      { name: 'returns', label: t('退货'), keywords: '退款 售后 refund return' },
      { name: 'subscriptions', label: t('订阅订单'), keywords: '订阅 续订 subscriptions recurring' },
    ],
  },
  {
    key: 'products', label: t('商品'), icon: PricetagOutline,
    pages: [
      { name: 'products', label: t('全部商品'), keywords: '库存 规格 inventory stock variants' },
      { name: 'collections', label: t('分类') },
      { name: 'reviews', label: t('评论') },
      { name: 'gift-cards', label: t('礼品卡') },
    ],
  },
  {
    key: 'sourcing', label: t('选品与采购'), icon: BulbOutline,
    pages: [
      { name: 'research', label: t('选品雷达'), keywords: '爆款 潜力商品 research winning products trends' },
      { name: 'collect', label: t('采集'), keywords: '导入 1688 速卖通 import collect aliexpress 1688' },
      { name: 'content', label: t('内容工坊'), keywords: '文案 图片 AI content copy images ai' },
      { name: 'testing', label: t('测款') },
      { name: 'purchasing', label: t('采购单'), keywords: '供应商 进货 purchase orders suppliers' },
    ],
  },
  {
    key: 'customers', label: t('客户'), icon: PeopleOutline,
    pages: [
      { name: 'customers', label: t('全部客户') },
      { name: 'privacy-requests', label: t('隐私请求'), keywords: '删除数据 GDPR privacy gdpr delete data' },
    ],
  },
  {
    key: 'marketing', label: t('营销'), icon: MegaphoneOutline,
    pages: [
      { name: 'campaigns', label: t('营销活动'), keywords: '邮件 群发 email campaigns newsletter' },
      { name: 'flows', label: t('自动化'), keywords: '欢迎邮件 召回 automation flows welcome winback' },
      { name: 'marketing', label: t('折扣'), also: ['promotions'], keywords: '折扣码 优惠券 自动折扣 满减 discount codes coupons automatic promotions' },
      { name: 'affiliates', label: t('联盟营销'), keywords: '推广员 佣金 affiliates commission' },
      { name: 'creators', label: t('达人合作'), keywords: '网红 博主 KOL 带货 influencers creators instagram tiktok youtube' },
      { name: 'seo-pages', label: t('SEO 落地页'), keywords: '搜索引擎 关键词 谷歌 自然流量 seo landing pages google organic' },
      { name: 'loyalty', label: t('积分') },
      { name: 'leads', label: t('获客雷达'), keywords: 'B2B 客户开发 线索 leads prospecting b2b' },
      { name: 'outreach', label: t('开发信'), keywords: '邮件序列 跟进 冷邮件 自动发送 outreach cold email sequences follow-up' },
    ],
  },
  {
    key: 'analytics', label: t('数据分析'), icon: BarChartOutline,
    pages: [
      { name: 'analytics', label: t('概览'), keywords: '访客 转化 报表 analytics visitors conversion reports' },
      { name: 'ad-performance', label: t('广告效果'), keywords: 'ROAS 广告花费 投放 每单成本 ads roas spend campaigns google meta tiktok' },
      { name: 'design-performance', label: t('装修效果'), keywords: 'A/B 测试 改版建议 design ab test insights' },
    ],
  },
  {
    key: 'online-store', label: t('在线商店'), icon: StorefrontOutline, channel: true,
    pages: [
      { name: 'online-store', label: t('主题'), keywords: '装修 模板 编辑器 themes editor design templates' },
      { name: 'assistant', label: t('AI 导购'), keywords: '聊天 客服 ai assistant chat' },
      { name: 'pages', label: t('页面') },
      { name: 'blog', label: t('博客') },
      { name: 'navigation', label: t('导航菜单') },
      { name: 'redirects', label: t('网址重定向') },
    ],
  },
  {
    key: 'b2b', label: t('B2B 批发'), icon: BriefcaseOutline, channel: true,
    pages: [
      { name: 'companies', label: t('公司'), keywords: '批发客户 companies wholesale customers' },
      { name: 'quotes', label: t('询价单'), keywords: '报价 quotes' },
      { name: 'price-lists', label: t('批发价'), keywords: '价格表 price lists wholesale' },
      { name: 'wholesale-catalogs', label: t('批发目录'), keywords: '分销商 catalogs distributors' },
    ],
  },
]

export const appsSection: NavSection = { key: 'apps', label: t('应用'), icon: AppsOutline, pages: [{ name: 'apps', label: t('应用') }], also: ['app-charge', 'app-embedded'] }
export const settingsSection: NavSection = { key: 'settings', label: t('设置'), icon: SettingsOutline, pages: [] }

/** Settings, grouped. */
export const settingsGroups: { label: string; pages: NavPage[] }[] = [
  { label: t('店铺'), pages: [
    { name: 'settings-general', label: t('店铺信息') },
    { name: 'settings-billing', label: t('套餐与账单'), keywords: '订阅 付费 发票 billing plan invoices' },
    { name: 'settings-domains', label: t('域名') },
  ] },
  { label: t('收款与配送'), pages: [
    { name: 'settings-payments', label: t('收款'), keywords: 'Stripe PayPal 支付 payments stripe paypal' },
    { name: 'settings-shipping', label: t('运费'), keywords: '物流 配送 自提 shipping delivery pickup' },
    { name: 'settings-taxes', label: t('税费') },
    { name: 'settings-risk', label: t('风控'), keywords: '欺诈 黑名单 risk fraud blocklist' },
  ] },
  { label: t('国际化'), pages: [
    { name: 'settings-markets', label: t('市场'), keywords: '国家 站点 markets countries' },
    { name: 'settings-currencies', label: t('多币种'), keywords: '汇率 currencies exchange rates' },
    { name: 'settings-languages', label: t('语言') },
    { name: 'translations', label: t('翻译') },
  ] },
  { label: t('通知与追踪'), pages: [
    { name: 'settings-notifications', label: t('邮件通知') },
    { name: 'settings-tracking', label: t('追踪代码'), keywords: 'Google Analytics Meta 像素 tracking pixels google analytics meta' },
  ] },
  { label: t('团队与安全'), pages: [
    { name: 'settings-staff', label: t('员工'), keywords: '权限 staff permissions' },
    { name: 'settings-security', label: t('账户安全'), keywords: '两步验证 密码 security 2fa password' },
    { name: 'settings-activity', label: t('操作日志') },
  ] },
  { label: t('开发者'), pages: [{ name: 'settings-developers', label: t('开发者'), keywords: 'API Webhook developers api webhooks' }] },
]

/** Which section a route belongs to (detail pages map to their list page through meta.menu first). */
export function sectionOf(routeName: string): NavSection | null {
  for (const s of [...sections, appsSection]) {
    if (s.pages.some((p) => p.name === routeName || p.also?.includes(routeName)) || s.also?.includes(routeName)) return s
  }
  if (routeName === 'settings' || settingsGroups.some((g) => g.pages.some((p) => p.name === routeName))) return settingsSection
  return null
}

/** The tab (page) of its section a route belongs to. */
export function pageOf(routeName: string): NavPage | null {
  for (const s of [...sections, appsSection]) {
    const p = s.pages.find((x) => x.name === routeName || x.also?.includes(routeName))
    if (p) return p
  }
  return null
}
