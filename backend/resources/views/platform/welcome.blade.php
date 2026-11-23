<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lumée — 跨境独立站建站平台</title>
    <link rel="icon" type="image/svg+xml" href="/brand/platform-mark.svg">
    <link rel="stylesheet" href="/css/platform-tokens.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--surface); color: var(--ink); font-family: var(--font-body); overflow-x: hidden; }
        a { color: inherit; text-decoration: none; }
        .nav { border-bottom: 1px solid var(--border); }
        .nav-inner { max-width: 1120px; margin: 0 auto; padding: 16px; display: flex; align-items: center; justify-content: space-between; }
        .brand { display: flex; align-items: center; gap: 10px; font-family: var(--font-display); font-weight: 700; font-size: 20px; }
        .brand img { width: 32px; height: 32px; }
        .nav-links { display: flex; gap: 8px; align-items: center; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 10px 22px; border-radius: 8px; font-weight: 600; font-size: 15px; transition: background .15s; }
        .btn-primary { background: var(--brand-600); color: #fff; }
        .btn-primary:hover { background: var(--brand-700); }
        .btn-ghost { color: var(--ink); }
        .btn-ghost:hover { background: var(--surface-muted); }
        .hero { background: var(--gradient-soft); }
        .hero-inner { max-width: 1120px; margin: 0 auto; padding: 88px 16px 72px; text-align: center; }
        .pill { display: inline-block; padding: 6px 14px; border-radius: 999px; background: var(--brand-50); border: 1px solid var(--brand-100); color: var(--brand-700); font-size: 13px; font-weight: 600; }
        h1 { font-family: var(--font-display); font-weight: 700; font-size: clamp(32px, 5.5vw, 54px); line-height: 1.15; letter-spacing: -0.02em; margin: 20px auto 16px; max-width: 760px; }
        h1 em { font-style: normal; color: var(--brand-600); }
        .lead { color: var(--ink-muted); font-size: 18px; line-height: 1.7; max-width: 560px; margin: 0 auto 32px; }
        .cta .btn { padding: 14px 34px; font-size: 16px; }
        .note { margin-top: 14px; font-size: 13px; color: var(--ink-muted); }
        .features { max-width: 1120px; margin: 0 auto 88px; padding: 0 16px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .feature { border: 1px solid var(--border); border-radius: var(--radius-card); padding: 24px; }
        .feature .ic { width: 40px; height: 40px; border-radius: 10px; background: var(--brand-50); color: var(--brand-600); display: flex; align-items: center; justify-content: center; margin-bottom: 14px; }
        .feature h3 { font-family: var(--font-display); font-size: 17px; margin: 0 0 6px; }
        .feature p { color: var(--ink-muted); font-size: 14px; line-height: 1.6; margin: 0; }
        footer { text-align: center; color: var(--ink-muted); font-size: 13px; padding: 32px 16px; border-top: 1px solid var(--border); }
        @media (max-width: 900px) { .features { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .features { grid-template-columns: 1fr; } .nav-links .btn-ghost { display: none; } .lead { font-size: 16px; } .hero-inner { padding-top: 56px; } }
    </style>
</head>
<body>
    <nav class="nav">
        <div class="nav-inner">
            <a class="brand" href="/"><img src="/brand/platform-mark.svg" alt="">Lumée</a>
            <div class="nav-links">
                <a class="btn btn-ghost" href="{{ config('lumee.admin_url') }}/login">登录</a>
                <a class="btn btn-primary" href="{{ config('lumee.admin_url') }}/login">免费开店</a>
            </div>
        </div>
    </nav>

    <section class="hero">
        <div class="hero-inner">
            <span class="pill">跨境独立站 · 一站式建站平台</span>
            <h1>几分钟开一家<em>卖向全球</em>的独立站</h1>
            <p class="lead">一个后台管理多个国家站点：AI 帮你装修，选品、收款、物流、营销一站搞定，专注把生意做大。</p>
            <div class="cta"><a class="btn btn-primary" href="{{ config('lumee.admin_url') }}/login">免费开店</a></div>
            <p class="note">14 天免费试用 · 无需信用卡</p>
        </div>
    </section>

    @php($icon = fn ($d) => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$d.'</svg>')
    <section class="features">
        <div class="feature">
            <div class="ic">{!! $icon('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>') !!}</div>
            <h3>一个国家一个站点</h3>
            <p>墨西哥、巴西、欧洲、中东……每个市场有自己的币种、语言、价格和支付方式，阿拉伯语自动从右到左排版。</p>
        </div>
        <div class="feature">
            <div class="ic">{!! $icon('<path d="M12 3l2.5 5.5L20 11l-5.5 2.5L12 19l-2.5-5.5L4 11l5.5-2.5z"/>') !!}</div>
            <h3>AI 一键装修</h3>
            <p>用中文说需求，AI 按当地语言和购物习惯生成整套页面；也能按地区风格自动装修，先预览再发布。</p>
        </div>
        <div class="feature">
            <div class="ic">{!! $icon('<path d="M4 5h16v14H4z"/><path d="M4 9h16M9 9v10"/>') !!}</div>
            <h3>拖拽装修 · 节日自动换装</h3>
            <p>实时预览的模块化编辑器，每个国家站点可以单独装修；节日活动按日历自动上线、结束自动恢复。</p>
        </div>
        <div class="feature">
            <div class="ic">{!! $icon('<path d="M4 19V5M4 19h16M8 15l3-4 3 2 5-6"/>') !!}</div>
            <h3>用数据改版</h3>
            <p>设计 A/B 测试、各国站点表现看板、每周改版建议，哪个版本卖得更好一目了然。</p>
        </div>
        <div class="feature">
            <div class="ic">{!! $icon('<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M8 11h6M11 8v6"/>') !!}</div>
            <h3>选品与货源</h3>
            <p>选品雷达找潜力商品，一键采集上架，内容工坊优化文案和图片，采购单直连供应商。</p>
        </div>
        <div class="feature">
            <div class="ic">{!! $icon('<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M7 15h3"/>') !!}</div>
            <h3>全球收款 · 当地支付</h3>
            <p>信用卡、Apple Pay、PayPal，以及 OXXO、Pix、Boleto、分期、货到付款等当地方式。</p>
        </div>
        <div class="feature">
            <div class="ic">{!! $icon('<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7M12 11v10"/>') !!}</div>
            <h3>订单、物流与 B2B</h3>
            <p>订单集中管理、物流追踪、退货退款；批发价格表、报价单和分销商目录一并支持。</p>
        </div>
        <div class="feature">
            <div class="ic">{!! $icon('<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>') !!}</div>
            <h3>为手机而生</h3>
            <p>图片按屏幕自动压缩、页面缓存、筛选和最近浏览，买家还能把店铺添加到手机桌面。</p>
        </div>
    </section>

    <footer>&copy; {{ date('Y') }} Lumée · 让每个品牌都能出海</footer>
</body>
</html>
