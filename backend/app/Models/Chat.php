<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    use HasFactory;

    protected $fillable = ['channel_id', 'external_user_id', 'status', 'assigned_operator_id', 'assigned_at', 'assigned_by_user_id', 'assignment_last_activity_at', 'last_message_at', 'last_inbound_message_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'assignment_last_activity_at' => 'datetime', 'last_message_at' => 'datetime', 'last_inbound_message_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function externalUser(): BelongsTo
    {
        return $this->belongsTo(ExternalUser::class);
    }

    public function assignedOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_operator_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isAssignedTo(User $user): bool
    {
        return (int) $this->assigned_operator_id === (int) $user->id;
    }

    public function assignmentState(): string
    {
        return $this->status === 'open' && $this->assigned_operator_id === null ? 'unassigned' : $this->status;
    }
}
