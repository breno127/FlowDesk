<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TicketMessage extends Model
{
    protected $fillable = [
        'ticket_id', 'author_type', 'author_id', 'visibility', 'direction', 'channel', 'body', 'external_id',
        'delivery_status', 'delivered_at', 'read_at', 'delivery_error', 'meta',
    ];

    protected $casts = [
        'visibility' => MessageVisibility::class,
        'direction' => MessageDirection::class,
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'meta' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_message_mentions')
            ->withPivot('notified_at')
            ->withTimestamps();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function isInternal(): bool
    {
        return $this->visibility === MessageVisibility::Internal;
    }

    public function authorName(): string
    {
        return $this->author?->name ?? 'Sistema';
    }
}
