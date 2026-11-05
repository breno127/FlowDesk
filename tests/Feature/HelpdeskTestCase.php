<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\SlaPolicy;
use App\Models\TicketPriority;
use App\Models\User;
use App\Services\Tickets\TicketService;
use Database\Seeders\HelpdeskBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class HelpdeskTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HelpdeskBootstrapSeeder::class);
    }

    protected function service(): TicketService
    {
        return app(TicketService::class);
    }

    protected function board(): Board
    {
        return Board::query()->where('is_default', true)->firstOrFail();
    }

    protected function column(string $name): BoardColumn
    {
        return $this->board()->columns()->where('name', $name)->firstOrFail();
    }

    protected function priority(string $slug): TicketPriority
    {
        return TicketPriority::query()->where('slug', $slug)->firstOrFail();
    }

    protected function agent(string $role = 'agente-n2', array $attributes = []): User
    {
        return User::factory()->create($attributes + ['is_agent' => true, 'is_active' => true])->assignRole($role);
    }

    /** Política 24x7 com metas para a prioridade informada. */
    protected function slaPolicy(string $prioritySlug = 'normal', int $firstResponse = 60, int $resolution = 240, ?int $scheduleId = null): SlaPolicy
    {
        $policy = SlaPolicy::query()->create([
            'name' => 'Padrão',
            'business_hour_schedule_id' => $scheduleId,
            'precedence' => 100,
            'is_active' => true,
        ]);

        $policy->targets()->create([
            'ticket_priority_id' => $this->priority($prioritySlug)->id,
            'first_response_minutes' => $firstResponse,
            'resolution_minutes' => $resolution,
            'warning_percent' => 75,
        ]);

        return $policy;
    }
}
