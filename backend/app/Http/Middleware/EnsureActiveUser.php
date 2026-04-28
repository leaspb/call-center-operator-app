<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            return ApiError::response('User account is disabled', 'USER_DISABLED', 403);
        }

        return $next($request);
    }
}
