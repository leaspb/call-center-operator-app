<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'is_active', 'config'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'config' => 'array'];
    }

    public function externalUsers(): HasMany
    {
        return $this->hasMany(ExternalUser::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }
}
