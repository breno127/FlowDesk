<?php

namespace Tests\Feature;

use App\Enums\SlaState;
use App\Enums\SlaTimerStatus;
use App\Enums\SlaTimerType;
use App\Events\SlaBreached;
use App\Models\Ticket;
use App\Services\Sla\SlaCalculationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

class SlaLifecycleTest extends HelpdeskTestCase
{
    private function timer(Ticket $ticket, SlaTimerType $type)
    {
        return $ticket->slaTimers()->where('type', $type->value)->firstOrFail();
    }

    public function test_full_sla_lifecycle_with_pause_resume_first_response_and_breach(): void
    {
        Event::fake([SlaBreached::class]);
        $this->slaPolicy('normal', firstResponse: 60, resolution: 240);
        $agent = $this->agent();
        $sla = app(SlaCalculationService::class);

        Carbon::setTestNow('2026-11-05 10:00:00');
        $ticket = $this->service()->create(['subject' => 'Impressora parada'], $agent);

        $this->assertNotNull($ticket->sla_policy_id);
        $this->assertSame('2026-11-05 11:00:00', $this->timer($ticket, SlaTimerType::FirstResponse)->due_at->toDateTimeString());
        $this->assertSame('2026-11-05 14:00:00', $this->timer($ticket, SlaTimerType::Resolution)->due_at->toDateTimeString());

        // 30 min depois: "Aguardando Cliente" pausa os timers
        Carbon::setTestNow('2026-11-05 10:30:00');
        $this->service()->moveToColumn($ticket, $this->column('Aguardando Cliente'), null, $agent);
        $fr = $this->timer($ticket, SlaTimerType::FirstResponse);
        $this->assertSame(SlaTimerStatus::Paused, $fr->status);
        $this->assertSame(1800, $fr->elapsed_seconds);
        $this->assertSame(SlaState::Paused, $sla->snapshot($fr)->state);

        // 2h pausado não consome SLA; ao voltar, vence 30 min depois
        Carbon::setTestNow('2026-11-05 12:30:00');
        $this->service()->moveToColumn($ticket, $this->column('Em Análise'), null, $agent);
        $fr = $this->timer($ticket, SlaTimerType::FirstResponse);
        $this->assertSame(SlaTimerStatus::Running, $fr->status);
        $this->assertSame('2026-11-05 13:00:00', $fr->due_at->toDateTimeString());

        // Resposta pública do agente cumpre a 1ª resposta dentro do prazo
        Carbon::setTestNow('2026-11-05 12:40:00');
        $this->service()->addMessage($ticket, ['body' => 'Olá! Estamos verificando.'], $agent);
        $fr = $this->timer($ticket, SlaTimerType::FirstResponse);
        $this->assertSame(SlaTimerStatus::Completed, $fr->status);
        $this->assertNull($fr->breached_at);
        $this->assertSame(SlaState::Met, $sla->snapshot($fr)->state);
        $this->assertNotNull($ticket->fresh()->first_response_at);

        // Solução: 240 min − 30 consumidos antes da pausa → vence 12:30 + 210 min = 16:00
        Carbon::setTestNow('2026-11-05 16:05:00');
        $result = $sla->scan();
        $this->assertSame(1, $result['breaches']);
        Event::assertDispatched(SlaBreached::class);
        $resolution = $this->timer($ticket, SlaTimerType::Resolution);
        $this->assertSame('2026-11-05 16:00:00', $resolution->breached_at->toDateTimeString());

        // Varredura é idempotente
        $this->assertSame(0, $sla->scan()['breaches']);

        // Resolver encerra o timer, mantendo o registro da violação
        $this->service()->moveToColumn($ticket, $this->column('Resolvido'), null, $agent);
        $ticket->refresh();
        $this->assertNotNull($ticket->resolved_at);
        $resolution = $this->timer($ticket, SlaTimerType::Resolution);
        $this->assertSame(SlaTimerStatus::Completed, $resolution->status);
        $this->assertSame(SlaState::Breached, $sla->snapshot($resolution)->state);

        // Reabrir limpa resolved_at e retoma o timer de solução
        $this->service()->moveToColumn($ticket, $this->column('Em Análise'), null, $agent);
        $this->assertNull($ticket->fresh()->resolved_at);
        $this->assertSame(SlaTimerStatus::Running, $this->timer($ticket, SlaTimerType::Resolution)->status);
    }

    public function test_priority_change_reapplies_policy_keeping_elapsed_time(): void
    {
        $this->slaPolicy('normal', 60, 240);
        $this->priority('urgent')->id;
        $policy = \App\Models\SlaPolicy::query()->first();
        $policy->targets()->create([
            'ticket_priority_id' => $this->priority('urgent')->id,
            'first_response_minutes' => 15,
            'resolution_minutes' => 60,
        ]);

        Carbon::setTestNow('2026-11-05 10:00:00');
        $ticket = $this->service()->create(['subject' => 'Servidor fora do ar']);

        Carbon::setTestNow('2026-11-05 10:10:00');
        $this->service()->update($ticket, ['ticket_priority_id' => $this->priority('urgent')->id]);

        $fr = $this->timer($ticket, SlaTimerType::FirstResponse);
        $this->assertSame(15 * 60, $fr->target_seconds);
        $this->assertSame(600, $fr->elapsed_seconds);
        $this->assertSame('2026-11-05 10:15:00', $fr->due_at->toDateTimeString());
    }

    public function test_most_specific_policy_wins(): void
    {
        $this->slaPolicy('normal', 60, 240);
        $company = \App\Models\ClientCompany::query()->create(['name' => 'ACME']);
        $vip = \App\Models\SlaPolicy::query()->create(['name' => 'VIP', 'client_company_id' => $company->id, 'precedence' => 500]);
        $vip->targets()->create(['ticket_priority_id' => $this->priority('normal')->id, 'first_response_minutes' => 10, 'resolution_minutes' => 60]);

        $ticket = $this->service()->create(['subject' => 'Dúvida', 'client_company_id' => $company->id]);

        $this->assertSame($vip->id, $ticket->sla_policy_id);
        $this->assertSame(600, $this->timer($ticket, SlaTimerType::FirstResponse)->target_seconds);
    }
}
