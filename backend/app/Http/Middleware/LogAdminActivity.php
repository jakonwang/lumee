<?php

namespace App\Http\Middleware;

use App\Security\ActivityLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs successful admin changes (POST/PUT/PATCH/DELETE) and exports, as "{area}.{action}",
 * e.g. PUT products/{product} → products.update, POST orders/{order}/refunds → orders.refunds.
 * Autosave of the theme draft is skipped (it fires on every keystroke).
 */
class LogAdminActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($response->getStatusCode() < 400 && $request->user() && $this->worthLogging($request)) {
                [$action, $subject] = $this->describe($request);
                ActivityLog::record($action, $subject);
            }
        } catch (\Throwable $e) {
            report($e);   // never break the request because of the log
        }

        return $response;
    }

    private function worthLogging(Request $request): bool
    {
        $uri = (string) $request->route()?->uri();
        if ($request->isMethod('GET')) {
            return str_ends_with($uri, '/export');
        }
        if ($request->isMethod('PUT') && str_ends_with($uri, 'api/admin/theme')) {
            return false;   // draft autosave
        }

        return ! str_contains($uri, 'uploads/') && ! str_ends_with($uri, '/contact');
    }

    /** @return array{string, ?string} */
    private function describe(Request $request): array
    {
        $uri = preg_replace('~^api/admin/~', '', (string) $request->route()->uri());
        $parts = array_values(array_filter(explode('/', $uri), fn ($p) => ! str_starts_with($p, '{')));
        $area = $parts[0] ?? 'admin';
        $sub = $parts[1] ?? null;
        $verb = match ($request->method()) {
            'POST' => 'store', 'PUT', 'PATCH' => 'update', 'DELETE' => 'destroy', default => 'view',
        };
        // Sub-actions: orders/{order}/refunds → orders.refunds; settings/payments/... → settings.update
        $action = $area === 'settings' ? 'settings.update' : $area.'.'.($sub ?? $verb);
        if ($area === 'theme' && ! $sub) {
            $action = 'theme.update';
        }
        if ($area === 'products' && $sub === 'inventory') {
            $action = 'products.inventory';
        }

        return [$action, $this->subject($request)];
    }

    /** "#1004", a product title, a customer email… taken from the route's bound model, if any. */
    private function subject(Request $request): ?string
    {
        foreach ($request->route()->parameters() as $p) {
            if ($p instanceof Model) {
                return match (true) {
                    method_exists($p, 'name') && $p instanceof \App\Models\Order => $p->name(),
                    isset($p->title) => (string) $p->title,
                    isset($p->email) => (string) $p->email,
                    isset($p->name) => (string) $p->name,
                    default => class_basename($p).' '.$p->getKey(),
                };
            }
            if (is_numeric($p)) {
                return '#'.$p;
            }
        }

        return null;
    }
}
