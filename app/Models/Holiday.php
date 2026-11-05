<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    protected $fillable = ['business_hour_schedule_id', 'name', 'date', 'is_recurring'];

    protected $casts = ['date' => 'date', 'is_recurring' => 'boolean'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BusinessHourSchedule::class, 'business_hour_schedule_id');
    }
}
