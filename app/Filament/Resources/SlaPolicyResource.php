<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SlaPolicyResource\Pages;
use App\Models\SlaPolicy;
use App\Models\TicketPriority;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Múltiplas políticas de SLA. Critérios vazios = "qualquer"; a mais específica vence
 * (cliente > tipo > departamento), desempate por precedência.
 */
class SlaPolicyResource extends Resource
{
    protected static ?string $model = SlaPolicy::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'SLA';

    protected static ?string $modelLabel = 'política de SLA';

    protected static ?string $pluralModelLabel = 'políticas de SLA';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Política')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(120)->columnSpan(2),
                    Forms\Components\Toggle::make('is_active')->label('Ativa')->default(true)->inline(false),
                    Forms\Components\Select::make('business_hour_schedule_id')->label('Calendário de expediente')
                        ->relationship('schedule', 'name')
                        ->preload()
                        ->placeholder('24x7 (tempo corrido)')
                        ->helperText('Fora do expediente e em feriados o SLA não conta.'),
                    Forms\Components\TextInput::make('precedence')->label('Precedência')->numeric()->default(100)->required()
                        ->helperText('Desempate entre políticas igualmente específicas: menor vence.'),
                    Forms\Components\Textarea::make('description')->label('Descrição')->rows(1),
                ]),

            Forms\Components\Section::make('Aplicar quando')
                ->description('Deixe em branco para "qualquer".')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('client_company_id')->label('Cliente')->relationship('company', 'name')->searchable()->preload(),
                    Forms\Components\Select::make('department_id')->label('Departamento')->relationship('department', 'name')->preload(),
                    Forms\Components\Select::make('ticket_type_id')->label('Tipo de ticket')->relationship('ticketType', 'name')->preload(),
                ]),

            Forms\Components\Section::make('Metas por prioridade')
                ->schema([
                    Forms\Components\Repeater::make('targets')
                        ->relationship()
                        ->hiddenLabel()
                        ->columns(4)
                        ->addActionLabel('Adicionar meta')
                        ->itemLabel(fn (array $state) => TicketPriority::query()->find($state['ticket_priority_id'] ?? null)?->name)
                        ->schema([
                            Forms\Components\Select::make('ticket_priority_id')->label('Prioridade')
                                ->options(fn () => TicketPriority::query()->orderByDesc('level')->pluck('name', 'id'))
                                ->required()
                                ->distinct()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->live(),
                            Forms\Components\TextInput::make('first_response_minutes')->label('1ª resposta (min)')->numeric()->minValue(1)
                                ->placeholder('Sem meta'),
                            Forms\Components\TextInput::make('resolution_minutes')->label('Solução (min)')->numeric()->minValue(1)
                                ->placeholder('Sem meta'),
                            Forms\Components\TextInput::make('warning_percent')->label('Alerta em (%)')->numeric()->minValue(1)->maxValue(99)
                                ->placeholder((string) config('helpdesk.sla.default_warning_percent')),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['schedule', 'company', 'department', 'ticketType'])->withCount('targets'))
            ->defaultSort('precedence')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Política')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('criteria')->label('Aplica-se a')
                    ->state(fn (SlaPolicy $record) => collect([
                        $record->company ? 'Cliente: '.$record->company->name : null,
                        $record->ticketType ? 'Tipo: '.$record->ticketType->name : null,
                        $record->department ? 'Depto: '.$record->department->name : null,
                    ])->filter()->implode(' · ') ?: 'Todos'),
                Tables\Columns\TextColumn::make('schedule.name')->label('Expediente')->placeholder('24x7'),
                Tables\Columns\TextColumn::make('targets_count')->label('Metas'),
                Tables\Columns\TextColumn::make('precedence')->label('Precedência')->sortable(),
                Tables\Columns\ToggleColumn::make('is_active')->label('Ativa'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ReplicateAction::make()->label('Duplicar')
                    ->excludeAttributes(['targets_count'])
                    ->after(fn (SlaPolicy $replica, SlaPolicy $record) => $replica->targets()->createMany(
                        $record->targets->map->only(['ticket_priority_id', 'first_response_minutes', 'resolution_minutes', 'warning_percent'])->all()
                    )),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSlaPolicies::route('/'),
            'create' => Pages\CreateSlaPolicy::route('/create'),
            'edit' => Pages\EditSlaPolicy::route('/{record}/edit'),
        ];
    }
}
