# Lumée 跨境独立站 SaaS 规划（对标 Shopify / 店匠 Shoplazza）

> 版本 v0.1 · 2026-11-17

---

## 1. 产品定位

一个**多租户建站 SaaS**：跨境卖家注册 → 选模板 → 上架商品 → 绑定独立域名 → 接入 Stripe/PayPal 收款 → 卖向全球。

| 角色 | 产品端 | 说明 |
|---|---|---|
| 访客 | **营销官网** `lumee.com` | 介绍、定价、模板市场、注册入口 |
| 商家 | **商家后台** `admin.lumee.com` | 商品、订单、客户、营销、主题装修、设置 |
| 消费者 | **店铺前台** `xxx.lumee.shop` / 商家自有域名 | 每个店铺独立的商城网站 |
| 平台 | **平台运营后台** `ops.lumee.com` | 商家管理、套餐计费、审核、风控、数据 |
| 开发者（后期） | 开放 API + 应用市场 | 第三方插件、Webhook |

差异化建议：以「轻奢 / 珠宝 / 女性饰品」垂类为切入点（和 logo 气质一致）——自带珠宝类模板、材质/尺寸/刻字等专属商品属性、精致的视觉，先做透一个垂类再泛化。

---

## 2. 技术架构

结合本机已有 phpStudy（PHP 8.2 / Nginx / Redis），推荐：

| 层 | 选型 | 理由 |
|---|---|---|
| 后端 API | **Laravel 11 (PHP 8.2)** | 生态成熟，队列/调度/鉴权全包，开发快 |
| 数据库 | **MySQL 8.0**（本机 5.7 建议升级） | JSON 字段、窗口函数、更好的索引 |
| 缓存/队列 | Redis + Laravel Horizon | 订单、邮件、Webhook、库存异步 |
| 商家后台 | **Vue 3 + Vite + TypeScript + Naive UI** | SPA，组件丰富，可按品牌色定制主题 |
| 店铺前台 | **Laravel Blade/Twig 主题引擎（服务端渲染）** | SEO 友好；主题 = 模板 + JSON 配置，类似 Shopify Liquid |
| 主题装修器 | Vue 3 拖拽编辑器 + iframe 实时预览 | Section / Block 架构 |
| 搜索 | Meilisearch（初期 MySQL 全文即可） | 商品搜索、筛选 |
| 文件存储 | S3 兼容（Cloudflare R2 / 阿里云 OSS）+ CDN | 图片多尺寸、WebP |
| 自定义域名 + HTTPS | **Caddy On-Demand TLS** 或 Cloudflare for SaaS | 商家绑定域名自动签证书 |
| 部署 | Docker + 海外云（AWS / GCP / 阿里云国际） | 前台面向海外买家，服务器放海外 |

### 多租户方案
- **共享数据库 + `store_id` 隔离**（Shopify 早期同款），每张业务表带 `store_id`，Laravel 全局 Scope 自动过滤。
- 请求进入时按 Host 解析店铺：`xxx.lumee.shop` 或 `domains` 表查自定义域名 → 注入当前 Store 上下文。
- 后期大商家可拆分独立库（分片）。

```
          ┌──────────── Cloudflare / CDN ────────────┐
买家 ───▶ │  *.lumee.shop  |  商家自有域名 (TLS)      │
          └───────────────┬──────────────────────────┘
                          ▼
                 Nginx / Caddy 网关
     ┌──────────────┬──────────────┬──────────────┐
     ▼              ▼              ▼              ▼
 Storefront    Admin API      Platform API    Webhooks
 (SSR 主题)    (商家后台)      (运营后台)      (Stripe/PayPal)
     └──────────────┴──────┬───────┴──────────────┘
                           ▼
              Laravel 核心  ── Redis 队列 ── Workers
                           ▼
                 MySQL 8 · R2/OSS · Meilisearch
```

---

## 3. 功能模块与分期

### 阶段一：MVP（约 3 个月）——能开店、能卖货
- **账号与店铺**：注册/登录、创建店铺、员工子账号与权限
- **商品**：商品/多规格 SKU、图片、分类(Collections)、库存、SEO 字段、CSV 导入
- **店铺前台**：首页、列表、详情、购物车、结账（单页 Checkout）、会员中心、订单查询
- **主题**：1~2 套官方主题（含 Lumée 珠宝风）、基础装修（Logo、颜色、Banner、导航、页脚）
- **订单**：订单列表、发货（填物流单号）、退款、订单邮件通知
- **支付**：Stripe（信用卡 / Apple Pay / Google Pay）、PayPal
- **物流**：按国家/重量设置运费模板
- **多币种 / 多语言**：前台货币切换（汇率自动更新）、中英文后台、前台多语言
- **域名**：二级域名 + 绑定自有域名 + 自动 HTTPS
- **平台计费**：套餐订阅（月/年付）、14 天试用、交易抽佣

### 阶段二：增长（+3 个月）——能营销
- 折扣码、满减、限时折扣、捆绑销售
- 弃单挽回邮件、邮件营销、优惠券弹窗
- 拖拽式页面装修器（Section/Block）、自定义页面、博客
- Facebook Pixel / GA4 / TikTok Pixel / Google Merchant Feed
- 评论系统（带图评论、导入）
- 数据分析仪表盘（销售、转化漏斗、流量来源）
- 更多支付：Airwallex、Klarna、本地支付；物流轨迹（17Track）

### 阶段三：平台化（+6 个月）
- 开放 API（REST/GraphQL）+ Webhook + OAuth 应用市场
- 主题市场（第三方开发者上传主题）
- 多店铺管理、B2B 批发价、订阅制商品
- 风控（欺诈订单识别）、税费（欧盟 VAT / 美国销售税）
- AI 能力：商品描述生成、多语言翻译、智能客服

---

## 4. 核心数据模型（简化）

```
platform: users · stores · plans · subscriptions · invoices · domains
store   : staff · roles · settings · themes · theme_settings · pages · menus
catalog : products · product_variants · product_options · product_images
          collections · collection_product · inventory_levels
sales   : customers · addresses · carts · cart_items · checkouts
          orders · order_items · payments · refunds · fulfillments
marketing: discounts · discount_usages · email_campaigns
system  : currencies · exchange_rates · translations · webhooks · audit_logs
```
所有店铺级表均含 `store_id` 且建联合索引 `(store_id, ...)`；金额统一存「最小货币单位整数」+ 币种字段。

---

## 5. 目录结构建议

```
lumee/
├── backend/            # Laravel 11（API + 前台 SSR + 主题引擎）
│   ├── app/Domain/     # 按领域分：Store, Catalog, Order, Payment, Theme, Billing…
│   ├── themes/         # 官方主题（lumee-aurora …）
│   └── routes/{admin,storefront,platform,webhook}.php
├── admin/              # 商家后台 Vue3 + Naive UI
├── ops/                # 平台运营后台 Vue3
├── website/            # 营销官网（Nuxt 或静态）
├── packages/ui-tokens/ # 品牌设计 Token（色板、字体，供各端共用）
└── docs/
```

---

## 6. 品牌视觉规范（取自 logo）

整体气质：**玫瑰金 + 象牙白底 + 马卡龙宝石色点缀**，轻奢、柔和、通透。

### 色板

| Token | 色值 | 用途 |
|---|---|---|
| `--rose-gold-700` | `#8A6655` | 正文强调、按钮悬停、可读性文字 |
| `--rose-gold-500` | `#B08A78` | **主色**：按钮、链接、Logo 文字 |
| `--rose-gold-300` | `#D6BCAE` | 边框、分割线 |
| `--rose-gold-100` | `#F3E9E3` | 卡片/选中背景 |
| `--ivory` | `#FDFBF9` | 页面底色 |
| `--gem-lavender` | `#C9B4E8` | 点缀 1（紫水晶） |
| `--gem-blush` | `#F2B5BC` | 点缀 2（粉晶）、促销角标 |
| `--gem-aqua` | `#9FD8E0` | 点缀 3（海蓝宝）、成功/信息 |
| `--gem-citrine` | `#F2E19B` | 点缀 4（黄水晶）、提示 |
| `--ink` | `#3E3330` | 主文字（暖深棕，替代纯黑） |
| `--ink-muted` | `#8C7F79` | 次要文字 |

规则：
- 大面积用象牙白 + 玫瑰金，宝石色只做**点缀**（标签、插画、图表、渐变高光），单屏不超过 2 种。
- 文字不用纯黑，用 `--ink`；玫瑰金 500 在白底上做小字对比度偏低，小字用 700。
- 品牌渐变（Hero / 装饰）：`linear-gradient(135deg, #C9B4E8 0%, #F2B5BC 35%, #9FD8E0 70%, #F2E19B 100%)`，低透明度使用。
- 金属质感按钮：`linear-gradient(180deg, #C9A391, #A97F6C)`。

### 字体
- 英文标题：**Cormorant Garamond** / Cinzel（衬线、大字距，呼应 L U M É E）
- 英文正文：Inter / Jost
- 中文：思源宋体（标题）、思源黑体（正文）
- 小标题样式：全大写 + `letter-spacing: 0.3em`，如 `— JEWELRY —`

### 组件风格
- 圆角 12px（卡片）/ 999px（按钮、标签）
- 阴影极轻：`0 4px 20px rgba(176,138,120,.12)`
- 图标：细线条 1.5px 描边
- 大量留白，商品图用浅暖灰底 `#F7F2EF`

---

## 7. 关键风险与注意事项
1. **支付合规**：卡号绝不经过自己服务器，用 Stripe Elements / PayPal 托管组件，规避 PCI 负担；平台抽佣用 Stripe Connect。
2. **隐私合规**：GDPR / CCPA、Cookie 同意、数据导出删除。
3. **性能**：前台首屏 < 2s（海外 CDN、图片 WebP、页面缓存）——直接影响转化。
4. **多租户安全**：所有查询强制 `store_id` 作用域，加自动化测试防越权。
5. **商标**：「Lumée」需在目标市场（美/欧）查询并注册商标；logo 中的 “JEWELRY” 若作为 SaaS 平台品牌需调整副标。

---

## 8. 下一步
1. 确认技术栈（本文推荐 Laravel + Vue3）
2. 搭建项目骨架 + 设计 Token + 多租户基础（店铺/域名解析）
3. 出商家后台与珠宝主题的高保真设计稿
4. 按阶段一顺序开发：账号店铺 → 商品 → 前台 → 购物车结账 → 支付 → 订单
