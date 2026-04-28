<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TelegramPollingService
{
    public const CACHE_KEY = 'telegram.polling.next_offset';

    public function __construct(private readonly TelegramUpdateProcessor $processor) {}

    public function poll(?int $limit = null, ?int $timeout = null): array
    {
        $botToken = (string) config('services.telegram.bot_token');
        if ($botToken === '') {
            return [
                'ok' => false,
                'code' => 'TELEGRAM_TOKEN_MISSING',
                'message' => 'TELEGRAM_BOT_TOKEN is required for Telegram polling',
            ];
        }

        $payload = [
            'limit' => $this->clamp($limit ?? (int) config('services.telegram.polling_limit'), 1, 100),
            'timeout' => $this->clamp($timeout ?? 0, 0, 50),
            'allowed_updates' => ['message', 'edited_message'],
        ];

        $offset = Cache::get(self::CACHE_KEY);
        if (is_numeric($offset)) {
            $payload['offset'] = (int) $offset;
        }

        $response = Http::asJson()->post("https://api.telegram.org/bot{$botToken}/getUpdates", $payload);
        if (! $response->successful() || $response->json('ok') !== true) {
            return [
                'ok' => false,
                'code' => (string) ($response->json('error_code') ?: $response->status()),
                'message' => 'Telegram getUpdates request failed',
                'status' => $response->status(),
            ];
        }

        $updates = $response->json('result', []);
        if (! is_array($updates)) {
            return [
                'ok' => false,
                'code' => 'INVALID_TELEGRAM_RESPONSE',
                'message' => 'Telegram getUpdates result must be an array',
            ];
        }

        $summary = [
            'ok' => true,
            'received' => count($updates),
            'processed' => 0,
            'duplicates' => 0,
            'ignored' => 0,
            'failed' => 0,
            'next_offset' => $offset === null ? null : (int) $offset,
        ];
        $maxUpdateId = null;

        foreach ($updates as $update) {
            if (! is_array($update)) {
                $summary['failed']++;

                continue;
            }

            $updateId = data_get($update, 'update_id');
            if (is_numeric($updateId)) {
                $maxUpdateId = max($maxUpdateId ?? (int) $updateId, (int) $updateId);
            }

            $result = $this->processor->process($update);
            if (($result['ok'] ?? false) !== true) {
                $summary['failed']++;
            } elseif (($result['duplicate'] ?? false) === true) {
                $summary['duplicates']++;
            } elseif (($result['ignored'] ?? false) === true) {
                $summary['ignored']++;
            } else {
                $summary['processed']++;
            }
        }

        if ($maxUpdateId !== null) {
            $summary['next_offset'] = $maxUpdateId + 1;
            Cache::forever(self::CACHE_KEY, $summary['next_offset']);
        }

        return $summary;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
