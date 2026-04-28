<?php

namespace App\Services;

use App\Events\OperatorEvent;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChatAssignmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function assignToSelf(Chat $chat, User $user): array
    {
        return DB::transaction(function () use ($chat, $user) {
            $locked = Chat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'closed') {
                return ['ok' => false, 'code' => 'CHAT_CLOSED', 'status' => 409, 'message' => 'Closed chat cannot be assigned'];
            }

            if ($locked->assigned_operator_id !== null && (int) $locked->assigned_operator_id !== (int) $user->id) {
                event(new OperatorEvent('chat.assignment_conflict', ['chat_id' => $locked->id, 'requested_by_user_id' => $user->id, 'assigned_operator_id' => $locked->assigned_operator_id]));

                return ['ok' => false, 'code' => 'CHAT_ALREADY_ASSIGNED', 'status' => 409, 'message' => 'Chat is already assigned', 'chat' => $locked->fresh(['assignedOperator'])];
            }

            $now = now();
            $locked->forceFill([
                'status' => 'assigned',
                'assigned_operator_id' => $user->id,
                'assigned_by_user_id' => $user->id,
                'assigned_at' => $locked->assigned_at ?: $now,
                'assignment_last_activity_at' => $now,
            ])->save();

            $this->audit->log('chat.assigned', $user, 'chat', $locked->id, ['operator_id' => $user->id]);
            event(new OperatorEvent('chat.assigned', ['chat_id' => $locked->id, 'operator_id' => $user->id]));

            return ['ok' => true, 'chat' => $locked->fresh(['assignedOperator', 'channel', 'externalUser'])];
        });
    }

    public function adminAssign(Chat $chat, User $admin, User $operator): Chat
    {
        return DB::transaction(function () use ($chat, $admin, $operator) {
            $locked = Chat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();
            $now = now();
            $locked->forceFill([
                'status' => 'assigned',
                'assigned_operator_id' => $operator->id,
                'assigned_by_user_id' => $admin->id,
                'assigned_at' => $now,
                'assignment_last_activity_at' => $now,
            ])->save();
            $this->audit->log('chat.admin_assigned', $admin, 'chat', $locked->id, ['operator_id' => $operator->id]);
            event(new OperatorEvent('chat.assigned', ['chat_id' => $locked->id, 'operator_id' => $operator->id, 'admin_id' => $admin->id]));

            return $locked->fresh(['assignedOperator', 'channel', 'externalUser']);
        });
    }

    public function release(Chat $chat, User $actor, string $event = 'chat.released'): Chat|array
    {
        return DB::transaction(function () use ($chat, $actor, $event) {
            $locked = Chat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();
            if ($locked->assigned_operator_id !== null && ! $actor->isAdmin() && (int) $locked->assigned_operator_id !== (int) $actor->id) {
                return ['ok' => false, 'code' => 'CHAT_NOT_OWNED', 'status' => 403, 'message' => 'Only owner or admin can release chat'];
            }
            $previous = $locked->assigned_operator_id;
            $locked->forceFill([
                'status' => 'open',
                'assigned_operator_id' => null,
                'assigned_by_user_id' => null,
                'assigned_at' => null,
                'assignment_last_activity_at' => null,
            ])->save();
            $this->audit->log($event, $actor, 'chat', $locked->id, ['previous_operator_id' => $previous]);
            event(new OperatorEvent('chat.released', ['chat_id' => $locked->id, 'previous_operator_id' => $previous]));

            return $locked->fresh(['assignedOperator', 'channel', 'externalUser']);
        });
    }

    public function heartbeat(Chat $chat, User $user): Chat|array
    {
        return DB::transaction(function () use ($chat, $user) {
            $locked = Chat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isAssignedTo($user)) {
                return ['ok' => false, 'code' => 'CHAT_NOT_OWNED', 'status' => 403, 'message' => 'Only owner can send heartbeat'];
            }
            $locked->forceFill(['assignment_last_activity_at' => now()])->save();

            return $locked->fresh(['assignedOperator', 'channel', 'externalUser']);
        });
    }

    public function autoReleaseInactive(int $timeoutMinutes = 10): int
    {
        $cutoff = now()->subMinutes($timeoutMinutes);
        $released = 0;
        Chat::query()
            ->where('status', 'assigned')
            ->whereNotNull('assigned_operator_id')
            ->where('assignment_last_activity_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($chats) use (&$released, $timeoutMinutes) {
                foreach ($chats as $chat) {
                    DB::transaction(function () use ($chat, &$released, $timeoutMinutes) {
                        $locked = Chat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();
                        if ($locked->status !== 'assigned' || $locked->assignment_last_activity_at === null || $locked->assignment_last_activity_at->gt(now()->subMinutes($timeoutMinutes))) {
                            return;
                        }
                        $previous = $locked->assigned_operator_id;
                        $locked->forceFill([
                            'status' => 'open',
                            'assigned_operator_id' => null,
                            'assigned_by_user_id' => null,
                            'assigned_at' => null,
                            'assignment_last_activity_at' => null,
                        ])->save();
                        $this->audit->log('chat.auto_released', null, 'chat', $locked->id, ['previous_operator_id' => $previous]);
                        event(new OperatorEvent('chat.released', ['chat_id' => $locked->id, 'previous_operator_id' => $previous, 'reason' => 'auto_release']));
                        $released++;
                    });
                }
            });

        return $released;
    }
}
