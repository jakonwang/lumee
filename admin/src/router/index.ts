import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/invite/:token', name: 'invite', component: () => import('@/views/InviteAcceptView.vue'), meta: { public: true } },
    { path: '/oauth/authorize', name: 'oauth-authorize', component: () => import('@/views/apps/OAuthAuthorizeView.vue'), meta: { title: t('安装应用') } },
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true } },
    { path: '/unavailable', name: 'unavailable', component: () => import('@/views/UnavailableView.vue'), meta: { public: true } },
    {
      path: '/',
      component: () => import('@/layouts/AdminLayout.vue'),
      children: [
        { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { title: t('首页') } },
        { path: 'products', name: 'products', component: () => import('@/views/products/ProductListView.vue'), meta: { perm: 'products', title: t('商品') } },
        { path: 'products/new', name: 'product-new', component: () => import('@/views/products/ProductEditView.vue'), meta: { perm: 'products', title: t('添加商品'), menu: 'products' } },
        { path: 'products/:id(\\d+)', name: 'product-edit', component: () => import('@/views/products/ProductEditView.vue'), meta: { perm: 'products', title: t('编辑商品'), menu: 'products' } },
        { path: 'orders', name: 'orders', component: () => import('@/views/orders/OrderListView.vue'), meta: { perm: 'orders', title: t('订单') } },
        { path: 'orders/abandoned', name: 'abandoned-checkouts', component: () => import('@/views/orders/AbandonedCheckoutsView.vue'), meta: { perm: 'orders', title: t('弃单') } },
        { path: 'orders/subscriptions', name: 'subscriptions', component: () => import('@/views/orders/SubscriptionsView.vue'), meta: { perm: 'orders', title: t('订阅') } },
        { path: 'orders/returns', name: 'returns', component: () => import('@/views/orders/ReturnsView.vue'), meta: { perm: 'orders', title: t('退货') } },
        { path: 'orders/new', name: 'order-new', component: () => import('@/views/orders/ManualOrderView.vue'), meta: { perm: 'orders', title: t('创建订单'), menu: 'orders' } },
        { path: 'orders/:id', name: 'order', component: () => import('@/views/orders/OrderDetailView.vue'), meta: { perm: 'orders', title: t('订单详情'), menu: 'orders' } },
        { path: 'customers', name: 'customers', component: () => import('@/views/CustomerListView.vue'), meta: { perm: 'customers', title: t('客户') } },
        { path: 'customers/companies', name: 'companies', component: () => import('@/views/customers/CompaniesView.vue'), meta: { perm: 'customers', title: t('公司') } },
        { path: 'leads', name: 'leads', component: () => import('@/views/leads/LeadFinderView.vue'), meta: { perm: 'customers', title: t('获客雷达') } },
        { path: 'customers/quotes', name: 'quotes', component: () => import('@/views/customers/QuotesView.vue'), meta: { perm: 'customers', title: t('询价单') } },
        { path: 'customers/:id(\\d+)', name: 'customer', component: () => import('@/views/CustomerDetailView.vue'), meta: { perm: 'customers', title: t('客户详情'), menu: 'customers' } },
        { path: 'marketing/affiliates', name: 'affiliates', component: () => import('@/views/marketing/AffiliatesView.vue'), meta: { perm: 'marketing', title: t('营销 · 联盟营销') } },
        { path: 'marketing/creators', name: 'creators', component: () => import('@/views/marketing/CreatorsView.vue'), meta: { perm: 'marketing', title: t('营销 · 达人合作') } },
        { path: 'marketing/seo-pages', name: 'seo-pages', component: () => import('@/views/marketing/SeoPagesView.vue'), meta: { perm: 'marketing', title: t('营销 · SEO 落地页') } },
        { path: 'marketing/points', name: 'loyalty', component: () => import('@/views/marketing/LoyaltyView.vue'), meta: { perm: 'marketing', title: t('营销 · 积分') } },
        { path: 'marketing/flows', name: 'flows', component: () => import('@/views/marketing/FlowListView.vue'), meta: { perm: 'marketing', title: t('营销 · 自动化') } },
        { path: 'marketing/flows/:id(\\d+)', name: 'flow-edit', component: () => import('@/views/marketing/FlowEditView.vue'), meta: { perm: 'marketing', title: t('编辑自动化'), menu: 'flows' } },
        { path: 'marketing/email', name: 'campaigns', component: () => import('@/views/marketing/CampaignListView.vue'), meta: { perm: 'marketing', title: t('营销 · 邮件营销') } },
        { path: 'marketing/email/new', name: 'campaign-new', component: () => import('@/views/marketing/CampaignEditView.vue'), meta: { perm: 'marketing', title: t('写邮件'), menu: 'campaigns' } },
        { path: 'marketing/email/:id(\\d+)', name: 'campaign-edit', component: () => import('@/views/marketing/CampaignEditView.vue'), meta: { perm: 'marketing', title: t('编辑邮件'), menu: 'campaigns' } },
        { path: 'marketing/promotions', name: 'promotions', component: () => import('@/views/marketing/PromotionListView.vue'), meta: { perm: 'marketing', title: t('营销 · 自动折扣') } },
        { path: 'marketing/promotions/new', name: 'promotion-new', component: () => import('@/views/marketing/PromotionEditView.vue'), meta: { perm: 'marketing', title: t('创建活动'), menu: 'promotions' } },
        { path: 'marketing/promotions/:id(\\d+)', name: 'promotion-edit', component: () => import('@/views/marketing/PromotionEditView.vue'), meta: { perm: 'marketing', title: t('编辑活动'), menu: 'promotions' } },
        { path: 'marketing', name: 'marketing', component: () => import('@/views/marketing/DiscountListView.vue'), meta: { perm: 'marketing', title: t('营销 · 折扣码') } },
        { path: 'analytics', name: 'analytics', component: () => import('@/views/AnalyticsView.vue'), meta: { perm: 'analytics', title: t('数据分析') } },
        { path: 'analytics/ads', name: 'ad-performance', component: () => import('@/views/analytics/AdPerformanceView.vue'), meta: { perm: 'analytics', title: t('数据分析 · 广告效果') } },
        { path: 'reviews', name: 'reviews', component: () => import('@/views/products/ReviewListView.vue'), meta: { perm: 'products', title: t('评论') } },
        { path: 'purchasing', name: 'purchasing', component: () => import('@/views/products/PurchasingView.vue'), meta: { perm: 'products', title: t('采购') } },
        { path: 'wholesale-catalogs', name: 'wholesale-catalogs', component: () => import('@/views/products/WholesaleCatalogsView.vue'), meta: { perm: 'products', title: t('批发目录') } },
        { path: 'research', name: 'research', component: () => import('@/views/products/ResearchView.vue'), meta: { perm: 'products', title: t('选品雷达') } },
        { path: 'testing', name: 'testing', component: () => import('@/views/products/TestingView.vue'), meta: { perm: 'products', title: t('测款') } },
        { path: 'content', name: 'content', component: () => import('@/views/products/ContentStudioView.vue'), meta: { perm: 'products', title: t('内容工坊') } },
        { path: 'collect', name: 'collect', component: () => import('@/views/products/CollectView.vue'), meta: { perm: 'products', title: t('商品采集') } },
        { path: 'price-lists', name: 'price-lists', component: () => import('@/views/products/PriceListsView.vue'), meta: { perm: 'products', title: t('批发价') } },
        { path: 'gift-cards', name: 'gift-cards', component: () => import('@/views/products/GiftCardsView.vue'), meta: { perm: 'products', title: t('礼品卡') } },
        { path: 'collections', name: 'collections', component: () => import('@/views/collections/CollectionListView.vue'), meta: { perm: 'products', title: t('商品分类') } },
        { path: 'collections/new', name: 'collection-new', component: () => import('@/views/collections/CollectionEditView.vue'), meta: { perm: 'products', title: t('新建分类'), menu: 'collections' } },
        { path: 'collections/:id(\\d+)', name: 'collection-edit', component: () => import('@/views/collections/CollectionEditView.vue'), meta: { perm: 'products', title: t('编辑分类'), menu: 'collections' } },
        { path: 'online-store/blog', name: 'blog', component: () => import('@/views/online-store/BlogListView.vue'), meta: { perm: 'online_store', title: t('博客') } },
        { path: 'online-store/blog/new', name: 'post-new', component: () => import('@/views/online-store/BlogEditView.vue'), meta: { perm: 'online_store', title: t('写文章'), menu: 'blog' } },
        { path: 'online-store/blog/:id(\\d+)', name: 'post-edit', component: () => import('@/views/online-store/BlogEditView.vue'), meta: { perm: 'online_store', title: t('编辑文章'), menu: 'blog' } },
        { path: 'online-store/pages', name: 'pages', component: () => import('@/views/online-store/PageListView.vue'), meta: { perm: 'online_store', title: t('页面') } },
        { path: 'online-store/pages/new', name: 'page-new', component: () => import('@/views/online-store/PageEditView.vue'), meta: { perm: 'online_store', title: t('添加页面'), menu: 'pages' } },
        { path: 'online-store/pages/:id(\\d+)', name: 'page-edit', component: () => import('@/views/online-store/PageEditView.vue'), meta: { perm: 'online_store', title: t('编辑页面'), menu: 'pages' } },
        { path: 'online-store/translations', name: 'translations', component: () => import('@/views/online-store/TranslationsView.vue'), meta: { perm: 'online_store', title: t('翻译') } },
        { path: 'online-store/navigation', name: 'navigation', component: () => import('@/views/online-store/NavigationView.vue'), meta: { perm: 'online_store', title: t('导航菜单') } },
        { path: 'online-store/assistant', name: 'assistant', component: () => import('@/views/online-store/AssistantView.vue'), meta: { perm: 'online_store', title: t('AI 导购') } },
        { path: 'online-store/design-performance', name: 'design-performance', component: () => import('@/views/online-store/DesignPerformanceView.vue'), meta: { perm: 'online_store', title: t('装修效果') } },
        { path: 'online-store/redirects', name: 'redirects', component: () => import('@/views/online-store/RedirectsView.vue'), meta: { perm: 'online_store', title: t('网址重定向') } },
        { path: 'online-store', name: 'online-store', component: () => import('@/views/online-store/ThemesView.vue'), meta: { perm: 'online_store', title: t('主题') } },
        { path: 'online-store/themes/:id(\\d+)/editor', name: 'theme-editor', component: () => import('@/views/ThemeEditorView.vue'), meta: { perm: 'online_store', title: t('店铺装修'), fullBleed: true, menu: 'online-store' } },
        { path: 'settings', name: 'settings', redirect: () => ({ name: useAuth().can('settings') ? 'settings-general' : 'settings-staff' }) },
        { path: 'settings/general', name: 'settings-general', component: () => import('@/views/settings/StoreSettingsView.vue'), meta: { perm: 'settings', title: t('设置 · 店铺信息'), menu: 'settings' } },
        { path: 'settings/payments', name: 'settings-payments', component: () => import('@/views/settings/PaymentSettingsView.vue'), meta: { perm: 'settings', title: t('设置 · 收款'), menu: 'settings' } },
        { path: 'settings/shipping', name: 'settings-shipping', component: () => import('@/views/settings/ShippingSettingsView.vue'), meta: { perm: 'settings', title: t('设置 · 运费'), menu: 'settings' } },
        { path: 'settings/billing', name: 'settings-billing', component: () => import('@/views/settings/BillingView.vue'), meta: { title: t('设置 · 套餐与账单'), menu: 'settings' } },
        { path: 'settings/tracking', name: 'settings-tracking', component: () => import('@/views/settings/TrackingSettingsView.vue'), meta: { perm: 'marketing', title: t('营销 · 追踪代码') } },
        { path: 'stores', name: 'stores', component: () => import('@/views/stores/StoresOverviewView.vue'), meta: { title: t('所有店铺') } },
        { path: 'apps', name: 'apps', component: () => import('@/views/apps/AppsView.vue'), meta: { perm: 'settings', title: t('应用') } },
        { path: 'apps/charges/:token', name: 'app-charge', component: () => import('@/views/apps/AppChargeView.vue'), meta: { perm: 'settings', title: t('应用收费'), menu: 'apps' } },
        { path: 'apps/open/:id', name: 'app-embedded', component: () => import('@/views/apps/EmbeddedAppView.vue'), meta: { perm: 'settings', title: t('应用'), menu: 'apps' } },
        { path: 'partner/payouts', name: 'partner-payouts', component: () => import('@/views/apps/PartnerPayoutsView.vue'), meta: { title: t('收入与结算') } },
        { path: 'customers/privacy', name: 'privacy-requests', component: () => import('@/views/customers/PrivacyRequestsView.vue'), meta: { perm: 'settings', title: t('隐私请求') } },
        { path: 'partner/themes', name: 'partner-themes', component: () => import('@/views/apps/PartnerThemesView.vue'), meta: { title: t('我的主题（开发者）') } },
        { path: 'partner/apps', name: 'partner-apps', component: () => import('@/views/apps/PartnerAppsView.vue'), meta: { title: t('我的应用（开发者）') } },
        { path: 'settings/developers', name: 'settings-developers', component: () => import('@/views/settings/DeveloperSettingsView.vue'), meta: { perm: 'settings', title: t('设置 · 开发者'), menu: 'settings' } },
        { path: 'settings/risk', name: 'settings-risk', component: () => import('@/views/settings/RiskSettingsView.vue'), meta: { perm: 'settings', title: t('设置 · 风控'), menu: 'settings' } },
        { path: 'settings/taxes', name: 'settings-taxes', component: () => import('@/views/settings/TaxSettingsView.vue'), meta: { perm: 'settings', title: t('设置 · 税费'), menu: 'settings' } },
        { path: 'settings/languages', name: 'settings-languages', component: () => import('@/views/settings/LanguageSettingsView.vue'), meta: { perm: 'settings', title: t('设置 · 语言'), menu: 'settings' } },
        { path: 'settings/markets', name: 'settings-markets', component: () => import('@/views/settings/MarketSettingsView.vue'), meta: { perm: 'settings', title: t('设置 · 市场'), menu: 'settings' } },
        { path: 'settings/currencies', name: 'settings-currencies', component: () => import('@/views/settings/CurrencySettingsView.vue'), meta: { perm: 'settings', title: t('设置 · 多币种'), menu: 'settings' } },
        { path: 'settings/domains', name: 'settings-domains', component: () => import('@/views/settings/DomainSettingsView.vue'), meta: { perm: 'settings', title: t('设置 · 域名'), menu: 'settings' } },
        { path: 'settings/security', name: 'settings-security', component: () => import('@/views/settings/SecuritySettingsView.vue'), meta: { title: t('设置 · 账户安全'), menu: 'settings' } },
        { path: 'settings/activity', name: 'settings-activity', component: () => import('@/views/settings/ActivityLogView.vue'), meta: { perm: 'settings', title: t('设置 · 操作日志'), menu: 'settings' } },
        { path: 'settings/staff', name: 'settings-staff', component: () => import('@/views/settings/StaffSettingsView.vue'), meta: { perm: 'staff', title: t('设置 · 员工'), menu: 'settings' } },
        { path: 'settings/notifications', name: 'settings-notifications', component: () => import('@/views/settings/NotificationSettingsView.vue'), meta: { perm: 'settings', title: t('设置 · 邮件通知'), menu: 'settings' } },
      ],
    },
    {
      path: '/ops',
      component: () => import('@/layouts/OpsLayout.vue'),
      meta: { ops: true },
      children: [
        { path: '', name: 'ops', component: () => import('@/views/ops/OpsOverviewView.vue'), meta: { ops: true, title: t('平台概览') } },
        { path: 'stores', name: 'ops-stores', component: () => import('@/views/ops/OpsStoresView.vue'), meta: { ops: true, title: t('店铺') } },
        { path: 'stores/:id(\\d+)', name: 'ops-store', component: () => import('@/views/ops/OpsStoreView.vue'), meta: { ops: true, title: t('店铺详情'), menu: 'ops-stores' } },
        { path: 'payouts', name: 'ops-payouts', component: () => import('@/views/ops/OpsPayoutsView.vue'), meta: { ops: true, title: t('开发者结算') } },
        { path: 'themes', name: 'ops-themes', component: () => import('@/views/ops/OpsThemesView.vue'), meta: { ops: true, title: t('主题审核') } },
        { path: 'apps', name: 'ops-apps', component: () => import('@/views/ops/OpsAppsView.vue'), meta: { ops: true, title: t('应用审核') } },
        { path: 'invoices', name: 'ops-invoices', component: () => import('@/views/ops/OpsInvoicesView.vue'), meta: { ops: true, title: t('平台账单') } },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuth()
  if (to.meta.public) return true
  if (to.meta.guest) return auth.isLoggedIn ? '/' : true
  if (!auth.isLoggedIn) return to.fullPath === '/' ? '/login' : { path: '/login', query: { redirect: to.fullPath } }
  if (!auth.user) {
    try {
      await auth.fetchMe()
    } catch (e) {
      // Signed out (401 clears the token): sign in again. Anything else (server down, network) isn't a sign-in problem —
      // sending a signed-in user to /login would bounce straight back here and end in a blank page.
      const status = (e as { response?: { status?: number } }).response?.status
      return status === 401 ? '/login' : { name: 'unavailable', query: { redirect: to.fullPath } }
    }
  }
  // Ops console: Lumée staff only
  if (to.meta.ops && !auth.user?.is_platform_admin) return '/'
  // Pages for areas this member can't access → home (the API would refuse anyway)
  if (typeof to.meta.perm === 'string' && !auth.can(to.meta.perm)) return { name: 'dashboard', query: { denied: '1' } }
  return true
})
