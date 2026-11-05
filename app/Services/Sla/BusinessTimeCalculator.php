<?php

namespace App\Services\Sla;

use App\Models\BusinessHourSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;

/**
 * Aritmética de "tempo útil": soma/subtrai segundos considerando apenas as
 * janelas de expediente do calendário, ignorando feriados.
 *
 * - Calendário null = 24x7 (tempo corrido).
 * - Intervalos são interpretados no fuso do calendário (lida com horário de verão).
 * - Intervalo com ends_at 00:00 significa "até a meia-noite".
 *
 * Registrado como `scoped` no container: o cache de configuração é descartado
 * a cada request/job, então edições no painel valem imediatamente.
 */
final class BusinessTimeCalculator
{
    private const DAY = 86400;

    /** @var array<int, array{tz: string, intervals: array<int, list<array{0:int, 1:int}>>, dates: array<string, true>, recurring: array<string, true>}> */
    private array $cache = [];

    /** Segundos úteis entre dois instantes. */
    public function secondsBetween(?BusinessHourSchedule $schedule, CarbonInterface $from, CarbonInterface $to): int
    {
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();

        if ($toTs <= $fromTs) {
            return 0;
        }

        if ($schedule === null) {
            return $toTs - $fromTs;
        }

        $total = 0;

        foreach ($this->windows($schedule, $from) as [$start, $end]) {
            if ($start >= $toTs) {
                break;
            }

            $s = max($start, $fromTs);
            $e = min($end, $toTs);

            if ($e > $s) {
                $total += $e - $s;
            }
        }

        return $total;
    }

    /**
     * Instante em que $seconds úteis terão decorrido a partir de $from.
     * Retorna null quando o calendário não tem expediente dentro do horizonte configurado.
     */
    public function addSeconds(?BusinessHourSchedule $schedule, CarbonInterface $from, int $seconds): ?CarbonImmutable
    {
        $appTz = config('app.timezone');

        if ($seconds <= 0) {
            return CarbonImmutable::instance($from)->setTimezone($appTz);
        }

        if ($schedule === null) {
            return CarbonImmutable::instance($from)->addSeconds($seconds)->setTimezone($appTz);
        }

        $fromTs = $from->getTimestamp();
        $remaining = $seconds;

        foreach ($this->windows($schedule, $from) as [$start, $end]) {
            if ($end <= $fromTs) {
                continue;
            }

            $s = max($start, $fromTs);
            $length = $end - $s;

            if ($length >= $remaining) {
                return CarbonImmutable::createFromTimestamp($s + $remaining, $appTz);
            }

            $remaining -= $length;
        }

        return null;
    }

    /** O instante está dentro do expediente? (usado para decidir se o contador visual "anda"). */
    public function isBusinessTime(?BusinessHourSchedule $schedule, CarbonInterface $at): bool
    {
        if ($schedule === null) {
            return true;
        }

        $ts = $at->getTimestamp();

        foreach ($this->windows($schedule, $at, 1) as [$start, $end]) {
            if ($start > $ts) {
                return false;
            }
            if ($ts < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gera janelas [inicioTs, fimTs) em ordem crescente, a partir do dia local de $from.
     *
     * @return Generator<int, array{0:int, 1:int}>
     */
    private function windows(BusinessHourSchedule $schedule, CarbonInterface $from, ?int $maxDays = null): Generator
    {
        $config = $this->config($schedule);
        $limit = $maxDays ?? (int) config('helpdesk.sla.max_lookahead_days', 730);

        if ($config['intervals'] === []) {
            return;
        }

        $day = CarbonImmutable::instance($from)->setTimezone($config['tz'])->startOfDay();

        for ($i = 0; $i < $limit; $i++, $day = $day->addDay()) {
            if (isset($config['dates'][$day->format('Y-m-d')]) || isset($config['recurring'][$day->format('m-d')])) {
                continue;
            }

            foreach ($config['intervals'][$day->isoWeekday()] ?? [] as [$startSec, $endSec]) {
                yield [$this->timestampAt($day, $startSec), $this->timestampAt($day, $endSec)];
            }
        }
    }

    private function timestampAt(CarbonImmutable $day, int $secondOfDay): int
    {
        if ($secondOfDay >= self::DAY) {
            return $day->addDay()->startOfDay()->getTimestamp();
        }

        return $day->setTime(intdiv($secondOfDay, 3600), intdiv($secondOfDay % 3600, 60), $secondOfDay % 60)->getTimestamp();
    }

    /** Carrega e normaliza (ordena + funde sobreposições) a configuração do calendário. */
    private function config(BusinessHourSchedule $schedule): array
    {
        $key = (int) $schedule->getKey();

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $schedule->loadMissing(['intervals', 'holidays']);

        $byDay = [];
        foreach ($schedule->intervals as $interval) {
            $start = $this->secondsOfDay((string) $interval->starts_at);
            $end = $this->secondsOfDay((string) $interval->ends_at);

            if ($end === 0) {
                $end = self::DAY; // 00:00 como fim = meia-noite
            }

            if ($end > $start) {
                $byDay[(int) $interval->weekday][] = [$start, $end];
            }
        }

        foreach ($byDay as $weekday => $ranges) {
            usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($ranges as $range) {
                $last = array_key_last($merged);
                if ($last !== null && $range[0] <= $merged[$last][1]) {
                    $merged[$last][1] = max($merged[$last][1], $range[1]);
                } else {
                    $merged[] = $range;
                }
            }
            $byDay[$weekday] = $merged;
        }

        $dates = [];
        $recurring = [];
        foreach ($schedule->holidays as $holiday) {
            if ($holiday->is_recurring) {
                $recurring[$holiday->date->format('m-d')] = true;
            } else {
                $dates[$holiday->date->format('Y-m-d')] = true;
            }
        }

        return $this->cache[$key] = [
            'tz' => $schedule->timezone ?: config('app.timezone'),
            'intervals' => $byDay,
            'dates' => $dates,
            'recurring' => $recurring,
        ];
    }

    private function secondsOfDay(string $time): int
    {
        $parts = array_map('intval', explode(':', $time)) + [0, 0, 0];

        return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
    }
}
