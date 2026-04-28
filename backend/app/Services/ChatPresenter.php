<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;

class ChatPresenter
{
    public function chat(Chat $chat, ?User $viewer = null, bool $details = false): array
    {
        $chat->loadMissing(['channel', 'externalUser', 'assignedOperator']);
        $lastMessage = $chat->relationLoaded('messages') ? $chat->messages->first() : $chat->messages()->latest('id')->first();

        return [
            'id' => $chat->id,
            'status' => $chat->status,
            'assignment_state' => $chat->assignmentState(),
            'assigned_at' => $chat->assigned_at?->toISOString(),
            'assignment_last_activity_at' => $chat->assignment_last_activity_at?->toISOString(),
            'last_message_at' => $chat->last_message_at?->toISOString(),
            'last_inbound_message_at' => $chat->last_inbound_message_at?->toISOString(),
            'unread_count' => $viewer ? $this->unreadCount($chat, $viewer) : null,
            'last_message_preview' => $lastMessage?->body,
            'assigned_operator' => $chat->assignedOperator ? [
                'id' => $chat->assignedOperator->id,
                'name' => $chat->assignedOperator->name,
                'email' => $chat->assignedOperator->email,
            ] : null,
            'external_user' => [
                'id' => $chat->externalUser->id,
                'external_id' => $chat->externalUser->external_id,
                'display_name' => $chat->externalUser->display_name,
                'username' => $chat->externalUser->username,
                'first_name' => $chat->externalUser->first_name,
                'last_name' => $chat->externalUser->last_name,
            ],
            'channel' => [
                'id' => $chat->channel->id,
                'code' => $chat->channel->code,
                'name' => $chat->channel->name,
            ],
            'read_only' => $viewer ? ($chat->assigned_operator_id !== null && ! $chat->isAssignedTo($viewer) && ! $viewer->isAdmin()) : null,
            'created_at' => $chat->created_at?->toISOString(),
            'updated_at' => $chat->updated_at?->toISOString(),
        ];
    }

    public function message(Message $message): array
    {
        $message->loadMissing(['deliveries', 'reads.user']);
        $delivery = $message->deliveries->first();

        return [
            'id' => $message->id,
            'chat_id' => $message->chat_id,
            'operator_id' => $message->operator_id,
            'direction' => $message->direction,
            'type' => $message->type,
            'body' => $message->body,
            'delivery_status' => $delivery?->status,
            'delivery' => $delivery ? [
                'id' => $delivery->id,
                'status' => $delivery->status,
                'attempt_count' => $delivery->attempt_count,
                'next_attempt_at' => $delivery->next_attempt_at?->toISOString(),
                'provider_message_id' => $delivery->provider_message_id,
                'provider_error_code' => $delivery->provider_error_code,
                'provider_error_message' => $delivery->provider_error_message,
            ] : null,
            'read_by' => $message->reads->map(fn ($read) => [
                'user_id' => $read->user_id,
                'name' => $read->user?->name,
                'read_at' => $read->read_at?->toISOString(),
            ])->values(),
            'metadata' => $message->metadata ?: (object) [],
            'created_at' => $message->created_at?->toISOString(),
        ];
    }

    private function unreadCount(Chat $chat, User $viewer): int
    {
        return (int) $chat->messages()
            ->where('direction', 'inbound')
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $viewer->id))
            ->count();
    }
}
