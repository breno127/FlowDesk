<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSequence extends Model
{
    protected $fillable = ['scope', 'last_value'];

    protected $casts = ['last_value' => 'integer'];
}
