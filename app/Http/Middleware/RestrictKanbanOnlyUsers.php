<?php

namespace App\Http\Middleware;

use App\Filament\Pages\KanbanBoard;
use App\Support\KanbanOnlyAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Perfil N1: qualquer página do painel fora da lista permitida (dashboard,
 * cadastros, relatórios...) redireciona para o Kanban — inclusive a home
 * pós-login. As policies já negam o acesso; isto evita telas de 403.
 */
class RestrictKanbanOnlyUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isKanbanOnly() && ! KanbanOnlyAccess::allowsRoute($request->route()?->getName())) {
            return redirect()->to(KanbanBoard::getUrl());
        }

        return $next($request);
    }
}
