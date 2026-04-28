<?php

namespace App\Services;

use App\Jobs\SendOutboundMessage;
use App\Models\MessageDelivery;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutboxPoller
{
    public function enqueue(int $limit = 50): int
    {
        $workerId = (string) Str::uuid();
        $staleBefore = now()->subMinutes(2);
        $count = 0;
        $outboxes = OutboxMessage::query()
            ->where(function ($query) use ($staleBefore) {
                $query->where(function ($pending) {
                    $pending->where('status', 'pending')
                        ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()));
                })->orWhere(function ($stale) use ($staleBefore) {
                    $stale->where('status', 'enqueued')
                        ->where('locked_at', '<=', $staleBefore);
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($outboxes as $outbox) {
            DB::transaction(function () use ($outbox, $workerId, $staleBefore, &$count) {
                $locked = OutboxMessage::query()->whereKey($outbox->id)->lockForUpdate()->first();
                if (! $locked || $locked->status === 'processed' || $locked->status === 'failed') {
                    return;
                }
                if ($locked->status === 'pending' && $locked->available_at !== null && $locked->available_at->gt(now())) {
                    return;
                }
                if ($locked->status === 'enqueued' && ($locked->locked_at === null || $locked->locked_at->gt($staleBefore))) {
                    return;
                }

                $deliveryId = data_get($locked->payload, 'delivery_id');
                if (! $deliveryId) {
                    $locked->forceFill(['status' => 'failed', 'attempts' => $locked->attempts + 1])->save();

                    return;
                }

                MessageDelivery::query()
                    ->whereKey($deliveryId)
                    ->whereIn('status', ['pending', 'queued', 'retrying'])
                    ->update(['status' => 'queued']);

                $locked->forceFill([
                    'status' => 'enqueued',
                    'attempts' => $locked->attempts + 1,
                    'locked_by' => $workerId,
                    'locked_at' => now(),
                ])->save();
                SendOutboundMessage::dispatch($locked->id)->onQueue('outbound');
                $count++;
            });
        }

        return $count;
    }

    public function enqueueDueRetries(int $limit = 50): int
    {
        return $this->enqueue($limit);
    }
}
