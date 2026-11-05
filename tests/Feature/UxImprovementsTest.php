<?php

namespace Tests\Feature;

use App\Enums\MessageVisibility;
use App\Filament\Pages\KanbanBoard;
use App\Filament\Resources;
use App\Filament\Widgets\AgentResolutionWidget;
use App\Filament\Widgets\QuickAccessWidget;
use App\Filament\Widgets\TicketOverviewStats;
use App\Livewire\TicketConversation;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class UxImprovementsTest extends HelpdeskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function team(string $slug): Team
    {
        $department = Department::query()->firstOrCreate(['slug' => 'suporte'], ['name' => 'Suporte']);

        return Team::query()->create([
            'department_id' => $department->id, 'name' => strtoupper($slug), 'slug' => $slug, 'tier' => 1, 'is_active' => true,
        ]);
    }

    private function n1In(Team $team): User
    {
        $user = $this->agent('agente-n1');
        $team->members()->attach($user->id, ['capacity' => 5]);

        return $user;
    }

    // ================================================================== 1. N1

    public function test_n1_only_sees_tickets_of_its_own_team(): void
    {
        $alpha = $this->team('alpha');
        $beta = $this->team('beta');
        $n1 = $this->n1In($alpha);

        $ofTeam = $this->service()->create(['subject' => 'Da equipe', 'team_id' => $alpha->id]);
        // Mesmo atribuído a ele, ticket de outra equipe fica fora da fila do N1
        $this->service()->create(['subject' => 'Outra equipe', 'team_id' => $beta->id, 'assignee_id' => $n1->id]);

        $this->assertSame([$ofTeam->id], Ticket::query()->visibleTo($n1)->pluck('id')->all());
    }

    public function test_n1_panel_is_restricted_to_kanban(): void
    {
        $alpha = $this->team('alpha');
        $n1 = $this->n1In($alpha);
        $ticket = $this->service()->create(['subject' => 'Fila', 'team_id' => $alpha->id]);
        $this->actingAs($n1);

        // Kanban e o ticket da equipe abrem; a navegação só tem o Kanban
        $this->get(KanbanBoard::getUrl())->assertOk()->assertSee('Fila');
        $this->get(Resources\TicketResource::getUrl('view', ['record' => $ticket]))->assertOk();

        $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())->map->getLabel()->values()->all();
        $this->assertSame(['Kanban'], $labels);

        // Dashboard, lista de tickets e cadastros redirecionam para o Kanban
        foreach (['/admin', Resources\TicketResource::getUrl('index'), Resources\ContactResource::getUrl('index'), Resources\BoardResource::getUrl('index')] as $url) {
            $this->get($url)->assertRedirect(KanbanBoard::getUrl());
        }

        // Policies negam o resto mesmo sem passar pelo middleware
        $this->assertFalse(Resources\TicketResource::canGloballySearch());
        $this->assertFalse($n1->can('viewAny', Contact::class));
        $this->assertFalse($n1->can('create', Ticket::class));
        $this->assertFalse($n1->can('update', $ticket));
        $this->assertTrue($n1->can('move', $ticket));
        $this->assertTrue($n1->can('reply', $ticket));
    }

    public function test_n1_cannot_open_ticket_of_another_team(): void
    {
        $n1 = $this->n1In($this->team('alpha'));
        $foreign = $this->service()->create(['subject' => 'Alheio', 'team_id' => $this->team('beta')->id]);

        $this->actingAs($n1)
            ->get(Resources\TicketResource::getUrl('view', ['record' => $foreign]))
            ->assertNotFound(); // a query do Resource já filtra: nem revela que o ticket existe
    }

    // ================================================================== 2/3. Dashboard

    public function test_dashboard_widgets_render_and_count(): void
    {
        $agent = $this->agent('gestor');
        $this->actingAs($agent);

        $this->service()->create(['subject' => 'Hoje A', 'assignee_id' => $agent->id]);
        $this->service()->create(['subject' => 'Hoje B']);

        // Widgets são lazy: a página só monta os componentes
        $this->get('/admin')->assertOk()
            ->assertSeeLivewire(TicketOverviewStats::class)
            ->assertSeeLivewire(QuickAccessWidget::class)
            ->assertSeeLivewire(AgentResolutionWidget::class);

        Livewire::test(TicketOverviewStats::class)
            ->assertSeeInOrder(['Abertos hoje', '2', 'Meus tickets', '1', 'Sem responsável', '1']);
        Livewire::test(QuickAccessWidget::class)->assertSee('Chat')->assertSee('Agenda')->assertSee('Em breve');

        config(['helpdesk.shortcuts.chat_url' => 'https://chat.example.test']);
        Livewire::test(QuickAccessWidget::class)->assertSeeHtml('href="https://chat.example.test"');
    }

    public function test_agent_resolution_widget_counts_resolved_in_period(): void
    {
        $manager = $this->agent('gestor');
        $alice = $this->agent('agente-n2', ['name' => 'Alice']);
        $bob = $this->agent('agente-n2', ['name' => 'Bob']);
        $this->actingAs($manager);

        foreach ([$alice, $alice, $bob] as $who) {
            $ticket = $this->service()->create(['subject' => 'X', 'assignee_id' => $who->id]);
            $this->service()->resolve($ticket, $who);
        }
        // Resolvido em mês anterior: não entra no "mês atual"
        $old = $this->service()->create(['subject' => 'Antigo', 'assignee_id' => $bob->id]);
        $this->service()->resolve($old, $bob);
        Ticket::query()->whereKey($old->id)->update(['resolved_at' => now()->subMonthsNoOverflow(2)]);

        $component = Livewire::test(AgentResolutionWidget::class)
            ->assertCanSeeTableRecords([$alice, $bob], inOrder: true);

        $records = $component->instance()->getTableRecords()->keyBy('id');
        $this->assertSame(2, (int) $records[$alice->id]->resolved_count);
        $this->assertSame(1, (int) $records[$bob->id]->resolved_count);

        $component->filterTable('period', 'all');
        $this->assertSame(2, (int) $component->instance()->getTableRecords()->keyBy('id')[$bob->id]->resolved_count);
    }

    public function test_agent_without_view_others_only_sees_own_row(): void
    {
        $alice = $this->agent('agente-n2');
        $bob = $this->agent('agente-n2');
        $this->actingAs($alice);

        Livewire::test(AgentResolutionWidget::class)
            ->assertCanSeeTableRecords([$alice])
            ->assertCanNotSeeTableRecords([$bob]);
    }

    // ================================================================== 4. Conversa

    public function test_conversation_lists_newest_first_and_hides_internal_notes(): void
    {
        $agent = $this->agent();
        $ticket = $this->service()->create(['subject' => 'Chat']);

        $this->travel(-2)->minutes();
        $this->service()->addMessage($ticket, ['body' => 'Primeira'], $agent);
        $this->travelBack();
        $this->service()->addMessage($ticket, ['body' => 'Nota secreta', 'visibility' => MessageVisibility::Internal], $agent);
        $this->service()->addMessage($ticket, ['body' => 'Última'], $agent);

        $this->actingAs($agent);
        Livewire::test(TicketConversation::class, ['record' => $ticket])
            ->assertSeeInOrder(['Última', 'Nota secreta', 'Primeira']);

        // Perfil sem nota interna não vê a nota
        Role::create(['name' => 'leitor'])->givePermissionTo(['tickets.view_own', 'tickets.reply']);
        $reader = $this->agent('leitor');
        $this->service()->assign($ticket, $reader);

        $this->actingAs($reader);
        Livewire::test(TicketConversation::class, ['record' => $ticket->fresh()])
            ->assertSee('Última')
            ->assertDontSee('Nota secreta');
    }

    public function test_reply_with_attachment_is_stored_and_downloadable(): void
    {
        Storage::fake('local');
        $agent = $this->agent();
        $ticket = $this->service()->create(['subject' => 'Anexo']);
        $this->actingAs($agent);

        Livewire::test(TicketConversation::class, ['record' => $ticket])
            ->fillForm([
                'visibility' => 'public',
                'body' => '<p>Segue o relatório <script>alert(1)</script></p>',
                'attachments' => [UploadedFile::fake()->create('relatorio.pdf', 120, 'application/pdf')],
            ])
            ->call('send')
            ->assertHasNoFormErrors();

        $message = $ticket->messages()->latest('id')->first();
        $this->assertStringNotContainsString('<script', $message->body);

        $attachment = $message->attachments()->sole();
        $this->assertSame('relatorio.pdf', $attachment->original_name);
        Storage::disk('local')->assertExists($attachment->path);

        $this->get($attachment->url(download: true))->assertOk()->assertDownload('relatorio.pdf');

        // Quem não vê o ticket não baixa o anexo
        Role::create(['name' => 'nenhum'])->givePermissionTo('kanban.view');
        $this->actingAs($this->agent('nenhum'))->get($attachment->url())->assertForbidden();
    }

    public function test_attachment_only_message_is_accepted_and_empty_is_rejected(): void
    {
        Storage::fake('local');
        $this->actingAs($this->agent());
        $ticket = $this->service()->create(['subject' => 'Vazio']);

        Livewire::test(TicketConversation::class, ['record' => $ticket])
            ->fillForm(['visibility' => 'public', 'body' => null])
            ->call('send')
            ->assertHasFormErrors(['body' => 'required']);

        Livewire::test(TicketConversation::class, ['record' => $ticket])
            ->fillForm(['visibility' => 'public', 'attachments' => [UploadedFile::fake()->image('print.png')]])
            ->call('send')
            ->assertHasNoFormErrors();

        $this->assertSame(1, $ticket->messages()->count());
    }
}
