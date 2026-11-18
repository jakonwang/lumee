<?php

namespace App\Security;

use App\Models\User;
use App\Tenancy\CurrentStore;
use Illuminate\Support\Facades\DB;

/**
 * Staff activity log: who changed what in the admin. Written by the LogAdminActivity middleware
 * for every successful change (and exports), and explicitly for sign-ins and security changes.
 */
class ActivityLog
{
    /** Readable label per action key (translated when shown). Unknown keys fall back to a generic label. */
    public const LABELS = [
        'auth.login' => '登录后台',
        'security.2fa_on' => '开启两步验证',
        'security.2fa_off' => '关闭两步验证',
        'security.2fa_codes' => '重新生成恢复码',
        'products.store' => '创建商品', 'products.update' => '编辑商品', 'products.destroy' => '删除商品',
        'products.import' => '导入商品', 'products.export' => '导出商品', 'products.inventory' => '调整库存', 'products.copy' => '复制商品到其他店铺',
        'collections.store' => '创建分类', 'collections.update' => '编辑分类', 'collections.destroy' => '删除分类',
        'orders.export' => '导出订单', 'orders.manual' => '手动创建订单', 'orders.mark-paid' => '标记订单已付款',
        'orders.fulfill' => '订单发货', 'orders.tracking' => '修改物流信息', 'orders.refunds' => '订单退款', 'orders.cancel' => '取消订单',
        'orders.comments' => '添加订单备注', 'orders.notifications' => '重发订单邮件',
        'customers.update' => '编辑客户', 'customers.export' => '导出客户', 'customers.bulk-tags' => '批量修改客户标签',
        'gift-cards.store' => '创建礼品卡', 'gift-cards.update' => '修改礼品卡', 'gift-cards.resend' => '重发礼品卡邮件',
        'discounts.store' => '创建折扣码', 'discounts.update' => '编辑折扣码', 'discounts.destroy' => '删除折扣码',
        'promotions.store' => '创建自动折扣', 'promotions.update' => '编辑自动折扣', 'promotions.destroy' => '删除自动折扣',
        'returns.update' => '处理退货申请',
        'theme.update' => '保存店铺装修草稿', 'theme.publish' => '发布主题', 'theme.discard' => '放弃装修修改',
        'themes.store' => '添加主题', 'themes.import' => '导入主题', 'themes.update' => '重命名主题', 'themes.destroy' => '删除主题', 'themes.export' => '导出主题',
        'pages.store' => '创建页面', 'pages.update' => '编辑页面', 'pages.destroy' => '删除页面',
        'blog-posts.store' => '发布博客文章', 'blog-posts.update' => '编辑博客文章', 'blog-posts.destroy' => '删除博客文章',
        'menus.update' => '修改导航菜单', 'redirects.store' => '添加网址重定向', 'redirects.update' => '修改网址重定向',
        'redirects.delete' => '删除网址重定向', 'redirects.import' => '导入网址重定向',
        'settings.update' => '修改店铺设置', 'staff.store' => '邀请员工', 'staff.update' => '修改员工权限', 'staff.destroy' => '移除员工',
        'domains.store' => '添加域名', 'domains.destroy' => '删除域名', 'developers.store' => '创建 API 令牌', 'developers.destroy' => '撤销 API 令牌',
        'campaigns.store' => '创建营销邮件', 'campaigns.update' => '编辑营销邮件', 'campaigns.send' => '发送营销邮件',
        'apps.install' => '安装应用', 'apps.uninstall' => '卸载应用',
    ];

    public static function record(string $action, ?string $subject = null, ?User $user = null, ?int $storeId = null): void
    {
        $request = request();
        $user ??= $request?->user() instanceof User ? $request->user() : null;
        $current = app(CurrentStore::class);
        $storeId ??= $current->check() ? $current->id() : null;
        // Account-level events (sign-in, two-step settings) belong to every store the person works in
        $storeIds = $storeId !== null ? [$storeId] : ($user ? $user->stores()->pluck('stores.id')->all() : [null]);

        $row = [
            'user_id' => $user?->id,
            'action' => mb_substr($action, 0, 60),
            'subject' => $subject !== null ? mb_substr($subject, 0, 120) : null,
            'method' => $request?->method(),
            'path' => $request ? mb_substr('/'.$request->path(), 0, 255) : null,
            'ip' => $request?->ip(),
            'created_at' => now(),
        ];
        DB::table('activity_logs')->insert(array_map(fn ($id) => ['store_id' => $id, ...$row], $storeIds ?: [null]));
    }

    public static function label(string $action): string
    {
        return __(self::LABELS[$action] ?? '修改设置');
    }
}
