<?php

namespace App\Services;

use App\Events\OperatorEvent;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OutboundMessageService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Chat $chat, User $operator, string $body): array
    {
        return DB::transaction(function () use ($chat, $operator, $body) {
            $locked = Chat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'closed') {
                return ['ok' => false, 'code' => 'CHAT_CLOSED', 'status' => 409, 'message' => 'Cannot send to closed chat'];
            }
            if (! $locked->isAssignedTo($operator) && ! $operator->isAdmin()) {
                return ['ok' => false, 'code' => 'CHAT_NOT_OWNED', 'status' => 403, 'message' => 'Only chat owner or admin can send messages'];
            }

            $message = Message::create([
                'chat_id' => $locked->id,
                'operator_id' => $operator->id,
                'direction' => 'outbound',
                'type' => 'text',
                'body' => $body,
                'metadata' => ['plain_text' => true],
            ]);
            $delivery = $message->deliveries()->create([
                'channel_id' => $locked->channel_id,
                'status' => 'pending',
                'attempt_count' => 0,
            ]);
            $outbox = $message->outboxMessages()->create([
                'aggregate_type' => 'message',
                'aggregate_id' => $message->id,
                'event_type' => 'outbound.message.created',
                'payload' => ['message_id' => $message->id, 'delivery_id' => $delivery->id],
                'status' => 'pending',
                'available_at' => now(),
            ]);
            $locked->forceFill([
                'last_message_at' => now(),
                'assignment_last_activity_at' => $locked->isAssignedTo($operator) ? now() : $locked->assignment_last_activity_at,
            ])->save();
            $this->audit->log('message.outbound_created', $operator, 'message', $message->id, ['chat_id' => $locked->id, 'delivery_id' => $delivery->id]);
            event(new OperatorEvent('message.created', ['chat_id' => $locked->id, 'message_id' => $message->id, 'direction' => 'outbound', 'delivery_id' => $delivery->id]));

            return ['ok' => true, 'message' => $message->fresh(['deliveries', 'reads.user']), 'delivery' => $delivery->fresh(), 'outbox' => $outbox];
        });
    }
}
