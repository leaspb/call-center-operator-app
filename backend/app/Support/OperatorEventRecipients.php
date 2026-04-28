<?php

namespace App\Support;

use App\Models\User;

class OperatorEventRecipients
{
    public static function all(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', ['admin', 'operator'])
            ->pluck('id')
            ->all();
    }

    public static function forUsers(array $userIds, bool $includeAdmins = true): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map(fn ($id) => (int) $id, $userIds)
        )));

        if ($userIds === [] && ! $includeAdmins) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($userIds, $includeAdmins): void {
                if ($userIds !== []) {
                    $query->whereIn('id', $userIds);
                }

                if ($includeAdmins) {
                    if ($userIds === []) {
                        $query->where('role', 'admin');

                        return;
                    }

                    $query->orWhere('role', 'admin');
                }
            })
            ->pluck('id')
            ->all();
    }
}
