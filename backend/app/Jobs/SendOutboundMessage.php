<?php

namespace App\Jobs;

use App\Models\MessageDelivery;
use App\Models\OutboxMessage;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramAdapter;
use App\Services\Telegram\TelegramSendResult;
use App\Support\OperatorEventRecipients;
use App\Support\OperatorNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendOutboundMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const RETRY_BACKOFF_MINUTES = [1, 2, 5, 10, 30];

    public int $uniqueFor = 120;

    public function __construct(
        public int $outboxId,
        public ?string $claimToken = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->outboxId;
    }

    public function handle(TelegramAdapter $telegram, AuditLogger $audit, ?OperatorNotifier $notifier = null): void
    {
        $notifier ??= app(OperatorNotifier::class);
        $claim = DB::transaction(function () {
            $outbox = OutboxMessage::query()->whereKey($this->outboxId)->lockForUpdate()->firstOrFail();
            if ($outbox->status !== 'enqueued') {
                return null;
            }
            if (! $this->matchesClaimToken($outbox)) {
                return null;
            }

            $deliveryId = data_get($outbox->payload, 'delivery_id');
            $delivery = MessageDelivery::query()->with('message.chat.externalUser')->whereKey($deliveryId)->lockForUpdate()->first();
            if (! $delivery) {
                $outbox->forceFill(['status' => 'failed'])->save();

                return null;
            }
            if ($delivery->status === 'sent') {
                $outbox->forceFill(['status' => 'processed', 'locked_at' => null, 'locked_by' => null])->save();

                return null;
            }
            if (! in_array($delivery->status, ['queued', 'retrying', 'pending'], true)) {
                return null;
            }

            $outbox->forceFill(['status' => 'processing', 'locked_at' => now()])->save();
            $delivery->forceFill(['status' => 'sending'])->save();

            return [$outbox->id, $delivery->id];
        });

        if ($claim === null) {
            return;
        }

        [$outboxId, $deliveryId] = $claim;
        $delivery = MessageDelivery::query()->with('message.chat.externalUser', 'message.operator')->findOrFail($deliveryId);
        $message = $delivery->message;
        try {
            $result = $telegram->sendText($message->chat, $message);
        } catch (Throwable $exception) {
            Log::warning('Telegram outbound transport failed', [
                'delivery_id' => $delivery->id,
                'message_id' => $message->id,
                'exception' => $this->redactSecrets($exception->getMessage()),
            ]);
            $result = new TelegramSendResult(false, null, 'TRANSPORT_EXCEPTION', 'Telegram transport failed; see server logs', true);
        }

        DB::transaction(function () use ($outboxId, $delivery, $message, $result, $audit, $notifier) {
            $outbox = OutboxMessage::query()->whereKey($outboxId)->lockForUpdate()->firstOrFail();
            if ($outbox->status !== 'processing' || ! $this->matchesClaimToken($outbox)) {
                return;
            }
            $freshDelivery = MessageDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($result->ok) {
                $freshDelivery->forceFill([
                    'status' => 'sent',
                    'provider_message_id' => $result->providerMessageId,
                    'provider_error_code' => null,
                    'provider_error_message' => null,
                    'next_attempt_at' => null,
                ])->save();
                $outbox->forceFill([
                    'status' => 'processed',
                    'locked_by' => null,
                    'locked_at' => null,
                ])->save();
                $audit->log('delivery.sent', $message->operator, 'message_delivery', $freshDelivery->id, ['message_id' => $message->id]);
                $notifier->notify('message.delivery_status_changed', [
                    'message_id' => $message->id,
                    'delivery_id' => $freshDelivery->id,
                    'status' => 'sent',
                ], OperatorEventRecipients::forUsers([$message->operator_id]));

                return;
            }

            $attempts = $freshDelivery->attempt_count + 1;
            $hasRetry = $result->retryable && $attempts <= count(self::RETRY_BACKOFF_MINUTES);
            $nextAttemptAt = $hasRetry ? now()->addMinutes(self::RETRY_BACKOFF_MINUTES[$attempts - 1]) : null;
            $freshDelivery->forceFill([
                'status' => $hasRetry ? 'retrying' : 'failed',
                'attempt_count' => $attempts,
                'next_attempt_at' => $nextAttemptAt,
                'provider_error_code' => $result->errorCode,
                'provider_error_message' => $result->errorMessage,
            ])->save();
            $outbox->forceFill([
                'status' => $hasRetry ? 'pending' : 'failed',
                'available_at' => $nextAttemptAt,
                'locked_by' => null,
                'locked_at' => null,
            ])->save();
            $notifier->notify('message.delivery_status_changed', [
                'message_id' => $message->id,
                'delivery_id' => $freshDelivery->id,
                'status' => $freshDelivery->status,
                'next_attempt_at' => $freshDelivery->next_attempt_at?->toISOString(),
            ], OperatorEventRecipients::forUsers([$message->operator_id]));
            $audit->log('delivery.'.($hasRetry ? 'retrying' : 'failed'), $message->operator, 'message_delivery', $freshDelivery->id, [
                'message_id' => $message->id,
                'attempt_count' => $attempts,
                'next_attempt_at' => $freshDelivery->next_attempt_at?->toISOString(),
                'provider_error_code' => $result->errorCode,
            ]);
        });
    }

    private function matchesClaimToken(OutboxMessage $outbox): bool
    {
        if ($this->claimToken === null) {
            return true;
        }

        return is_string($outbox->locked_by) && hash_equals($outbox->locked_by, $this->claimToken);
    }

    private function redactSecrets(string $value): string
    {
        return preg_replace('/bot[0-9]+:[A-Za-z0-9_-]+/', 'bot[redacted]', $value) ?? 'redacted';
    }
}
