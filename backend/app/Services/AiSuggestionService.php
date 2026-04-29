<?php

namespace App\Services;

use App\Models\Chat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiSuggestionService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Ты помощник оператора колл-центра сайта РЖД (rzd.ru). Твоя задача — предложить оператору короткий и точный ответ на вопрос клиента на основе информации с сайта rzd.ru: расписание поездов, покупка и возврат билетов, программа лояльности РЖД Бонус, услуги перевозки и т.д.

Правила:
- Отвечай только на русском языке.
- Ответ должен быть вежливым, профессиональным и лаконичным.
- Если вопрос не связан с тематикой РЖД, напиши: «Уточните, пожалуйста, Ваш вопрос.»
- Возвращай только текст ответа, без пояснений и комментариев.
PROMPT;

    public function suggest(Chat $chat): ?string
    {
        $apiKey = config('ai.key');
        if (empty($apiKey)) {
            return null;
        }

        $messages = $chat->messages()
            ->where('type', 'text')
            ->whereNotNull('body')
            ->orderBy('id', 'asc')
            ->limit(30)
            ->get(['direction', 'body']);

        if ($messages->isEmpty()) {
            return null;
        }

        $conversation = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        foreach ($messages as $msg) {
            $conversation[] = [
                'role' => $msg->direction === 'inbound' ? 'user' : 'assistant',
                'content' => $msg->body,
            ];
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(config('ai.timeout'))
                ->post(rtrim(config('ai.url'), '/') . '/chat/completions', [
                    'model' => config('ai.model'),
                    'messages' => $conversation,
                    'max_tokens' => 500,
                    'temperature' => 0.5,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('AI suggestion connection failed', ['error' => $e->getMessage()]);
            return null;
        }

        if (! $response->successful()) {
            Log::warning('AI suggestion request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'model' => config('ai.model'),
            ]);
            return null;
        }

        return $response->json('choices.0.message.content');
    }
}
