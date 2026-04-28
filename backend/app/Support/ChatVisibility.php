<?php

namespace App\Support;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ChatVisibility
{
    public static function canView(Chat $chat, User $user): bool
    {
        return $user->isAdmin()
            || ($chat->status === 'open' && $chat->assigned_operator_id === null)
            || $chat->isAssignedTo($user);
    }

    public static function constrainVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user): void {
            $visible
                ->where(function (Builder $unassigned): void {
                    $unassigned->where('status', 'open')->whereNull('assigned_operator_id');
                })
                ->orWhere('assigned_operator_id', $user->id);
        });
    }
}
