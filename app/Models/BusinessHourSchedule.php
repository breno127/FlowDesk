<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessHourSchedule extends Model
{
    protected $fillable = ['name', 'timezone', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    protected static function booted(): void
    {
        // Garante um único calendário padrão
        static::saved(function (self $schedule) {
            if ($schedule->is_default) {
                static::query()->whereKeyNot($schedule->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public function intervals(): HasMany
    {
        return $this->hasMany(BusinessHourInterval::class)->orderBy('weekday')->orderBy('starts_at');
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class)->orderBy('date');
    }
}
