<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public function log(string $eventType, ?User $actor = null, ?string $targetType = null, ?int $targetId = null, array $metadata = [], ?Request $request = null): AuditLog
    {
        return AuditLog::create([
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadata ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $request?->headers->get('X-Request-Id'),
            'created_at' => now(),
        ]);
    }
}
