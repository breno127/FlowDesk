<?php

namespace App\Exceptions\Helpdesk;

use RuntimeException;

/**
 * Erro de regra de negócio — mensagem segura para exibir ao usuário.
 */
class HelpdeskException extends RuntimeException
{
    public static function wipLimit(string $column, int $limit): self
    {
        return new self(sprintf('A coluna "%s" atingiu o limite WIP de %d tickets.', $column, $limit));
    }

    public static function columnNotInBoard(): self
    {
        return new self('A coluna de destino não pertence ao quadro do ticket.');
    }

    public static function columnNotEmpty(string $column, int $count): self
    {
        return new self(sprintf('A coluna "%s" possui %d ticket(s). Mova-os antes de removê-la.', $column, $count));
    }

    public static function noResolvedColumn(): self
    {
        return new self('O quadro não possui coluna da categoria "Resolvido".');
    }

    public static function boardWithoutColumns(): self
    {
        return new self('O quadro precisa de ao menos uma coluna.');
    }

    public static function noDefaultBoard(): self
    {
        return new self('Nenhum quadro padrão configurado. Cadastre um quadro em Kanban › Quadros.');
    }

    public static function noPriority(): self
    {
        return new self('Nenhuma prioridade cadastrada. Cadastre ao menos uma em Configuração › Prioridades.');
    }
}
