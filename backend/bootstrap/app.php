<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['api', 'auth:sanctum'], 'prefix' => 'api/v1'],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API is stateless; Sanctum personal access tokens authenticate protected routes.
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['active' => EnsureActiveUser::class, 'admin' => EnsureAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response('Validation failed', 'VALIDATION_FAILED', 422, $e->errors());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response('Unauthenticated', 'UNAUTHENTICATED', 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response($e->getMessage() ?: 'Forbidden', 'FORBIDDEN', 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response('Resource not found', 'NOT_FOUND', 404);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && $e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    409 => 'CONFLICT',
                    429 => 'RATE_LIMITED',
                    default => 'HTTP_ERROR',
                };

                return ApiError::response($e->getMessage() ?: 'HTTP error', $code, $status);
            }
        });
    })->create();
