<?php

/*
| Blocks shared by every theme (merged into each theme's schema by config/themes.php), page
| templates, and style kits. See docs/STOREFRONT-DESIGN-PLAN.md (phase B).
|
| Each block's "on" lists where it can be placed: home, page (campaign landing pages), product,
| collection, cart. Every block also gets a "visibility" field (all / desktop / mobile) from
| ThemeService::schema(). Templates with a "fixed" block always contain it (the page's own content).
*/

$slides = [];
foreach ([1, 2, 3] as $n) {
    $slides += [
        "slide{$n}_image" => ['type' => 'image', 'label' => "第 {$n} 张 · 图片（电脑）"],
        "slide{$n}_mobile_image" => ['type' => 'image', 'label' => "第 {$n} 张 · 图片（手机，可选，竖图更好）"],
        "slide{$n}_heading" => ['type' => 'text', 'label' => "第 {$n} 张 · 标题", 'max' => 100, 'default' => $n === 1 ? 'New Season' : ''],
        "slide{$n}_text" => ['type' => 'text', 'label' => "第 {$n} 张 · 说明", 'max' => 160, 'default' => $n === 1 ? 'Fresh styles just landed.' : ''],
        "slide{$n}_button" => ['type' => 'text', 'label' => "第 {$n} 张 · 按钮文字", 'max' => 40, 'default' => $n === 1 ? 'Shop now' : ''],
        "slide{$n}_link" => ['type' => 'url', 'label' => "第 {$n} 张 · 链接", 'default' => $n === 1 ? '/collections/all' : ''],
    ];
}
$circles = [];
foreach (range(1, 8) as $n) {
    $circles["collection_{$n}"] = ['type' => 'collection', 'label' => $n === 1 ? '分类 1（都不选则自动显示前 8 个分类）' : "分类 {$n}"];
}
$tabs = [];
foreach ([1, 2, 3] as $n) {
    $tabs += [
        "tab{$n}_label" => ['type' => 'text', 'label' => "第 {$n} 个标签 · 名称", 'max' => 30, 'default' => [1 => 'New in', 2 => 'Best sellers', 3 => 'On sale'][$n]],
        "tab{$n}_source" => ['type' => 'select', 'label' => "第 {$n} 个标签 · 商品", 'options' => ['newest' => '最新上架', 'bestsellers' => '本市场热卖', 'sale' => '正在打折', 'collection' => '某个分类'], 'default' => [1 => 'newest', 2 => 'bestsellers', 3 => 'sale'][$n]],
        "tab{$n}_collection" => ['type' => 'collection', 'label' => "第 {$n} 个标签 · 分类（选「某个分类」时）"],
    ];
}
$coupons = [];
foreach ([1, 2, 3] as $n) {
    $coupons += [
        "code{$n}" => ['type' => 'text', 'label' => "折扣码 {$n}", 'max' => 40, 'default' => ''],
        "label{$n}" => ['type' => 'text', 'label' => "折扣码 {$n} · 说明（留空自动显示优惠内容）", 'max' => 60, 'default' => ''],
    ];
}
$points = [];
foreach ([1, 2, 3] as $n) {
    $points += [
        "point{$n}_product" => ['type' => 'product', 'label' => "热点 {$n} · 商品"],
        "point{$n}_x" => ['type' => 'range', 'label' => "热点 {$n} · 横向位置", 'min' => 0, 'max' => 100, 'step' => 1, 'unit' => '%', 'default' => [1 => 30, 2 => 55, 3 => 75][$n]],
        "point{$n}_y" => ['type' => 'range', 'label' => "热点 {$n} · 纵向位置", 'min' => 0, 'max' => 100, 'step' => 1, 'unit' => '%', 'default' => [1 => 40, 2 => 60, 3 => 35][$n]],
    ];
}
$grid = [
    'columns' => ['type' => 'select', 'label' => '每行几个（电脑）', 'options' => ['3' => '3 个', '4' => '4 个', '5' => '5 个'], 'default' => '4'],
    'mobile_columns' => ['type' => 'select', 'label' => '每行几个（手机）', 'options' => ['1' => '1 个', '2' => '2 个'], 'default' => '2'],
];
$everywhere = ['home', 'page', 'product', 'collection', 'cart'];

return [
    'sections' => [
        // ── Home and campaign pages ──
        'slideshow' => [
            'label' => '轮播横幅', 'on' => ['home', 'page'],
            'fields' => [
                ...$slides,
                'height' => ['type' => 'select', 'label' => '高度', 'options' => ['small' => '小', 'medium' => '中', 'large' => '大'], 'default' => 'medium'],
                'autoplay' => ['type' => 'checkbox', 'label' => '自动播放', 'default' => true],
                'interval' => ['type' => 'range', 'label' => '切换间隔', 'min' => 3, 'max' => 10, 'step' => 1, 'unit' => '秒', 'default' => 5],
                'overlay' => ['type' => 'range', 'label' => '图片遮罩', 'min' => 0, 'max' => 70, 'step' => 5, 'unit' => '%', 'default' => 25],
            ],
        ],
        'category_circles' => [
            'label' => '圆形分类入口', 'on' => $everywhere,
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '标题（可选）', 'max' => 100, 'default' => ''],
                ...$circles,
                'shape' => ['type' => 'select', 'label' => '形状', 'options' => ['circle' => '圆形', 'rounded' => '圆角方形'], 'default' => 'circle'],
            ],
        ],
        'flash_sale' => [
            'label' => '限时抢购', 'on' => ['home', 'page'],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'Flash sale'],
                'ends_at' => ['type' => 'datetime', 'label' => '结束时间（店铺时区；不填则用正在进行的自动折扣的结束时间）'],
                'collection' => ['type' => 'collection', 'label' => '商品来源（不选则显示正在打折的商品）'],
                'limit' => ['type' => 'range', 'label' => '显示数量', 'min' => 2, 'max' => 12, 'step' => 1, 'default' => 8],
            ],
        ],
        'coupon_bar' => [
            'label' => '优惠券条', 'on' => $everywhere,
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '标题（可选）', 'max' => 100, 'default' => 'Exclusive coupons'],
                ...$coupons,
            ],
        ],
        'product_tabs' => [
            'label' => '标签页商品', 'on' => ['home', 'page'],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '标题（可选）', 'max' => 100, 'default' => ''],
                ...$tabs,
                'limit' => ['type' => 'range', 'label' => '每个标签显示几个', 'min' => 2, 'max' => 16, 'step' => 1, 'default' => 8],
                ...$grid,
            ],
        ],
        'market_bestsellers' => [
            'label' => '本市场热卖', 'on' => $everywhere,
            'fields' => [
                'eyebrow' => ['type' => 'text', 'label' => '小标题', 'max' => 60, 'default' => 'Trending'],
                'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'Best sellers'],
                'days' => ['type' => 'select', 'label' => '按最近多少天的销量', 'options' => ['7' => '7 天', '14' => '14 天', '30' => '30 天', '90' => '90 天'], 'default' => '30'],
                'limit' => ['type' => 'range', 'label' => '显示数量', 'min' => 2, 'max' => 16, 'step' => 1, 'default' => 8],
                ...$grid,
            ],
        ],
        'marquee' => [
            'label' => '跑马灯', 'on' => $everywhere,
            'fields' => [
                'text' => ['type' => 'textarea', 'label' => '文字（每行一条）', 'max' => 400, 'default' => "Free shipping over \$49\nEasy 30-day returns\nSecure checkout"],
                'speed' => ['type' => 'select', 'label' => '速度', 'options' => ['slow' => '慢', 'normal' => '中', 'fast' => '快'], 'default' => 'normal'],
                'style' => ['type' => 'select', 'label' => '颜色', 'options' => ['primary' => '主色', 'accent' => '点缀色', 'dark' => '深色'], 'default' => 'dark'],
            ],
        ],
        'trust_bar' => [
            'label' => '当地信任条', 'on' => $everywhere,
            'fields' => [
                'show_payments' => ['type' => 'checkbox', 'label' => '显示这个市场能用的支付方式', 'default' => true],
                'show_shipping' => ['type' => 'checkbox', 'label' => '显示免邮门槛（按运费设置自动计算）', 'default' => true],
                'show_duties' => ['type' => 'checkbox', 'label' => '显示关税说明（按市场的关税方式）', 'default' => true],
                'returns' => ['type' => 'text', 'label' => '退货说明（留空不显示）', 'max' => 80, 'default' => '30-day returns'],
            ],
        ],
        'ugc_wall' => [
            'label' => '买家秀墙', 'on' => $everywhere,
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'From our customers'],
                'limit' => ['type' => 'range', 'label' => '显示几张', 'min' => 3, 'max' => 24, 'step' => 1, 'default' => 8],
                'min_rating' => ['type' => 'select', 'label' => '只显示几星以上', 'options' => ['3' => '3 星', '4' => '4 星', '5' => '5 星'], 'default' => '4'],
            ],
        ],
        'hotspots' => [
            'label' => '场景图热点', 'on' => ['home', 'page'],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '标题（可选）', 'max' => 100, 'default' => 'Shop the look'],
                'image' => ['type' => 'image', 'label' => '场景图'],
                ...$points,
            ],
        ],

        // ── Product page ──
        'main_product' => [
            'label' => '商品信息（图片、价格、购买）', 'on' => ['product'], 'max_per_page' => 1,
            'fields' => [
                'show_perks' => ['type' => 'checkbox', 'label' => '显示购买保障', 'default' => true],
            ],
        ],
        'shipping_eta' => [
            'label' => '预计送达', 'on' => ['product'], 'max_per_page' => 1,
            'fields' => [
                'processing' => ['type' => 'range', 'label' => '备货天数', 'min' => 0, 'max' => 10, 'step' => 1, 'unit' => '天', 'default' => 1],
                'transit_min' => ['type' => 'range', 'label' => '运输最少天数', 'min' => 1, 'max' => 40, 'step' => 1, 'unit' => '天', 'default' => 5],
                'transit_max' => ['type' => 'range', 'label' => '运输最多天数', 'min' => 1, 'max' => 60, 'step' => 1, 'unit' => '天', 'default' => 10],
                'note' => ['type' => 'text', 'label' => '补充说明（可选）', 'max' => 120, 'default' => ''],
            ],
        ],
        'product_reviews' => [
            'label' => '商品评价', 'on' => ['product'], 'max_per_page' => 1,
            'fields' => [],
        ],
        'frequently_bought' => [
            'label' => '搭配购买', 'on' => ['product'], 'max_per_page' => 1,
            'fields' => [],
        ],
        'recently_viewed' => [
            'label' => '最近浏览', 'on' => $everywhere,
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'Recently viewed'],
                'limit' => ['type' => 'range', 'label' => '最多显示', 'min' => 2, 'max' => 12, 'step' => 1, 'default' => 6],
                ...$grid,
            ],
        ],
        'related_products' => [
            'label' => '相关推荐', 'on' => ['product'],
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'You may also like'],
                'source' => ['type' => 'select', 'label' => '推荐哪些商品', 'options' => ['related' => '同分类的商品', 'bestsellers' => '本市场热卖', 'newest' => '最新上架'], 'default' => 'related'],
                'limit' => ['type' => 'range', 'label' => '显示数量', 'min' => 2, 'max' => 12, 'step' => 1, 'default' => 4],
                ...$grid,
            ],
        ],

        // ── Collection page ──
        'collection_banner' => [
            'label' => '分类标题和介绍', 'on' => ['collection'], 'max_per_page' => 1,
            'fields' => [
                'show_image' => ['type' => 'checkbox', 'label' => '显示分类图片作为横幅', 'default' => false],
                'show_description' => ['type' => 'checkbox', 'label' => '显示分类介绍', 'default' => true],
                'align' => ['type' => 'select', 'label' => '对齐', 'options' => ['center' => '居中', 'left' => '左对齐'], 'default' => 'center'],
            ],
        ],
        'collection_grid' => [
            'label' => '商品列表（筛选、排序、分页）', 'on' => ['collection'], 'max_per_page' => 1,
            'fields' => [
                ...$grid,
                'show_sort' => ['type' => 'checkbox', 'label' => '显示排序', 'default' => true],
                'show_filters' => ['type' => 'checkbox', 'label' => '显示筛选（尺码、颜色、价格、有货）', 'default' => true],
            ],
        ],

        // ── Cart ──
        'cart_main' => [
            'label' => '购物车商品和结算', 'on' => ['cart'], 'max_per_page' => 1,
            'fields' => [],
        ],
        'cart_upsell' => [
            'label' => '凑单推荐', 'on' => ['cart'], 'max_per_page' => 1,
            'fields' => [
                'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'You might also like'],
                'limit' => ['type' => 'range', 'label' => '显示数量', 'min' => 2, 'max' => 8, 'step' => 1, 'default' => 4],
            ],
        ],
    ],

    // Where each of the theme's own blocks can go (blocks without an entry: home and campaign pages)
    'placement' => [
        'hero' => ['home', 'page'], 'collection_list' => ['home', 'page', 'collection'], 'featured_products' => $everywhere,
        'image_text' => $everywhere, 'rich_text' => $everywhere, 'newsletter' => $everywhere, 'features' => $everywhere,
        'faq' => $everywhere, 'video' => ['home', 'page', 'product', 'collection'], 'testimonials' => $everywhere,
    ],

    'templates' => [
        'product' => ['label' => '商品页', 'fixed' => 'main_product', 'default' => ['main_product', 'product_reviews', 'frequently_bought', 'related_products', 'recently_viewed']],
        'collection' => ['label' => '分类页', 'fixed' => 'collection_grid', 'default' => ['collection_banner', 'collection_grid']],
        'cart' => ['label' => '购物车', 'fixed' => 'cart_main', 'default' => ['cart_main', 'cart_upsell']],
        'page' => ['label' => '活动页', 'default' => ['hero', 'featured_products']],
    ],

    // Shape and spacing settings every theme gets (settings group "style")
    'style' => [
        'label' => '版式风格',
        'fields' => [
            'radius' => ['type' => 'select', 'label' => '圆角', 'options' => ['theme' => '主题默认', '0' => '直角', '6' => '小', '12' => '中', '20' => '大'], 'default' => 'theme'],
            'buttons' => ['type' => 'select', 'label' => '按钮形状', 'options' => ['theme' => '主题默认', 'square' => '方形', 'rounded' => '圆角', 'pill' => '胶囊'], 'default' => 'theme'],
            'cards' => ['type' => 'select', 'label' => '商品卡片', 'options' => ['theme' => '主题默认', 'plain' => '无边框', 'bordered' => '描边', 'shadow' => '阴影'], 'default' => 'theme'],
            'image_ratio' => ['type' => 'select', 'label' => '商品图片比例', 'options' => ['4/5' => '4:5 竖图', '3/4' => '3:4 竖图', '1/1' => '1:1 方图', '4/3' => '4:3 横图'], 'default' => '4/5'],
            'density' => ['type' => 'select', 'label' => '模块间距', 'options' => ['compact' => '紧凑（适合促销）', 'regular' => '标准', 'airy' => '宽松（适合品牌）'], 'default' => 'regular'],
        ],
    ],

    /*
     | Style kits: a starting look for a region. Applying one sets these settings in the editor (in a
     | market, they become the market's own). Colours keep the merchant's primary (brand) colour;
     | fonts only apply when the theme offers them.
     */
    'kits' => [
        'us_clean' => [
            'name' => '美国 · 大图简洁', 'description' => '白底黑字、留白多、粗体无衬线，突出品牌和大图',
            'settings' => ['colors.background' => '#FFFFFF', 'colors.text' => '#111111', 'colors.accent' => '#E8E8E8',
                'typography.heading_font' => ['Plus Jakarta Sans', 'Playfair Display'], 'typography.body_font' => 'Inter',
                'style.radius' => '6', 'style.buttons' => 'square', 'style.cards' => 'plain', 'style.image_ratio' => '3/4', 'style.density' => 'airy'],
        ],
        'eu_minimal' => [
            'name' => '欧洲 · 极简可信', 'description' => '低饱和、细字体、小圆角，信息克制，适合含税和退货说明',
            'settings' => ['colors.background' => '#FAFAF7', 'colors.text' => '#2B2B2B', 'colors.accent' => '#E9E4DA',
                'typography.heading_font' => ['DM Sans', 'Cormorant Garamond'], 'typography.body_font' => ['Inter', 'Lato'],
                'style.radius' => '0', 'style.buttons' => 'square', 'style.cards' => 'plain', 'style.image_ratio' => '4/5', 'style.density' => 'airy'],
        ],
        'latam_promo' => [
            'name' => '拉美 · 热闹促销', 'description' => '高饱和点缀色、大圆角胶囊按钮，适合优惠券、分期和限时抢购',
            'settings' => ['colors.background' => '#FFFFFF', 'colors.text' => '#1C1C1C', 'colors.accent' => '#FFD43B',
                'typography.heading_font' => ['Poppins', 'Playfair Display'], 'typography.body_font' => ['Jost', 'Inter'],
                'style.radius' => '20', 'style.buttons' => 'pill', 'style.cards' => 'shadow', 'style.image_ratio' => '1/1', 'style.density' => 'compact'],
        ],
        'sea_mobile' => [
            'name' => '东南亚 · 高密度移动端', 'description' => '红橙点缀、紧凑卡片、方图，首屏就能看到商品',
            'settings' => ['colors.background' => '#F6F6F6', 'colors.text' => '#222222', 'colors.accent' => '#FF5A36',
                'typography.heading_font' => ['Poppins', 'Playfair Display'], 'typography.body_font' => 'Inter',
                'style.radius' => '6', 'style.buttons' => 'rounded', 'style.cards' => 'bordered', 'style.image_ratio' => '1/1', 'style.density' => 'compact'],
        ],
        'me_luxe' => [
            'name' => '中东 · 深色金调', 'description' => '深底金色点缀、衬线标题，适合礼品和高客单价商品（配合阿语从右到左排版）',
            'settings' => ['colors.background' => '#FFFDF8', 'colors.text' => '#1F1A14', 'colors.accent' => '#C9A24B',
                'typography.heading_font' => ['Playfair Display', 'Cinzel'], 'typography.body_font' => ['Lato', 'Inter'],
                'style.radius' => '0', 'style.buttons' => 'square', 'style.cards' => 'plain', 'style.image_ratio' => '3/4', 'style.density' => 'airy'],
        ],
    ],
];
