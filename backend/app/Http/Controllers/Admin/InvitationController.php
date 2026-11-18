<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\StoreInvitation;
use App\Models\User;
use App\Tenancy\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Public endpoints for the invite link. The invitee proves who they are by either
 * creating an account for the invited email, or signing in with that email's password.
 */
class InvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = $this->pending($token);

        return response()->json([
            'store_name' => $invitation->store->name,
            'email' => $invitation->email,
            'inviter' => $invitation->inviter?->name,
            'permissions' => array_map(fn ($p) => __(Permissions::ALL[$p][0] ?? $p), $invitation->permissions),
            'has_account' => User::where('email', $invitation->email)->exists(),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->pending($token);
        $user = User::where('email', $invitation->email)->first();
        $data = [];

        if ($user) {
            $request->validate(['password' => ['required', 'string']]);
            if (! Hash::check($request->input('password'), $user->password)) {
                throw ValidationException::withMessages(['password' => __('密码不正确')]);
            }
        } else {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'password' => ['required', 'string', 'min:8'],
            ], ['password.min' => __('密码至少 8 位'), 'name.required' => __('请填写你的名字')]);
        }

        DB::transaction(function () use (&$user, $invitation, $data) {
            // Lock + re-check so a link can't be used twice concurrently
            $fresh = StoreInvitation::withoutGlobalScope('store')->lockForUpdate()->find($invitation->id);
            if (! $fresh || ! $fresh->isPending()) {
                throw ValidationException::withMessages(['token' => __('邀请已失效')]);
            }

            // The plan may have been downgraded since the invitation was sent
            $limit = app(\App\Billing\Billing::class)->limits($invitation->store)['staff'];
            if (! $invitation->store->users()->where('users.email', $invitation->email)->exists() && $invitation->store->users()->count() >= $limit) {
                throw ValidationException::withMessages(['token' => __('店铺成员已满，请联系店铺所有者升级套餐')]);
            }

            $user ??= User::create(['name' => $data['name'], 'email' => $invitation->email, 'password' => $data['password']]);

            if (! $invitation->store->users()->whereKey($user->id)->exists()) {
                $invitation->store->users()->attach($user->id, [
                    'role' => 'staff',
                    'permissions' => json_encode(Permissions::clean($invitation->permissions)),
                ]);
            }
            $fresh->update(['accepted_at' => now()]);
        });

        return response()->json([
            'token' => $user->createToken('admin')->plainTextToken,
            'user' => $user->only('id', 'name', 'email'),
            'stores' => StoreResource::collection($user->stores()->with('domains')->get()),
            'store_id' => $invitation->store_id,
        ]);
    }

    private function pending(string $token): StoreInvitation
    {
        $invitation = StoreInvitation::findByToken($token);
        if (! $invitation || ! $invitation->isPending()) {
            abort(response()->json(['message' => __('邀请链接无效或已过期，请联系店铺所有者重新邀请')], 404));
        }

        return $invitation;
    }
}
