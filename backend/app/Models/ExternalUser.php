<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalUser extends Model
{
    use HasFactory;

    protected $fillable = ['channel_id', 'external_id', 'username', 'first_name', 'last_name', 'display_name', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }
}
