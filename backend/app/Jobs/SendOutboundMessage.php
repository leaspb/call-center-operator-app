<?php

namespace App\Jobs;

use App\Events\OperatorEvent;
use App\Models\MessageDelivery;
use App\Models\OutboxMessage;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SendOutboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $outboxId) {}

    public function handle(TelegramAdapter $telegram, AuditLogger $audit): void
    {
        $claim = DB::transaction(function () {
            $outbox = OutboxMessage::query()->whereKey($this->outboxId)->lockForUpdate()->firstOrFail();
            if ($outbox->status !== 'enqueued') {
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
        $result = $telegram->sendText($message->chat, $message);

        DB::transaction(function () use ($outboxId, $delivery, $message, $result, $audit) {
            $outbox = OutboxMessage::query()->whereKey($outboxId)->lockForUpdate()->firstOrFail();
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
                event(new OperatorEvent('message.delivery_status_changed', ['message_id' => $message->id, 'delivery_id' => $freshDelivery->id, 'status' => 'sent']));

                return;
            }

            $attempts = $freshDelivery->attempt_count + 1;
            $backoff = [1, 2, 5, 10, 30];
            $hasRetry = $result->retryable && $attempts <= count($backoff);
            $nextAttemptAt = $hasRetry ? now()->addMinutes($backoff[$attempts - 1]) : null;
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
            event(new OperatorEvent('message.delivery_status_changed', ['message_id' => $message->id, 'delivery_id' => $freshDelivery->id, 'status' => $freshDelivery->status, 'next_attempt_at' => $freshDelivery->next_attempt_at?->toISOString()]));
            $audit->log('delivery.'.($hasRetry ? 'retrying' : 'failed'), $message->operator, 'message_delivery', $freshDelivery->id, [
                'message_id' => $message->id,
                'attempt_count' => $attempts,
                'next_attempt_at' => $freshDelivery->next_attempt_at?->toISOString(),
                'provider_error_code' => $result->errorCode,
            ]);
        });
    }
}
