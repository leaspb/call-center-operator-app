<?php

namespace App\Http\Controllers;

use App\Models\MessageDelivery;
use App\Services\AuditLogger;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryRetryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Request $request, MessageDelivery $delivery): JsonResponse
    {
        $delivery->loadMissing('message.chat');
        $chat = $delivery->message->chat;
        if (! $request->user()->isAdmin() && ! $chat->isAssignedTo($request->user())) {
            return ApiError::response('Only chat owner or admin can retry delivery', 'CHAT_NOT_OWNED', 403);
        }

        $result = DB::transaction(function () use ($delivery, $request) {
            $lockedDelivery = MessageDelivery::query()
                ->with('message')
                ->whereKey($delivery->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedDelivery->status, ['failed', 'retrying'], true)) {
                return ['ok' => false, 'delivery' => $lockedDelivery];
            }

            $lockedDelivery->forceFill(['status' => 'pending', 'next_attempt_at' => null])->save();
            $lockedDelivery->message->outboxMessages()->updateOrCreate(
                ['event_type' => 'outbound.message.created'],
                [
                    'aggregate_type' => 'message',
                    'aggregate_id' => $lockedDelivery->message_id,
                    'payload' => ['message_id' => $lockedDelivery->message_id, 'delivery_id' => $lockedDelivery->id],
                    'status' => 'pending',
                    'available_at' => now(),
                    'locked_by' => null,
                    'locked_at' => null,
                ]
            );
            $this->audit->log('delivery.manual_retry', $request->user(), 'message_delivery', $lockedDelivery->id, ['message_id' => $lockedDelivery->message_id], $request);

            return ['ok' => true, 'delivery' => $lockedDelivery->fresh()];
        });

        if (! $result['ok']) {
            return ApiError::response('Only failed or retrying deliveries can be manually retried', 'DELIVERY_NOT_RETRYABLE', 409, [
                'status' => $result['delivery']->status,
            ]);
        }

        $delivery = $result['delivery'];

        return response()->json(['delivery' => [
            'id' => $delivery->id,
            'status' => $delivery->status,
            'attempt_count' => $delivery->attempt_count,
            'next_attempt_at' => null,
        ]]);
    }
}
