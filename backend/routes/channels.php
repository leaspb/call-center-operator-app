<?php

use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('operator.chats', fn (User $user): bool => in_array($user->role, ['admin', 'operator'], true) && $user->is_active);

Broadcast::channel('chat.{chatId}', function (User $user, int $chatId): bool {
    $chat = Chat::query()->find($chatId);

    return $chat !== null && ($user->isAdmin() || $chat->assigned_operator_id === null || $chat->assigned_operator_id === $user->id);
});
