<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    use HasFactory;

    protected $fillable = ['aggregate_type', 'aggregate_id', 'event_type', 'payload', 'status', 'available_at', 'attempts', 'locked_by', 'locked_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime', 'locked_at' => 'datetime'];
    }
}
