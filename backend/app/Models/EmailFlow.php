<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailFlow extends Model
{
    use BelongsToStore;

    /** type => [label, description] */
    public const TYPES = [
        'welcome' => ['欢迎邮件', '有人订阅邮件后，自动发送欢迎和品牌介绍'],
        'post_purchase' => ['购后关怀', '顾客第一次下单付款后，发送感谢和搭配推荐'],
        'win_back' => ['老客召回', '老顾客很久没有下单时，发一封带优惠的召回邮件'],
        'birthday' => ['生日祝福', '顾客生日当天，发一封带专属优惠的祝福邮件（每年一次）'],
        'browse_abandon' => ['浏览未购买提醒', '已登录的顾客看了商品却没有下单，几小时后提醒他回来看看'],
    ];

    public const DEFAULT_SETTINGS = ['stop_on_order' => true, 'win_back_days' => 90, 'browse_hours' => 4];

    protected $fillable = ['type', 'enabled', 'settings'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'settings' => 'array'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(EmailCampaign::class)->orderBy('flow_position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(EmailFlowEnrollment::class);
    }

    public function setting(string $key): mixed
    {
        return ($this->settings ?? [])[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }
}
