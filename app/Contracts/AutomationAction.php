<?php

namespace App\Contracts;

use App\Models\Ticket;
use App\Services\Automation\AutomationContext;

/**
 * Ação executável por uma regra de automação.
 * Registre novas ações em config('helpdesk.automation.actions').
 */
interface AutomationAction
{
    public static function label(): string;

    /**
     * Componentes Filament para configurar os parâmetros da ação no construtor de regras.
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function schema(): array;

    /** Executa e devolve um resumo legível para o log de execução. */
    public function execute(Ticket $ticket, array $data, AutomationContext $context): string;
}
