<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketColumnTransition extends Model
{
    public $timestamps = false;

    protected $fillable = ['ticket_id', 'board_column_id', 'from_column_id', 'user_id', 'entered_at', 'left_at', 'duration_seconds'];

    protected $casts = [
        'entered_at' => 'datetime',
        'left_at' => 'datetime',
        'duration_seconds' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'board_column_id');
    }

    public function fromColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'from_column_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
