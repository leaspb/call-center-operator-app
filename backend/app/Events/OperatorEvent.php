<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OperatorEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private readonly string $name,
        public readonly array $payload,
        private readonly array $targetUserIds,
    ) {}

    public function broadcastOn(): array
    {
        return collect($this->targetUserIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->map(fn (int $id) => new PrivateChannel("operator.{$id}"))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
