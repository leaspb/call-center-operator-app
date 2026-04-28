<?php

namespace App\Http\Controllers;

use App\Services\ChatPresenter;
use App\Services\TelegramUpdateProcessor;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(private readonly TelegramUpdateProcessor $processor, private readonly ChatPresenter $presenter) {}

    public function store(Request $request): JsonResponse
    {
        $expected = (string) config('services.telegram.webhook_secret');
        if ($expected === '') {
            return ApiError::response('Telegram webhook secret is not configured', 'MISCONFIGURATION', 500);
        }
        if (! hash_equals($expected, (string) $request->headers->get('X-Telegram-Bot-Api-Secret-Token'))) {
            return ApiError::response('Invalid Telegram webhook secret', 'INVALID_TELEGRAM_WEBHOOK_SECRET', 403);
        }

        $result = $this->processor->process($request->all());
        if (! ($result['ok'] ?? false)) {
            return ApiError::response($result['message'], $result['code'], $result['status']);
        }

        Log::info('telegram.webhook.processed', ['duplicate' => $result['duplicate'] ?? false, 'ignored' => $result['ignored'] ?? false]);

        return response()->json([
            'ok' => true,
            'duplicate' => $result['duplicate'] ?? false,
            'ignored' => $result['ignored'] ?? false,
            'chat' => isset($result['chat']) ? $this->presenter->chat($result['chat']) : null,
            'message' => isset($result['message']) ? $this->presenter->message($result['message']) : null,
        ]);
    }
}
