<?php

/*
| Theme schemas. Each theme declares:
|  - settings: global groups (colors, typography, header, footer…) → fields
|  - sections: section types merchants can add to the home page → fields
|  - default_sections: the home page a new store starts with
|
| Field types: text, textarea, url, color, select, range, checkbox, image, collection
| The admin editor renders its forms from this schema, and ThemeService
| sanitizes every saved value against it — so nothing outside the schema is stored.
*/

$headingFonts = [
    'Cormorant Garamond' => 'Cormorant Garamond（优雅衬线）',
    'Playfair Display' => 'Playfair Display（杂志感）',
    'Cinzel' => 'Cinzel（古典大写）',
    'Marcellus' => 'Marcellus（柔和罗马体）',
    'Noto Serif SC' => '思源宋体',
];
$bodyFonts = [
    'Inter' => 'Inter（清晰现代）',
    'Jost' => 'Jost（几何感）',
    'Lato' => 'Lato（圆润友好）',
    'Noto Sans SC' => '思源黑体',
];

$aurora = [
        'name' => 'Aurora',
        'placeholder' => '/brand/logo-mark.png',   // shown for products without a photo
        'description' => '轻奢珠宝风格：玫瑰金、象牙白、宝石色点缀',

        'settings' => [
            'colors' => [
                'label' => '颜色',
                'fields' => [
                    'primary' => ['type' => 'color', 'label' => '主色（按钮、链接、价格）', 'default' => '#B08A78'],
                    'background' => ['type' => 'color', 'label' => '页面背景', 'default' => '#FDFBF9'],
                    'text' => ['type' => 'color', 'label' => '文字颜色', 'default' => '#3E3330'],
                    'accent' => ['type' => 'color', 'label' => '点缀色（促销标签）', 'default' => '#F2B5BC'],
                ],
            ],
            'typography' => [
                'label' => '字体',
                'fields' => [
                    'heading_font' => ['type' => 'select', 'label' => '标题字体', 'options' => $headingFonts, 'default' => 'Cormorant Garamond'],
                    'body_font' => ['type' => 'select', 'label' => '正文字体', 'options' => $bodyFonts, 'default' => 'Inter'],
                ],
            ],
            'header' => [
                'label' => '页头',
                'fields' => [
                    'logo' => ['type' => 'image', 'label' => 'Logo 图片（不上传则显示店铺名称）'],
                    'logo_height' => ['type' => 'range', 'label' => 'Logo 高度', 'min' => 24, 'max' => 80, 'step' => 2, 'unit' => 'px', 'default' => 40],
                    'announcement_enabled' => ['type' => 'checkbox', 'label' => '显示顶部公告栏', 'default' => true],
                    'announcement_text' => ['type' => 'text', 'label' => '公告内容', 'max' => 120, 'default' => 'Free worldwide shipping on orders over $99'],
                    'announcement_link' => ['type' => 'url', 'label' => '公告链接（可选）'],
                ],
            ],
            'product' => [
                'label' => '商品页',
                'fields' => [
                    'perks' => ['type' => 'textarea', 'label' => '购买保障（每行一条）', 'max' => 400, 'default' => "Free worldwide shipping over \$99\n30-day easy returns\nGift-ready packaging"],
                ],
            ],
            'footer' => [
                'label' => '页脚',
                'fields' => [
                    'tagline' => ['type' => 'text', 'label' => '页脚标语', 'max' => 120, 'default' => ''],
                    'show_powered_by' => ['type' => 'checkbox', 'label' => '显示 “Powered by Lumée”', 'default' => true],
                ],
            ],
        ],

        'sections' => [
            'hero' => [
                'label' => '首屏大图',
                'max_per_page' => 3,
                'fields' => [
                    'image' => ['type' => 'image', 'label' => '背景图片（不上传则使用品牌渐变）'],
                    'eyebrow' => ['type' => 'text', 'label' => '小标题', 'max' => 60, 'default' => 'New Collection'],
                    'heading' => ['type' => 'text', 'label' => '主标题（留空显示店铺名）', 'max' => 100, 'default' => ''],
                    'text' => ['type' => 'textarea', 'label' => '描述', 'max' => 300, 'default' => 'Delicate pieces crafted to catch the light — designed to be worn every day and treasured for a lifetime.'],
                    'button_label' => ['type' => 'text', 'label' => '按钮文字', 'max' => 40, 'default' => 'Shop Now'],
                    'button_link' => ['type' => 'url', 'label' => '按钮链接', 'default' => '#shop'],
                    'height' => ['type' => 'select', 'label' => '高度', 'options' => ['small' => '小', 'medium' => '中', 'large' => '大'], 'default' => 'medium'],
                    'overlay' => ['type' => 'range', 'label' => '图片遮罩（让文字更清晰）', 'min' => 0, 'max' => 70, 'step' => 5, 'unit' => '%', 'default' => 35],
                ],
            ],
            'featured_products' => [
                'label' => '精选商品',
                'fields' => [
                    'eyebrow' => ['type' => 'text', 'label' => '小标题', 'max' => 60, 'default' => 'Featured'],
                    'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'Best Sellers'],
                    'collection' => ['type' => 'collection', 'label' => '商品来源（不选则显示最新商品）'],
                    'limit' =>['type' => 'range', 'label' => '显示数量', 'min' => 2, 'max' => 16, 'step' => 1, 'default' => 8],
                    'columns' => ['type' => 'select', 'label' => '每行几个（电脑端）', 'options' => ['3' => '3 个', '4' => '4 个'], 'default' => '4'],
                ],
            ],
            'collection_list' => [
                'label' => '分类展示',
                'fields' => [
                    'eyebrow' => ['type' => 'text', 'label' => '小标题', 'max' => 60, 'default' => 'Shop by'],
                    'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'Collections'],
                    'collection_1' => ['type' => 'collection', 'label' => '分类 1（都不选则自动显示前 4 个分类）'],
                    'collection_2' => ['type' => 'collection', 'label' => '分类 2'],
                    'collection_3' => ['type' => 'collection', 'label' => '分类 3'],
                    'collection_4' => ['type' => 'collection', 'label' => '分类 4'],
                ],
            ],
            'image_text' => [
                'label' => '图文介绍',
                'fields' => [
                    'image' => ['type' => 'image', 'label' => '图片'],
                    'image_position' => ['type' => 'select', 'label' => '图片位置', 'options' => ['left' => '左侧', 'right' => '右侧'], 'default' => 'left'],
                    'eyebrow' => ['type' => 'text', 'label' => '小标题', 'max' => 60, 'default' => 'Our Story'],
                    'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'Made to be treasured'],
                    'text' => ['type' => 'textarea', 'label' => '正文', 'max' => 1200, 'default' => 'Every piece is designed in-house and finished by hand with ethically sourced gemstones.'],
                    'button_label' => ['type' => 'text', 'label' => '按钮文字（可选）', 'max' => 40, 'default' => ''],
                    'button_link' => ['type' => 'url', 'label' => '按钮链接'],
                ],
            ],
            'rich_text' => [
                'label' => '文字段落',
                'fields' => [
                    'eyebrow' => ['type' => 'text', 'label' => '小标题', 'max' => 60, 'default' => ''],
                    'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'Talk about your brand'],
                    'text' => ['type' => 'textarea', 'label' => '正文', 'max' => 2000, 'default' => 'Share the story behind your jewelry, your materials and what makes each piece special.'],
                    'align' => ['type' => 'select', 'label' => '对齐', 'options' => ['center' => '居中', 'left' => '左对齐'], 'default' => 'center'],
                ],
            ],
            'newsletter' => [
                'label' => '邮件订阅',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'Join our newsletter'],
                    'text' => ['type' => 'textarea', 'label' => '说明', 'max' => 300, 'default' => 'Be the first to hear about new arrivals and members-only offers.'],
                    'button_label' => ['type' => 'text', 'label' => '按钮文字', 'max' => 40, 'default' => 'Subscribe'],
                ],
            ],
            'features' => [
                'label' => '服务亮点（三栏）',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => '标题（可选）', 'max' => 100, 'default' => ''],
                    'item1_title' => ['type' => 'text', 'label' => '第 1 栏标题', 'max' => 60, 'default' => 'Free Shipping'],
                    'item1_text' => ['type' => 'text', 'label' => '第 1 栏说明', 'max' => 140, 'default' => 'On all orders over $99, worldwide.'],
                    'item2_title' => ['type' => 'text', 'label' => '第 2 栏标题', 'max' => 60, 'default' => '30-Day Returns'],
                    'item2_text' => ['type' => 'text', 'label' => '第 2 栏说明', 'max' => 140, 'default' => 'Not in love? Send it back, no questions asked.'],
                    'item3_title' => ['type' => 'text', 'label' => '第 3 栏标题', 'max' => 60, 'default' => 'Gift Ready'],
                    'item3_text' => ['type' => 'text', 'label' => '第 3 栏说明', 'max' => 140, 'default' => 'Every piece arrives in our signature box.'],
                ],
            ],
            'faq' => [
                'label' => '常见问题（折叠）',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'Frequently asked questions'],
                    'q1' => ['type' => 'text', 'label' => '问题 1', 'max' => 160, 'default' => 'How long does shipping take?'],
                    'a1' => ['type' => 'textarea', 'label' => '回答 1', 'max' => 800, 'default' => 'Orders ship within 1–2 business days and usually arrive in 5–10 days.'],
                    'q2' => ['type' => 'text', 'label' => '问题 2', 'max' => 160, 'default' => 'Can I return my order?'],
                    'a2' => ['type' => 'textarea', 'label' => '回答 2', 'max' => 800, 'default' => 'Yes — you have 30 days to return unworn items for a full refund.'],
                    'q3' => ['type' => 'text', 'label' => '问题 3', 'max' => 160, 'default' => ''],
                    'a3' => ['type' => 'textarea', 'label' => '回答 3', 'max' => 800, 'default' => ''],
                    'q4' => ['type' => 'text', 'label' => '问题 4', 'max' => 160, 'default' => ''],
                    'a4' => ['type' => 'textarea', 'label' => '回答 4', 'max' => 800, 'default' => ''],
                    'q5' => ['type' => 'text', 'label' => '问题 5', 'max' => 160, 'default' => ''],
                    'a5' => ['type' => 'textarea', 'label' => '回答 5', 'max' => 800, 'default' => ''],
                ],
            ],
            'video' => [
                'label' => '视频',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => '标题（可选）', 'max' => 100, 'default' => ''],
                    'video_url' => ['type' => 'url', 'label' => 'YouTube 或 Vimeo 视频链接', 'default' => ''],
                    'text' => ['type' => 'textarea', 'label' => '说明（可选）', 'max' => 400, 'default' => ''],
                    'width' => ['type' => 'select', 'label' => '宽度', 'options' => ['narrow' => '居中', 'full' => '通栏'], 'default' => 'narrow'],
                ],
            ],
            'testimonials' => [
                'label' => '顾客评价',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => '标题', 'max' => 100, 'default' => 'What our customers say'],
                    'quote1' => ['type' => 'textarea', 'label' => '评价 1', 'max' => 400, 'default' => 'Beautiful quality and it arrived so quickly. Already ordered another one!'],
                    'author1' => ['type' => 'text', 'label' => '评价人 1', 'max' => 60, 'default' => 'Emma, London'],
                    'quote2' => ['type' => 'textarea', 'label' => '评价 2', 'max' => 400, 'default' => 'Exactly as pictured. The packaging made it the perfect gift.'],
                    'author2' => ['type' => 'text', 'label' => '评价人 2', 'max' => 60, 'default' => 'Sofia, Madrid'],
                    'quote3' => ['type' => 'textarea', 'label' => '评价 3', 'max' => 400, 'default' => 'Great customer service and easy returns. Highly recommend.'],
                    'author3' => ['type' => 'text', 'label' => '评价人 3', 'max' => 60, 'default' => 'Liam, New York'],
                ],
            ],
        ],

        'max_sections' => 20,

        'default_sections' => [
            ['type' => 'hero'],
            ['type' => 'featured_products'],
            ['type' => 'features'],
        ],
];

/*
| Fresh — clean green & white, suits any kind of store.
| "extends" => 'aurora': any template Fresh doesn't provide falls back to Aurora's
| (ThemeContext::view). Fresh only overrides the layouts' styling.
*/
$fresh = array_replace_recursive($aurora, [
    'name' => 'Fresh',
    'description' => '清爽绿白风格：白色为主、绿色点缀，适合各类商品',
    'extends' => 'aurora',
    'placeholder' => '/brand/placeholder.svg',
    'settings' => [
        'colors' => ['fields' => [
            'primary' => ['default' => '#0E8A5F'],
            'background' => ['default' => '#FFFFFF'],
            'text' => ['default' => '#1C2621'],
            'accent' => ['label' => '点缀色（促销标签背景）', 'default' => '#E3F5EC'],
        ]],
        'typography' => ['fields' => [
            'heading_font' => ['default' => 'Plus Jakarta Sans'],
            'body_font' => ['default' => 'Inter'],
        ]],
        'product' => ['fields' => [
            'perks' => ['default' => implode("\n", ['Free shipping on orders over $99', '30-day easy returns', 'Secure checkout'])],
        ]],
    ],
    'sections' => [
        'hero' => ['fields' => [
            'image' => ['label' => '背景图片（不上传则使用浅绿背景）'],
            'eyebrow' => ['default' => 'New Arrivals'],
            'text' => ['default' => 'Quality everyday essentials, thoughtfully made and shipped worldwide.'],
            'overlay' => ['default' => 30],
        ]],
        'image_text' => ['fields' => [
            'heading' => ['default' => 'Made with care'],
            'text' => ['default' => 'Tell customers what makes your products special — materials, craftsmanship, or the story behind your brand.'],
        ]],
        'rich_text' => ['fields' => [
            'text' => ['default' => 'Share the story behind your store and what makes your products special.'],
        ]],
        'features' => ['fields' => [
            'item3_title' => ['default' => 'Secure Checkout'],
            'item3_text' => ['default' => 'Pay safely with all major cards.'],
        ]],
    ],
]);
// Sans-serif font choices (replace, don't merge, Aurora's serif list)
$fresh['settings']['typography']['fields']['heading_font']['options'] = [
    'Plus Jakarta Sans' => 'Plus Jakarta Sans（现代友好）',
    'Poppins' => 'Poppins（圆润几何）',
    'DM Sans' => 'DM Sans（简洁利落）',
    'Noto Sans SC' => '思源黑体',
];

return [
    'aurora' => $aurora,
    'fresh' => $fresh,
];
