<?php

namespace App\Support;

use App\Filament\Pages\KanbanBoard;
use App\Filament\Resources\TicketResource;
use App\Models\Ticket;

/**
 * Regras do perfil N1 ("somente Kanban").
 *
 * Aplicadas num único Gate::before, então valem para TODAS as policies:
 * sem `viewAny` nenhum Resource se registra na navegação, a busca global
 * fica vazia e as rotas dos cadastros respondem 403 — sem precisar tocar
 * em cada Resource/Policy.
 */
final class KanbanOnlyAccess
{
    /** Habilidades de policy liberadas — e apenas sobre um Ticket concreto. */
    private const TICKET_ABILITIES = ['view', 'move', 'reply', 'addInternalNote', 'viewInternalNotes', 'assignToSelf'];

    /**
     * null = segue o fluxo normal (Spatie/policy decide); false = negado.
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function check(string $ability, array $arguments): ?bool
    {
        // Permissões Spatie ("kanban.view", "tickets.move"...) seguem o perfil normalmente
        if (str_contains($ability, '.')) {
            return null;
        }

        $subject = $arguments[0] ?? null;

        if ($subject instanceof Ticket && in_array($ability, self::TICKET_ABILITIES, true)) {
            return null;
        }

        // Exigido pelo Filament para abrir QUALQUER página do TicketResource (inclusive a View).
        // A listagem continua bloqueada: sem item de menu, sem busca global e a rota
        // redireciona para o Kanban (RestrictKanbanOnlyUsers).
        if ($ability === 'viewAny' && $subject === Ticket::class) {
            return null;
        }

        return false;
    }

    /** Rotas que o N1 pode abrir diretamente (o resto redireciona para o Kanban). */
    public static function allowsRoute(?string $routeName): bool
    {
        return in_array($routeName, [
            KanbanBoard::getRouteName(),
            TicketResource::getRouteBaseName().'.view',
            'filament.admin.auth.logout',
            'filament.admin.tickets.attachments.download',
        ], true);
    }
}
