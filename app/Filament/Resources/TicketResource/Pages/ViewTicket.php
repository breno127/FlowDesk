<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Livewire\TicketConversation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CustomFields\CustomFieldService;
use App\Services\Tickets\TicketService;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function getTitle(): string
    {
        return $this->record->number;
    }

    public function getSubheading(): ?string
    {
        return $this->record->subject;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(3)
            ->schema([
                Components\Tabs::make('ticket')
                    ->columnSpan(['lg' => 2])
                    ->persistTabInQueryString()
                    ->tabs([
                        // Conversa abre por padrão: editor no topo + mensagens (mais recente primeiro)
                        Components\Tabs\Tab::make('Conversa')
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->badge(fn (Ticket $record) => $record->messages()->count() ?: null)
                            ->schema([
                                Components\Livewire::make(TicketConversation::class)
                                    ->key(fn (Ticket $record) => 'conversation-'.$record->id),
                            ]),

                        Components\Tabs\Tab::make('Detalhes')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Components\Section::make('Descrição')
                                    ->schema([
                                        Components\TextEntry::make('description')
                                            ->hiddenLabel()
                                            ->html()
                                            ->placeholder('Sem descrição'),
                                    ]),

                                Components\Section::make(fn (Ticket $record) => $record->type?->name ?? 'Campos')
                                    ->columns(2)
                                    ->visible(fn (Ticket $record) => app(CustomFieldService::class)->fieldsForType($record->ticket_type_id)->isNotEmpty())
                                    ->schema(fn (Ticket $record) => $this->customFieldEntries($record)),
                            ]),
                    ]),

                Components\Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Components\Section::make('SLA')
                            ->icon('heroicon-o-clock')
                            ->schema([
                                Components\ViewEntry::make('sla')
                                    ->hiddenLabel()
                                    ->view('filament.infolists.ticket-sla'),
                                Components\TextEntry::make('slaPolicy.name')->label('Política')->placeholder('Sem política aplicável'),
                            ]),

                        Components\Section::make('Status')
                            ->columns(2)
                            ->schema([
                                Components\TextEntry::make('column.name')->label('Coluna')->badge()
                                    ->color(fn (Ticket $record) => Color::hex($record->column?->color ?? '#71717a')),
                                Components\TextEntry::make('priority.name')->label('Prioridade')->badge()
                                    ->color(fn (Ticket $record) => Color::hex($record->priority?->color ?? '#71717a')),
                                Components\TextEntry::make('assignee.name')->label('Responsável')->placeholder('—'),
                                Components\TextEntry::make('team.name')->label('Equipe')->placeholder('—'),
                                Components\TextEntry::make('department.name')->label('Departamento')->placeholder('—'),
                                Components\TextEntry::make('channel')->label('Canal')
                                    ->formatStateUsing(fn (?string $state) => config("helpdesk.channels.{$state}", $state)),
                            ]),

                        Components\Section::make('Cliente')
                            ->columns(2)
                            ->schema([
                                Components\TextEntry::make('contact.name')->label('Contato')->placeholder('—'),
                                Components\TextEntry::make('company.name')->label('Empresa')->placeholder('—'),
                                Components\TextEntry::make('contact.email')->label('E-mail')->placeholder('—')->copyable(),
                                Components\TextEntry::make('contact.phone')->label('Telefone')->placeholder('—')->copyable(),
                            ]),

                        Components\Section::make('Datas')
                            ->columns(2)
                            ->collapsed()
                            ->schema([
                                Components\TextEntry::make('created_at')->label('Aberto')->dateTime('d/m/Y H:i'),
                                Components\TextEntry::make('assigned_at')->label('Atribuído')->dateTime('d/m/Y H:i')->placeholder('—'),
                                Components\TextEntry::make('first_response_at')->label('1ª resposta')->dateTime('d/m/Y H:i')->placeholder('—'),
                                Components\TextEntry::make('resolved_at')->label('Resolvido')->dateTime('d/m/Y H:i')->placeholder('—'),
                                Components\TextEntry::make('csat_score')->label('CSAT')->placeholder('—')
                                    ->formatStateUsing(fn (?int $state) => $state ? str_repeat('★', $state).str_repeat('☆', 5 - $state) : null),
                                Components\TextEntry::make('jira_issue_key')->label('Jira')->placeholder('—'),
                            ]),
                    ]),
            ]);
    }

    /** @return array<int, Components\TextEntry> */
    private function customFieldEntries(Ticket $record): array
    {
        $service = app(CustomFieldService::class);
        $values = $service->valuesFor($record);

        return $service->fieldsForType($record->ticket_type_id)
            ->map(function ($field) use ($values) {
                $value = $values[$field->key] ?? null;
                $options = $field->optionMap();

                $display = match (true) {
                    is_array($value) => implode(', ', array_map(fn ($v) => $options[$v] ?? $v, $value)),
                    is_bool($value) => $value ? 'Sim' : 'Não',
                    $value !== null && isset($options[(string) $value]) => $options[(string) $value],
                    default => $value,
                };

                return Components\TextEntry::make('cf_'.$field->key)
                    ->label($field->label)
                    ->state($display)
                    ->placeholder('—');
            })
            ->all();
    }

    protected function getHeaderActions(): array
    {
        // Responder/nota interna não são mais modais: o editor vive na aba "Conversa"
        return [
            TicketResource::assumeAction(Actions\Action::class),
            TicketResource::moveAction(Actions\Action::class),
            TicketResource::resolveAction(Actions\Action::class),

            Actions\ActionGroup::make([
                Actions\Action::make('assign')
                    ->label('Atribuir')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn () => Gate::allows('assign', $this->record))
                    ->fillForm(fn () => ['assignee_id' => $this->record->assignee_id])
                    ->form([
                        Forms\Components\Select::make('assignee_id')->label('Responsável')->searchable()
                            ->options(fn () => TicketResource::assigneeOptions($this->record->team_id)),
                    ])
                    ->action(function (array $data) {
                        $user = $data['assignee_id'] ? User::query()->find($data['assignee_id']) : null;
                        app(TicketService::class)->assign($this->record, $user, auth()->user());
                        $this->refreshRecord();
                        Notification::make()->title($user ? 'Atribuído a '.$user->name : 'Responsável removido')->success()->send();
                    }),
                Actions\Action::make('autoAssign')
                    ->label('Distribuir (round-robin)')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->visible(fn () => $this->record->team_id && Gate::allows('assign', $this->record))
                    ->action(function () {
                        $agent = app(TicketService::class)->autoAssign($this->record, null, auth()->user());
                        $this->refreshRecord();
                        Notification::make()
                            ->title($agent ? 'Distribuído para '.$agent->name : 'Nenhum agente disponível na equipe')
                            ->status($agent ? 'success' : 'warning')
                            ->send();
                    }),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->using(fn () => app(TicketService::class)->delete($this->record, auth()->user())),
            ]),
        ];
    }

    /** Disparado pelo TicketConversation: 1ª resposta muda SLA/datas do painel lateral. */
    #[On('ticket-conversation-updated')]
    public function refreshRecord(): void
    {
        $this->record->refresh();
        $this->record->unsetRelations();
    }
}
