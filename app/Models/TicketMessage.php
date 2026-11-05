<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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

    /**
     * Corpo pronto para exibir. Mensagens do editor (HTML) passam pelo sanitizador
     * do Filament; as de canais em texto puro (WhatsApp, e-mail, API) são escapadas
     * preservando quebras de linha. @menções viram destaque nos dois casos.
     */
    public function bodyHtml(): HtmlString
    {
        $body = (string) $this->body;

        $html = $body !== strip_tags($body)
            ? Str::sanitizeHtml($body)
            : nl2br(e($body), false);

        // Só fora de tags (o lookahead impede casar dentro de atributos como alt="@x")
        $html = preg_replace(
            '/(?<![\w.@\/])@([A-Za-z0-9][A-Za-z0-9._-]{1,63})(?![^<]*>)/u',
            '<span class="hd-mention">@$1</span>',
            $html
        );

        return new HtmlString($html);
    }
}
