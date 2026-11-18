<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoreInvitation;
use App\Notifications\StaffInviteMail;
use App\Tenancy\CurrentStore;
use App\Tenancy\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Owner-only (route middleware perm:owner): members, invitations, permissions. */
class StaffController extends Controller
{
    public const MAX_STAFF = 20;

    public function index(CurrentStore $current): JsonResponse
    {
        return response()->json($this->payload($current));
    }

    public function invite(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $this->validated($request, withEmail: true);
        $store = $current->getOrFail();
        $email = Str::lower($data['email']);

        if ($store->users()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('这个邮箱已经是店铺成员')]);
        }
        // Plan limit counts everyone in the store (owner included) plus pending invitations
        $limit = min(self::MAX_STAFF, app(\App\Billing\Billing::class)->limits($store)['staff']);
        if ($store->users()->count() + StoreInvitation::where('accepted_at', null)->where('expires_at', '>', now())->count() >= $limit) {
            throw ValidationException::withMessages(['email' => $limit <= 1
                ? __('当前套餐不支持添加员工，请升级到基础版或以上')
                : __('当前套餐最多 :p0 位成员（含所有者），请升级套餐', ['p0' => $limit])]);
        }

        // Re-inviting the same email replaces the old pending invitation
        StoreInvitation::where('email', $email)->whereNull('accepted_at')->delete();
        $invitation = new StoreInvitation(['email' => $email, 'permissions' => $data['permissions'], 'invited_by' => $request->user()->id]);
        $token = $invitation->issueToken();
        $invitation->save();

        $this->send($invitation, $token, $request->user()->name);

        return response()->json($this->payload($current), 201);
    }

    public function resend(Request $request, int $id, CurrentStore $current): JsonResponse
    {
        $invitation = StoreInvitation::whereNull('accepted_at')->findOrFail($id);   // store-scoped
        $token = $invitation->issueToken();   // old link stops working
        $invitation->save();
        $this->send($invitation, $token, $request->user()->name);

        return response()->json($this->payload($current));
    }

    public function cancel(int $id, CurrentStore $current): JsonResponse
    {
        StoreInvitation::whereNull('accepted_at')->findOrFail($id)->delete();

        return response()->json($this->payload($current));
    }

    public function update(Request $request, int $userId, CurrentStore $current): JsonResponse
    {
        $data = $this->validated($request, withEmail: false);
        $member = $this->member($userId, $current);

        $current->getOrFail()->users()->updateExistingPivot($member->id, ['permissions' => json_encode($data['permissions'])]);

        return response()->json($this->payload($current));
    }

    public function remove(int $userId, CurrentStore $current): JsonResponse
    {
        $member = $this->member($userId, $current);
        $current->getOrFail()->users()->detach($member->id);
        // Their API tokens keep working for OTHER stores they belong to; access to this one is gone
        // immediately because membership is checked on every request (ResolveAdminStore).

        return response()->json($this->payload($current));
    }

    /** A non-owner member of the current store. */
    private function member(int $userId, CurrentStore $current)
    {
        $member = $current->getOrFail()->users()->whereKey($userId)->first();
        abort_unless($member, 404);
        if ($member->pivot->role === 'owner') {
            throw ValidationException::withMessages(['member' => __('不能修改或移除店铺所有者')]);
        }

        return $member;
    }

    private function validated(Request $request, bool $withEmail): array
    {
        $data = $request->validate([
            ...($withEmail ? ['email' => ['required', 'email', 'max:255']] : []),
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::in(array_keys(Permissions::ALL))],
        ], [
            'email.required' => __('请填写邮箱'),
            'email.email' => __('邮箱格式不正确'),
        ]);
        $data['permissions'] = Permissions::clean($data['permissions']);
        if (! $data['permissions']) {
            throw ValidationException::withMessages(['permissions' => __('请至少勾选一项权限')]);
        }

        return $data;
    }

    private function send(StoreInvitation $invitation, string $token, string $inviterName): void
    {
        $url = rtrim((string) config('lumee.admin_url'), '/').'/invite/'.$token;
        // In the inviter's admin language (we don't know the invitee's yet)
        Mail::to($invitation->email)->locale(app()->getLocale())->queue(new StaffInviteMail($invitation->store()->first(), $inviterName, $url, $invitation->permissions));
    }

    private function payload(CurrentStore $current): array
    {
        $store = $current->getOrFail();

        return [
            'members' => $store->users()->orderByRaw("CASE WHEN store_user.role = 'owner' THEN 0 ELSE 1 END")->orderBy('store_user.created_at')->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->pivot->role,
                    'permissions' => $u->pivot->role === 'owner'
                        ? array_keys(Permissions::ALL)
                        : Permissions::clean(json_decode((string) $u->pivot->permissions, true) ?: []),
                    'joined_at' => $u->pivot->created_at,
                ]),
            'invitations' => StoreInvitation::whereNull('accepted_at')->latest('id')->get()->map(fn ($i) => [
                'id' => $i->id,
                'email' => $i->email,
                'permissions' => $i->permissions,
                'expires_at' => $i->expires_at,
                'expired' => $i->expires_at->isPast(),
            ]),
            'permissions' => collect(Permissions::ALL)->map(fn ($v, $k) => ['key' => $k, 'label' => __($v[0]), 'description' => __($v[1])])->values(),
        ];
    }
}
