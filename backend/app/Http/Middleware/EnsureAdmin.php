<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->role !== 'admin' || ! $user->is_active) {
            return ApiError::response('Admin role required', 'FORBIDDEN', 403);
        }

        return $next($request);
    }
}
