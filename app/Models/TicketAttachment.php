<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TicketAttachment extends Model
{
    protected $fillable = ['ticket_id', 'ticket_message_id', 'disk', 'path', 'original_name', 'mime_type', 'size'];

    protected $casts = ['size' => 'integer'];

    protected static function booted(): void
    {
        static::deleted(fn (self $attachment) => Storage::disk($attachment->disk)->delete($attachment->path));
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }
}
