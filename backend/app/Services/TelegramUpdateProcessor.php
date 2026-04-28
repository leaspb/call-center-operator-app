<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Chat;
use App\Models\ExternalUser;
use App\Models\ProcessedProviderUpdate;
use App\Support\OperatorEventRecipients;
use App\Support\OperatorNotifier;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class TelegramUpdateProcessor
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OperatorNotifier $notifier,
    ) {}

    public function process(array $payload): array
    {
        $updateId = (string) Arr::get($payload, 'update_id');
        if ($updateId === '') {
            return ['ok' => false, 'code' => 'INVALID_TELEGRAM_UPDATE', 'status' => 422, 'message' => 'Telegram update_id is required'];
        }

        $messagePayload = Arr::get($payload, 'message') ?: Arr::get($payload, 'edited_message');
        if (! is_array($messagePayload)) {
            return ['ok' => true, 'duplicate' => false, 'ignored' => true];
        }

        return DB::transaction(function () use ($payload, $updateId, $messagePayload) {
            $channel = $this->telegramChannel();
            $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $alreadyProcessed = ProcessedProviderUpdate::query()
                ->where('channel_id', $channel->id)
                ->where('provider', 'telegram')
                ->where('provider_update_id', $updateId)
                ->exists();

            if ($alreadyProcessed) {
                return ['ok' => true, 'duplicate' => true];
            }

            $processedAt = now();
            $inserted = ProcessedProviderUpdate::query()->insertOrIgnore([
                'channel_id' => $channel->id,
                'provider' => 'telegram',
                'provider_update_id' => $updateId,
                'raw_payload_hash' => $hash,
                'processed_at' => $processedAt,
                'created_at' => $processedAt,
                'updated_at' => $processedAt,
            ]);

            if ($inserted === 0) {
                return ['ok' => true, 'duplicate' => true];
            }

            $from = Arr::get($messagePayload, 'from', []);
            $chatPayload = Arr::get($messagePayload, 'chat', []);
            $externalId = (string) (Arr::get($from, 'id') ?: Arr::get($chatPayload, 'id'));
            if ($externalId === '') {
                return ['ok' => false, 'code' => 'INVALID_TELEGRAM_SENDER', 'status' => 422, 'message' => 'Telegram sender id is required'];
            }

            $firstName = Arr::get($from, 'first_name');
            $lastName = Arr::get($from, 'last_name');
            $username = Arr::get($from, 'username');
            $displayName = trim(implode(' ', array_filter([$firstName, $lastName]))) ?: ($username ?: $externalId);

            $externalUser = ExternalUser::updateOrCreate(
                ['channel_id' => $channel->id, 'external_id' => $externalId],
                ['username' => $username, 'first_name' => $firstName, 'last_name' => $lastName, 'display_name' => $displayName, 'metadata' => $from]
            );

            $chat = Chat::query()
                ->where('channel_id', $channel->id)
                ->where('external_user_id', $externalUser->id)
                ->whereIn('status', ['open', 'assigned', 'closed'])
                ->lockForUpdate()
                ->first();

            if (! $chat) {
                $chat = Chat::create(['channel_id' => $channel->id, 'external_user_id' => $externalUser->id, 'status' => 'open']);
                $this->audit->log('chat.created', null, 'chat', $chat->id, ['provider' => 'telegram']);
                $this->notifier->notify('chat.created', ['chat_id' => $chat->id, 'channel' => 'telegram'], OperatorEventRecipients::all());
            }

            if ($chat->status === 'closed') {
                $chat->forceFill([
                    'status' => 'open',
                    'assigned_operator_id' => null,
                    'assigned_by_user_id' => null,
                    'assigned_at' => null,
                    'assignment_last_activity_at' => null,
                ])->save();
                $this->audit->log('chat.reopened', null, 'chat', $chat->id, ['provider' => 'telegram']);
                $this->notifier->notify('chat.reopened', ['chat_id' => $chat->id, 'channel' => 'telegram'], OperatorEventRecipients::all());
            }

            $text = Arr::get($messagePayload, 'text');
            $type = is_string($text) ? 'text' : 'unsupported_message';
            $body = is_string($text) ? $text : null;
            $message = $chat->messages()->create([
                'direction' => 'inbound',
                'type' => $type,
                'body' => $body,
                'metadata' => $messagePayload,
                'external_message_id' => (string) Arr::get($messagePayload, 'message_id'),
            ]);

            $chat->forceFill([
                'last_message_at' => now(),
                'last_inbound_message_at' => now(),
            ])->save();
            $this->audit->log('message.inbound_created', null, 'message', $message->id, ['chat_id' => $chat->id, 'provider' => 'telegram']);
            $messageEvent = ['chat_id' => $chat->id, 'message_id' => $message->id, 'direction' => 'inbound'];
            $recipients = $chat->assigned_operator_id !== null
                ? OperatorEventRecipients::forUsers([$chat->assigned_operator_id])
                : OperatorEventRecipients::all();
            $this->notifier->notify('message.created', $messageEvent, $recipients);

            return ['ok' => true, 'duplicate' => false, 'chat' => $chat->fresh(['channel', 'externalUser', 'assignedOperator']), 'message' => $message->fresh(['deliveries', 'reads.user'])];
        });
    }

    private function telegramChannel(): Channel
    {
        $now = now();
        Channel::query()->insertOrIgnore([
            'code' => 'telegram',
            'name' => 'Telegram',
            'is_active' => true,
            'config' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Channel::query()->where('code', 'telegram')->firstOrFail();
    }
}
