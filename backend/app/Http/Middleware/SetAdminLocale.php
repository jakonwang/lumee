<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin / ops API: messages in the language the admin app is shown in (X-Admin-Locale: zh_CN | en).
 * The signed-in user's choice is remembered: merchant emails (new orders, billing reminders,
 * invitations they send) go out in it.
 */
class SetAdminLocale
{
    public const LOCALES = ['zh_CN', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-Admin-Locale');
        $locale = in_array($header, self::LOCALES, true) ? $header : 'zh_CN';
        App::setLocale($locale);

        $response = $next($request);

        $user = $request->user();
        if ($header && $user instanceof User && $user->locale !== $locale) {
            $user->forceFill(['locale' => $locale])->saveQuietly();
        }

        return $response;
    }
}
