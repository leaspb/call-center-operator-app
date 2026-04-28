<?php

namespace App\Providers;

use App\Services\Telegram\FakeTelegramAdapter;
use App\Services\Telegram\HttpTelegramAdapter;
use App\Services\Telegram\TelegramAdapter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TelegramAdapter::class, function () {
            return config('services.telegram.fake')
                ? new FakeTelegramAdapter
                : new HttpTelegramAdapter((string) config('services.telegram.bot_token'));
        });
    }

    public function boot(): void
    {
        RateLimiter::for('telegram-webhook', fn ($request) => Limit::perMinute(120)->by($request->ip() ?: 'telegram-webhook'));
    }
}
