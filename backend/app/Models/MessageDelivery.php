<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageDelivery extends Model
{
    use HasFactory;

    protected $fillable = ['message_id', 'channel_id', 'status', 'attempt_count', 'next_attempt_at', 'provider_message_id', 'provider_error_code', 'provider_error_message'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
