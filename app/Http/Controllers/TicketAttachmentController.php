<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve anexos do disco privado aplicando a mesma regra de visibilidade
 * do ticket — e escondendo anexos de notas internas de quem não as vê.
 */
class TicketAttachmentController extends Controller
{
    public function download(Request $request, Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        abort_unless((int) $attachment->ticket_id === (int) $ticket->id, 404);

        Gate::authorize('view', $ticket);

        if ($attachment->message?->isInternal()) {
            Gate::authorize('viewInternalNotes', $ticket);
        }

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        $headers = ['X-Content-Type-Options' => 'nosniff'];

        // Inline só para imagens raster; qualquer outro tipo é forçado a download
        if (! $request->boolean('download') && $attachment->isPreviewableImage()) {
            return $disk->response($attachment->path, $attachment->original_name, $headers + [
                'Content-Type' => $attachment->mime_type,
            ]);
        }

        return $disk->download($attachment->path, $attachment->original_name, $headers);
    }
}
