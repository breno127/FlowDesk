<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketPriority extends Model
{
    protected $fillable = ['name', 'slug', 'level', 'weight', 'color', 'is_default'];

    protected $casts = ['level' => 'integer', 'weight' => 'integer', 'is_default' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(function (self $priority) {
            if ($priority->is_default) {
                static::query()->whereKeyNot($priority->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->orderBy('level')->first();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
