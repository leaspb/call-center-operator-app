<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('operator.{userId}', fn (User $user, int $userId): bool => $user->is_active && (int) $user->id === $userId && in_array($user->role, ['admin', 'operator'], true));
