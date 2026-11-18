<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Security\ActivityLog;
use App\Security\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Two-step sign-in for staff accounts (authenticator app, TOTP). Turning it on takes two steps:
 * get a secret (QR code) → confirm with a code from the app, which also hands out 8 one-time recovery codes.
 */
class TwoFactorController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json([
            'enabled' => $u->two_factor_confirmed_at !== null,
            'confirmed_at' => $u->two_factor_confirmed_at,
            'recovery_codes_left' => $u->two_factor_confirmed_at ? count($u->two_factor_recovery_codes ?? []) : 0,
        ]);
    }

    /** Step 1: a new secret (not active until confirmed). */
    public function start(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $u = $request->user();
        $this->checkPassword($u, $request->input('password'));
        abort_if($u->two_factor_confirmed_at !== null, 422, __('两步验证已经开启'));

        $secret = Totp::secret();
        $u->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => null])->save();

        return response()->json([
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'uri' => Totp::uri($secret, $u->email, config('app.name', 'Lumée')),
        ]);
    }

    /** Step 2: prove the app is set up. */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:10']]);
        $u = $request->user();
        abort_unless($u->two_factor_secret && ! $u->two_factor_confirmed_at, 422, __('请先生成验证密钥'));
        if (! Totp::verify($u->two_factor_secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('验证码不正确，请检查手机时间是否准确')]);
        }
        $codes = $this->newCodes();
        $u->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();
        ActivityLog::record('security.2fa_on');

        return response()->json(['enabled' => true, 'recovery_codes' => $codes]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $u = $request->user();
        $this->checkPassword($u, $request->input('password'));
        abort_unless($u->two_factor_confirmed_at, 422);
        $codes = $this->newCodes();
        $u->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();
        ActivityLog::record('security.2fa_codes');

        return response()->json(['recovery_codes' => $codes]);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $u = $request->user();
        $this->checkPassword($u, $request->input('password'));
        $u->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        ActivityLog::record('security.2fa_off');

        return response()->json(['enabled' => false]);
    }

    private function checkPassword($user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => __('密码不正确')]);
        }
    }

    /** 8 codes like "k7m2-9qxp". */
    private function newCodes(): array
    {
        return array_map(fn () => Str::lower(Str::random(4)).'-'.Str::lower(Str::random(4)), range(1, 8));
    }
}
