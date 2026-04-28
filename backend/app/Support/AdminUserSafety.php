<?php

namespace App\Support;

use App\Models\User;

class AdminUserSafety
{
    public static function removingLastActiveAdmin(User $user, array $changes): bool
    {
        $nextRole = $changes['role'] ?? $user->role;
        $nextActive = $changes['is_active'] ?? $user->is_active;

        if ($user->role !== 'admin' || ! $user->is_active) {
            return false;
        }

        if ($nextRole === 'admin' && $nextActive) {
            return false;
        }

        return User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->doesntExist();
    }
}
