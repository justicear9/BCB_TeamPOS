<?php

namespace Modules\Cashier\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Passport issues year-long tokens by default. A lost phone should stop
 * working within a month, so cashier routes refuse older tokens.
 */
class FreshCashierToken
{
    public const MAX_AGE_DAYS = 30;

    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->token();
        if ($token && $token->created_at && $token->created_at->lt(now()->subDays(self::MAX_AGE_DAYS))) {
            $token->revoke();
            abort(401, 'Your sign-in has expired. Sign in again.');
        }

        return $next($request);
    }
}
