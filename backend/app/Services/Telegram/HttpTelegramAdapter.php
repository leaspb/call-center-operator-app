<?php

namespace App\Services\Telegram;

use App\Models\Chat;
use App\Models\Message;
use Illuminate\Support\Facades\Http;

class HttpTelegramAdapter implements TelegramAdapter
{
    public function __construct(private readonly string $botToken) {}

    public function sendText(Chat $chat, Message $message): TelegramSendResult
    {
        if ($this->botToken === '') {
            return new TelegramSendResult(false, null, 'TELEGRAM_TOKEN_MISSING', 'Telegram bot token is not configured', false);
        }

        $chat->loadMissing('externalUser');
        $response = Http::asJson()->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
            'chat_id' => $chat->externalUser->external_id,
            'text' => $message->body,
            'disable_web_page_preview' => true,
        ]);

        if ($response->successful() && $response->json('ok') === true) {
            return new TelegramSendResult(true, (string) $response->json('result.message_id'));
        }

        $status = $response->status();
        $retryable = $status === 429 || $status >= 500;

        return new TelegramSendResult(
            false,
            null,
            (string) ($response->json('error_code') ?: $status),
            (string) ($response->json('description') ?: $response->body()),
            $retryable,
        );
    }
}
