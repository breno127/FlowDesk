<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessHourInterval extends Model
{
    protected $fillable = ['business_hour_schedule_id', 'weekday', 'starts_at', 'ends_at'];

    protected $casts = ['weekday' => 'integer'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BusinessHourSchedule::class, 'business_hour_schedule_id');
    }
}
