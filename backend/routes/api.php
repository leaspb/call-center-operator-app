<?php

use App\Http\Controllers\AdminChatController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DeliveryRetryController;
use App\Http\Controllers\DevTelegramController;
use App\Http\Controllers\MessageReadController;
use App\Http\Controllers\OpenApiController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/telegram/webhook', [TelegramWebhookController::class, 'store'])->middleware('throttle:telegram-webhook');
    Route::get('/openapi.json', OpenApiController::class);

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::post('/dev/telegram/updates/simulate', [DevTelegramController::class, 'store']);

        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::get('/chats', [ChatController::class, 'index']);
        Route::get('/chats/{chat}', [ChatController::class, 'show']);
        Route::get('/chats/{chat}/messages', [ChatController::class, 'messages']);
        Route::post('/chats/{chat}/messages', [ChatController::class, 'storeMessage']);
        Route::post('/chats/{chat}/assign', [ChatController::class, 'assign']);
        Route::post('/chats/{chat}/release', [ChatController::class, 'release']);
        Route::post('/chats/{chat}/close', [ChatController::class, 'close']);
        Route::post('/chats/{chat}/heartbeat', [ChatController::class, 'heartbeat']);
        Route::post('/messages/{message}/read', [MessageReadController::class, 'store']);
        Route::post('/deliveries/{delivery}/retry', DeliveryRetryController::class);

        Route::middleware('admin')->group(function (): void {
            Route::get('/admin/users', [AdminUserController::class, 'index']);
            Route::post('/admin/users', [AdminUserController::class, 'store']);
            Route::patch('/admin/users/{user}/role', [AdminUserController::class, 'updateRole']);
            Route::patch('/admin/users/{user}/status', [AdminUserController::class, 'updateStatus']);
            Route::post('/admin/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->middleware('throttle:admin-password-reset');
            Route::post('/admin/chats/{chat}/assign', [AdminChatController::class, 'assign']);
            Route::post('/admin/chats/{chat}/force-release', [AdminChatController::class, 'forceRelease']);
            Route::get('/audit-log', AuditLogController::class);
        });
    });
});
