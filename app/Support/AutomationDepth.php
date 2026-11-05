<?php

namespace App\Support;

use Closure;

/**
 * Rastreia a profundidade de cascata das automações no processo atual.
 * Eventos gerados durante a execução de uma regra herdam depth + 1,
 * e o motor recusa execução além de helpdesk.automation.max_depth.
 */
final class AutomationDepth
{
    private static int $depth = 0;

    public static function current(): int
    {
        return self::$depth;
    }

    public static function run(int $depth, Closure $callback): mixed
    {
        $previous = self::$depth;
        self::$depth = $depth;

        try {
            return $callback();
        } finally {
            self::$depth = $previous;
        }
    }
}
