<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\User;
use App\Services\StoreProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, StoreProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'store_name' => ['required', 'string', 'max:100'],
        ], [
            'email.unique' => __('该邮箱已注册'),
            'password.min' => __('密码至少 8 位'),
        ]);

        $user = DB::transaction(function () use ($data, $provisioner) {
            $user = User::create($data);
            $provisioner->create($user, $data['store_name']);

            return $user;
        });

        return $this->session($user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('邮箱或密码错误')]);
        }

        // Two-step sign-in: no token yet, just a short-lived signed challenge for the code step
        if ($user->two_factor_confirmed_at) {
            return response()->json(['two_factor' => true, 'challenge' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
                'id' => $user->id, 'exp' => now()->addMinutes(10)->timestamp,
            ]))]);
        }

        \App\Security\ActivityLog::record('auth.login', null, $user);

        return $this->session($user);
    }

    /** Second step: a code from the authenticator app, or a one-time recovery code. */
    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:10', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);
        try {
            $c = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($data['challenge']), true);
        } catch (\Throwable) {
            $c = null;
        }
        $user = is_array($c) && ($c['exp'] ?? 0) >= time() ? User::find($c['id'] ?? 0) : null;
        if (! $user || ! $user->two_factor_confirmed_at) {
            throw ValidationException::withMessages(['code' => __('登录已过期，请重新输入密码')]);
        }

        $ok = false;
        if (! empty($data['code'])) {
            $ok = \App\Security\Totp::verify($user->two_factor_secret, $data['code']);
        } elseif (! empty($data['recovery_code'])) {
            $codes = $user->two_factor_recovery_codes ?? [];
            foreach ($codes as $i => $hash) {
                if (Hash::check(strtolower(trim($data['recovery_code'])), $hash)) {
                    unset($codes[$i]);   // each recovery code works once
                    $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                    $ok = true;
                    break;
                }
            }
        }
        if (! $ok) {
            throw ValidationException::withMessages(['code' => __('验证码不正确')]);
        }
        \App\Security\ActivityLog::record('auth.login', null, $user);

        return $this->session($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [...$user->only('id', 'name', 'email'), 'is_platform_admin' => (bool) $user->is_platform_admin, 'two_factor' => $user->two_factor_confirmed_at !== null],
            'stores' => StoreResource::collection($user->stores()->with('domains')->get()),
        ]);
    }

    private function session(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken('admin')->plainTextToken,
            'user' => [...$user->only('id', 'name', 'email'), 'is_platform_admin' => (bool) $user->is_platform_admin],
            'stores' => StoreResource::collection($user->stores()->with('domains')->get()),
        ], $status);
    }
}
