<?php

namespace App\Tenancy;

/** Areas of the admin a staff member can be given access to. Owners implicitly have all + "staff". */
final class Permissions
{
    public const ALL = [
        'orders' => ['订单', '查看订单、标记付款、发货、重发邮件'],
        'products' => ['商品与分类', '添加和编辑商品、图片、规格、分类'],
        'customers' => ['客户', '查看客户名单和消费记录'],
        'marketing' => ['营销', '创建和管理折扣码'],
        'analytics' => ['数据分析', '查看销售额、报表等经营数据'],
        'online_store' => ['在线商店', '店铺装修、主题和导航菜单'],
        'settings' => ['店铺设置', '店铺信息、域名、邮件通知'],
    ];

    /** Keep only known permission keys, in a stable order. */
    public static function clean(array $perms): array
    {
        return array_values(array_intersect(array_keys(self::ALL), $perms));
    }
}
