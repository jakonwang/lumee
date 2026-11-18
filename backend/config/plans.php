<?php

/*
| Platform plans. Prices are USD cents. The transaction fee is charged on each paid order's
| net amount (after refunds), in basis points (100 = 1%). Orders paid with the dev "test"
| gateway are never charged. "staff" = how many people can be in the store, owner included.
*/
return [
    'trial' => [
        'name' => '免费试用',
        'marketing_emails' => 500,
        'staff' => 4,
        'custom_domains' => true,
        'fee_bp' => 0,
    ],

    'plans' => [
        'starter' => [
            'name' => '入门版',
            'tagline' => '刚开始卖货',
            'month' => 900,
            'year' => 9000,
            'fee_bp' => 200,
            'staff' => 1,
            'custom_domains' => false,
            'marketing_emails' => 2000,
            'features' => ['店铺前台与全部主题', '无限商品', '交易佣金 2%', '使用 lumee 二级域名', '每月 2,000 封营销邮件'],
        ],
        'basic' => [
            'name' => '基础版',
            'tagline' => '成长中的品牌',
            'month' => 2900,
            'year' => 29000,
            'fee_bp' => 100,
            'staff' => 4,
            'custom_domains' => true,
            'marketing_emails' => 10000,
            'features' => ['入门版全部功能', '绑定自有域名', '最多 4 位成员', '交易佣金 1%', '每月 10,000 封营销邮件'],
        ],
        'pro' => [
            'name' => '专业版',
            'tagline' => '规模化运营',
            'month' => 7900,
            'year' => 79000,
            'fee_bp' => 50,
            'staff' => 15,
            'custom_domains' => true,
            'marketing_emails' => 50000,
            'features' => ['基础版全部功能', '最多 15 位成员', '交易佣金 0.5%', '每月 50,000 封营销邮件', '优先客服支持'],
        ],
    ],

    // After a failed renewal payment, the store keeps working this many days before it is paused
    'grace_days' => 7,

    // Dev only: subscribing activates instantly without Stripe (like the test payment gateway)
    'test_mode' => env('LUMEE_TEST_BILLING', false),
];
