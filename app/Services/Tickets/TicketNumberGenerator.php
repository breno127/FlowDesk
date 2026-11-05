<?php

namespace App\Services\Tickets;

use App\Models\TicketSequence;
use Illuminate\Support\Facades\DB;

/**
 * Gera números legíveis e sem colisão (ex.: HD-2026-000123) usando uma linha
 * de sequência travada por FOR UPDATE — seguro sob concorrência.
 */
class TicketNumberGenerator
{
    public function next(): string
    {
        $config = config('helpdesk.ticket_number');
        $now = now();

        $scope = ($config['reset_yearly'] && str_contains($config['format'], '{year}'))
            ? $now->format('Y')
            : 'global';

        $value = DB::transaction(function () use ($scope) {
            TicketSequence::query()->insertOrIgnore([
                'scope' => $scope, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $sequence = TicketSequence::query()->where('scope', $scope)->lockForUpdate()->firstOrFail();
            $sequence->increment('last_value');

            return $sequence->last_value;
        });

        return strtr($config['format'], [
            '{prefix}' => $config['prefix'],
            '{year}' => $now->format('Y'),
            '{month}' => $now->format('m'),
            '{seq}' => str_pad((string) $value, (int) $config['pad'], '0', STR_PAD_LEFT),
        ]);
    }
}
