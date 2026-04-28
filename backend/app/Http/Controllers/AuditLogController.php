<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type' => ['sometimes', 'string'],
            'target_type' => ['sometimes', 'string'],
            'target_id' => ['sometimes', 'integer'],
            'actor_user_id' => ['sometimes', 'integer'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = AuditLog::query()->with('actor')->latest('id');
        foreach (['event_type', 'target_type', 'target_id', 'actor_user_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['cursor'])) {
            $query->where('id', '<', $data['cursor']);
        }
        $limit = (int) ($data['limit'] ?? 50);
        $logs = $query->limit($limit + 1)->get();
        $visible = $logs->take($limit);

        return response()->json(['data' => $visible->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'actor_user' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name, 'email' => $log->actor->email] : null,
            'event_type' => $log->event_type,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'metadata' => $log->metadata ?: (object) [],
            'ip_address' => $log->ip_address,
            'request_id' => $log->request_id,
            'created_at' => $log->created_at?->toISOString(),
        ])->values(), 'next_cursor' => $logs->count() > $limit ? $visible->last()->id : null]);
    }
}
