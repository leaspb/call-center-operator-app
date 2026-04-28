<?php

namespace App\Providers;

use App\Services\Telegram\HttpTelegramAdapter;
use App\Services\Telegram\TelegramAdapter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            TelegramAdapter::class,
            fn () => new HttpTelegramAdapter((string) config('services.telegram.bot_token'))
        );
    }

    public function boot(): void
    {
        RateLimiter::for('auth', fn ($request) => Limit::perMinute(5)->by($request->ip() ?: 'auth'));
        RateLimiter::for('admin-password-reset', fn ($request) => Limit::perMinute(10)->by(optional($request->user())->id ?: $request->ip() ?: 'admin-password-reset'));
        RateLimiter::for('telegram-webhook', fn ($request) => Limit::perMinute(120)->by($request->ip() ?: 'telegram-webhook'));
    }
}
