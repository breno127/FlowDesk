<?php

namespace Tests\Feature;

use App\Models\BusinessHourSchedule;
use App\Services\Sla\BusinessTimeCalculator;
use Carbon\CarbonImmutable;

class BusinessTimeCalculatorTest extends HelpdeskTestCase
{
    private function schedule(): BusinessHourSchedule
    {
        // Seed: seg–sex 08:00–18:00, America/Sao_Paulo (UTC-3)
        return BusinessHourSchedule::query()->where('is_default', true)->firstOrFail();
    }

    public function test_adding_time_skips_nights_and_weekends(): void
    {
        $fridayAt17 = CarbonImmutable::parse('2026-11-06 17:00', 'America/Sao_Paulo');

        $due = app(BusinessTimeCalculator::class)->addSeconds($this->schedule(), $fridayAt17, 2 * 3600);

        $this->assertSame('2026-11-09 09:00', $due->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'));
    }

    public function test_holidays_are_skipped(): void
    {
        $schedule = $this->schedule();
        $schedule->holidays()->create(['name' => 'Feriado', 'date' => '2026-11-09', 'is_recurring' => false]);

        $fridayAt17 = CarbonImmutable::parse('2026-11-06 17:00', 'America/Sao_Paulo');
        $due = app(BusinessTimeCalculator::class)->addSeconds($schedule->fresh(), $fridayAt17, 2 * 3600);

        $this->assertSame('2026-11-10 09:00', $due->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'));
    }

    public function test_seconds_between_counts_only_business_hours(): void
    {
        $calc = app(BusinessTimeCalculator::class);

        $seconds = $calc->secondsBetween(
            $this->schedule(),
            CarbonImmutable::parse('2026-11-06 17:00', 'America/Sao_Paulo'),
            CarbonImmutable::parse('2026-11-09 09:00', 'America/Sao_Paulo'),
        );

        $this->assertSame(2 * 3600, $seconds);
    }

    public function test_business_time_detection(): void
    {
        $calc = app(BusinessTimeCalculator::class);

        $this->assertTrue($calc->isBusinessTime($this->schedule(), CarbonImmutable::parse('2026-11-05 10:00', 'America/Sao_Paulo')));
        $this->assertFalse($calc->isBusinessTime($this->schedule(), CarbonImmutable::parse('2026-11-05 19:00', 'America/Sao_Paulo')));
        $this->assertFalse($calc->isBusinessTime($this->schedule(), CarbonImmutable::parse('2026-11-07 10:00', 'America/Sao_Paulo')));
    }
}
