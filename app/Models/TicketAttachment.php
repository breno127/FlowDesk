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

    /** Tipos exibidos inline com segurança (SVG/HTML ficam de fora: risco de XSS). */
    private const INLINE_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function isPreviewableImage(): bool
    {
        return in_array($this->mime_type, self::INLINE_IMAGE_TYPES, true);
    }

    public function url(bool $download = false): string
    {
        return route('filament.admin.tickets.attachments.download', array_filter([
            'ticket' => $this->ticket_id,
            'attachment' => $this->id,
            'download' => $download ? 1 : null,
        ]));
    }

    public function humanSize(): string
    {
        $size = (int) $this->size;

        return match (true) {
            $size >= 1048576 => number_format($size / 1048576, 1, ',', '').' MB',
            $size >= 1024 => number_format($size / 1024, 0, ',', '').' KB',
            default => $size.' B',
        };
    }
}
