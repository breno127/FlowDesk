<?php

namespace Tests\Feature;

use App\Enums\CustomFieldType;
use App\Filament\Pages\KanbanBoard;
use App\Filament\Resources;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\TicketResource\Pages\CreateTicket;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\CustomField;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class FilamentPanelTest extends HelpdeskTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->agent('super-admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_every_panel_page_renders(): void
    {
        $this->slaPolicy();
        $ticket = $this->service()->create(['subject' => 'Render test']);

        $urls = [
            '/admin',
            KanbanBoard::getUrl(),
            Resources\TicketResource::getUrl('index'),
            Resources\TicketResource::getUrl('create'),
            Resources\TicketResource::getUrl('view', ['record' => $ticket]),
            Resources\TicketResource::getUrl('edit', ['record' => $ticket]),
            Resources\BoardResource::getUrl('index'),
            Resources\BoardResource::getUrl('edit', ['record' => $this->board()]),
            Resources\SlaPolicyResource::getUrl('index'),
            Resources\SlaPolicyResource::getUrl('create'),
            Resources\BusinessHourScheduleResource::getUrl('index'),
            Resources\BusinessHourScheduleResource::getUrl('create'),
            Resources\TicketTypeResource::getUrl('create'),
            Resources\CustomFieldResource::getUrl('index'),
            Resources\TicketPriorityResource::getUrl('index'),
            Resources\AutomationRuleResource::getUrl('index'),
            Resources\AutomationRuleResource::getUrl('create'),
            Resources\RoleResource::getUrl('index'),
            Resources\RoleResource::getUrl('create'),
            Resources\RoleResource::getUrl('edit', ['record' => Role::findByName('agente-n1')]),
            Resources\UserResource::getUrl('index'),
            Resources\UserResource::getUrl('create'),
            Resources\TeamResource::getUrl('index'),
            Resources\TeamResource::getUrl('create'),
            Resources\DepartmentResource::getUrl('index'),
            Resources\ClientCompanyResource::getUrl('index'),
            Resources\ContactResource::getUrl('index'),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_ticket_is_created_through_the_form_with_dynamic_custom_fields(): void
    {
        $type = TicketType::query()->create(['name' => 'TI', 'slug' => 'ti']);
        $field = CustomField::query()->create(['label' => 'Patrimônio', 'key' => 'asset_tag', 'type' => CustomFieldType::Text]);
        $type->customFields()->attach($field->id, ['is_required' => true, 'sort' => 1]);

        Livewire::test(CreateTicket::class)
            ->fillForm(['subject' => 'Monitor piscando', 'ticket_type_id' => $type->id])
            ->call('create')
            ->assertHasFormErrors(['custom_fields.asset_tag' => 'required']);

        Livewire::test(CreateTicket::class)
            ->fillForm([
                'subject' => 'Monitor piscando',
                'ticket_type_id' => $type->id,
                'custom_fields' => ['asset_tag' => 'MN-77'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = Ticket::query()->where('subject', 'Monitor piscando')->firstOrFail();
        $this->assertSame('MN-77', $ticket->fieldValues()->value('value_string'));
        $this->assertSame($this->admin->id, $ticket->created_by);
    }

    public function test_kanban_drag_and_drop_moves_ticket_and_respects_wip(): void
    {
        $ticket = $this->service()->create(['subject' => 'Arrastar']);
        $analysis = $this->column('Em Análise');

        Livewire::test(KanbanBoard::class)
            ->assertSee('Arrastar')
            ->call('moveTicket', $ticket->id, $analysis->id, 0)
            ->assertNotified();

        $this->assertSame($analysis->id, $ticket->fresh()->board_column_id);

        // WIP: bloqueia para quem não tem kanban.bypass_wip
        $analysis->update(['wip_limit' => 1]);
        $other = $this->service()->create(['subject' => 'Outro']);
        $agent = $this->agent('agente-n2');
        $this->actingAs($agent);

        $other->update(['team_id' => null, 'assignee_id' => $agent->id]);
        Livewire::test(KanbanBoard::class)->call('moveTicket', $other->id, $analysis->id, 0);
        $this->assertNotSame($analysis->id, $other->fresh()->board_column_id);
    }

    public function test_reply_from_view_page_completes_first_response(): void
    {
        $this->slaPolicy();
        $ticket = $this->service()->create(['subject' => 'Responder']);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->callAction('reply', ['body' => 'Olá, já estamos verificando.'])
            ->assertHasNoActionErrors();

        $this->assertNotNull($ticket->fresh()->first_response_at);
    }

    public function test_role_is_created_with_granular_permissions(): void
    {
        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'supervisor',
                Resources\RoleResource::groupField('Tickets') => ['tickets.view_all', 'tickets.delete'],
                Resources\RoleResource::groupField('Dashboards') => ['dashboards.view_others'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::findByName('supervisor');
        $this->assertEqualsCanonicalizing(
            ['tickets.view_all', 'tickets.delete', 'dashboards.view_others'],
            $role->permissions->pluck('name')->all()
        );
    }

    public function test_agent_without_permission_cannot_see_others_tickets_in_table(): void
    {
        $mine = $this->agent('agente-n1');
        $own = $this->service()->create(['subject' => 'Meu chamado', 'assignee_id' => $mine->id]);
        $foreign = $this->service()->create(['subject' => 'Chamado alheio']);

        $this->actingAs($mine);

        Livewire::test(ListTickets::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);

        $this->get(Resources\RoleResource::getUrl('index'))->assertForbidden();
    }
}
