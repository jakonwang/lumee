<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** api.scope:read_products — the token must have the scope (write_* implies read_*). */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $request->attributes->get('api_token');
        $ok = $token && ($token->can($scope) || (str_starts_with($scope, 'read_') && $token->can('write_'.substr($scope, 5))));
        if (! $ok) {
            return response()->json(['error' => 'forbidden', 'message' => "This token is missing the \"{$scope}\" scope."], 403);
        }

        return $next($request);
    }
}
