<?php

namespace Tests\Feature;

use App\Enums\CustomFieldType;
use App\Enums\MessageVisibility;
use App\Enums\RoutingStrategy;
use App\Exceptions\Helpdesk\HelpdeskException;
use App\Models\AutomationRule;
use App\Models\AutomationRuleRun;
use App\Models\CustomField;
use App\Models\Department;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Notifications\AutomationAlertNotification;
use App\Notifications\MentionedInTicketNotification;
use App\Services\CustomFields\CustomFieldService;
use App\Services\Kanban\BoardService;
use Illuminate\Support\Facades\Notification;

class KanbanAndAutomationTest extends HelpdeskTestCase
{
    public function test_automation_rule_fires_when_card_enters_column(): void
    {
        Notification::fake();
        $agent = $this->agent();
        $analysis = $this->column('Em Análise');

        AutomationRule::query()->create([
            'name' => 'Avisar ao entrar em análise',
            'event' => 'ticket.moved',
            'match' => 'all',
            'conditions' => [['field' => 'board_column_id', 'operator' => 'equals', 'value' => (string) $analysis->id]],
            'actions' => [
                ['type' => 'add_internal_note', 'data' => ['body' => 'Ticket {number} entrou em {column}']],
                ['type' => 'notify_assignee', 'data' => ['title' => '{number} em análise', 'message' => '{subject}']],
            ],
            'is_active' => true,
        ]);

        $ticket = $this->service()->create(['subject' => 'Erro no ERP', 'assignee_id' => $agent->id]);
        $this->service()->moveToColumn($ticket, $analysis, null, $agent);

        $note = $ticket->messages()->where('visibility', MessageVisibility::Internal->value)->first();
        $this->assertNotNull($note);
        $this->assertSame("Ticket {$ticket->number} entrou em Em Análise", $note->body);
        Notification::assertSentTo($agent, AutomationAlertNotification::class);
        $this->assertSame('success', AutomationRuleRun::query()->first()->status);
    }

    public function test_load_balanced_assignment_picks_least_loaded_agent(): void
    {
        $department = Department::query()->create(['name' => 'Suporte', 'slug' => 'suporte']);
        $team = Team::query()->create([
            'department_id' => $department->id, 'name' => 'N1', 'slug' => 'n1', 'tier' => 1,
            'routing_strategy' => RoutingStrategy::LoadBalanced, 'is_active' => true,
        ]);
        $busy = $this->agent();
        $free = $this->agent();
        $team->members()->attach([$busy->id => ['capacity' => 1], $free->id => ['capacity' => 1]]);

        $this->service()->create(['subject' => 'Urgente', 'ticket_priority_id' => $this->priority('urgent')->id, 'assignee_id' => $busy->id, 'team_id' => $team->id]);

        $ticket = $this->service()->create(['subject' => 'Novo chamado', 'team_id' => $team->id]);

        $this->assertSame($free->id, $ticket->assignee_id);
        $this->assertNotNull($ticket->assigned_at);
    }

    public function test_capacity_cap_leaves_ticket_unassigned(): void
    {
        $department = Department::query()->create(['name' => 'Suporte', 'slug' => 'suporte']);
        $team = Team::query()->create([
            'department_id' => $department->id, 'name' => 'N2', 'slug' => 'n2', 'tier' => 2,
            'routing_strategy' => RoutingStrategy::RoundRobin, 'max_weight_per_agent' => 2, 'is_active' => true,
        ]);
        $team->members()->attach($this->agent()->id, ['capacity' => 1]);

        $first = $this->service()->create(['subject' => 'A', 'team_id' => $team->id]);  // peso 2 (normal)
        $second = $this->service()->create(['subject' => 'B', 'team_id' => $team->id]);

        $this->assertNotNull($first->assignee_id);
        $this->assertNull($second->assignee_id);
    }

    public function test_wip_limit_blocks_move_and_positions_are_kept_contiguous(): void
    {
        $agent = $this->agent();
        $analysis = $this->column('Em Análise');
        $analysis->update(['wip_limit' => 2]);

        $a = $this->service()->create(['subject' => 'A']);
        $b = $this->service()->create(['subject' => 'B']);
        $c = $this->service()->create(['subject' => 'C']);

        $this->service()->moveToColumn($a, $analysis, null, $agent);
        $this->service()->moveToColumn($b, $analysis, 0, $agent); // B no topo

        $this->assertSame([$b->id, $a->id], Ticket::query()->where('board_column_id', $analysis->id)->orderBy('position')->pluck('id')->all());
        $this->assertSame([0], Ticket::query()->where('board_column_id', $this->column('Novo')->id)->pluck('position')->all());

        $this->expectException(HelpdeskException::class);
        $this->service()->moveToColumn($c, $analysis, null, $agent);
    }

    public function test_columns_can_be_created_renamed_and_removed_only_when_empty(): void
    {
        $board = $this->board();
        $columns = $board->columns->map(fn ($c) => $c->only(['id', 'name', 'color', 'category', 'is_initial']))
            ->map(fn ($c) => ['category' => $c['category']->value] + $c)->all();

        $columns[1]['name'] = 'Triagem';
        $columns[] = ['name' => 'Homologação', 'category' => 'open', 'sla_behavior' => 'run'];

        $board = app(BoardService::class)->syncColumns($board, $columns);
        $this->assertSame(['Novo', 'Triagem', 'Aguardando Cliente', 'Resolvido', 'Fechado', 'Homologação'], $board->columns->pluck('name')->all());

        $this->service()->create(['subject' => 'Bloqueia remoção']); // fica em "Novo"
        $this->expectException(HelpdeskException::class);
        app(BoardService::class)->syncColumns($board, array_slice($columns, 1));
    }

    public function test_custom_fields_are_stored_in_typed_eav_columns(): void
    {
        $type = TicketType::query()->create(['name' => 'TI', 'slug' => 'ti']);
        $asset = CustomField::query()->create(['label' => 'Patrimônio', 'key' => 'asset_tag', 'type' => CustomFieldType::Text]);
        $cost = CustomField::query()->create(['label' => 'Custo', 'key' => 'cost', 'type' => CustomFieldType::Decimal]);
        $tags = CustomField::query()->create(['label' => 'Tags', 'key' => 'tags', 'type' => CustomFieldType::MultiSelect,
            'options' => [['value' => 'hw', 'label' => 'Hardware'], ['value' => 'sw', 'label' => 'Software']]]);
        $type->customFields()->attach([$asset->id => ['is_required' => true, 'sort' => 1], $cost->id => ['is_required' => false, 'sort' => 2], $tags->id => ['is_required' => false, 'sort' => 3]]);

        $ticket = $this->service()->create([
            'subject' => 'Notebook quebrado',
            'ticket_type_id' => $type->id,
            'custom_fields' => ['asset_tag' => 'NB-0042', 'cost' => '1250.90', 'tags' => ['hw']],
        ]);

        $fields = app(CustomFieldService::class);
        $this->assertSame(['asset_tag' => 'NB-0042', 'cost' => 1250.9, 'tags' => ['hw']], $fields->valuesFor($ticket));
        $this->assertCount(3, $fields->formComponents($type->id));
        $this->assertSame(1250.9, $ticket->fieldValues()->where('custom_field_id', $cost->id)->value('value_number') + 0.0);

        // Troca de tipo remove valores que não pertencem ao novo tipo
        $finance = TicketType::query()->create(['name' => 'Financeiro', 'slug' => 'fin']);
        $this->service()->update($ticket, ['ticket_type_id' => $finance->id]);
        $this->assertSame([], app(CustomFieldService::class)->valuesFor($ticket->fresh()));
    }

    public function test_visibility_scope_respects_granular_permissions(): void
    {
        $n1 = $this->agent('agente-n1');
        $other = $this->agent('agente-n1');

        $mine = $this->service()->create(['subject' => 'Meu', 'assignee_id' => $n1->id]);
        $this->service()->create(['subject' => 'Do colega', 'assignee_id' => $other->id]);

        $this->assertSame([$mine->id], Ticket::query()->visibleTo($n1)->pluck('id')->all());
        $this->assertTrue($n1->can('view', $mine));
        $this->assertFalse($n1->can('delete', $mine));
    }

    public function test_mentions_in_internal_notes_notify_agents(): void
    {
        Notification::fake();
        $author = $this->agent();
        $mentioned = $this->agent('agente-n1', ['username' => 'maria.souza']);
        $ticket = $this->service()->create(['subject' => 'Ajuda']);

        $message = $this->service()->addMessage($ticket, ['body' => 'Pode olhar, @maria.souza?', 'visibility' => 'internal'], $author);

        $this->assertSame([$mentioned->id], $message->mentions()->pluck('users.id')->all());
        Notification::assertSentTo($mentioned, MentionedInTicketNotification::class);
        $this->assertNull($ticket->fresh()->first_response_at); // nota interna não conta como resposta
    }
}
