<?php

namespace App\Filament\Resources;

use App\Contracts\AutomationAction;
use App\Filament\Resources\AutomationRuleResource\Pages;
use App\Models\AutomationRule;
use App\Models\BoardColumn;
use App\Models\ClientCompany;
use App\Models\CustomField;
use App\Models\Department;
use App\Models\Team;
use App\Models\TicketPriority;
use App\Models\TicketType;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Construtor visual de regras: QUANDO (evento) + SE (condições) + ENTÃO (ações).
 * Eventos, campos, operadores e ações vêm de config/helpdesk.php.
 */
class AutomationRuleResource extends Resource
{
    protected static ?string $model = AutomationRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = 'Configuração';

    protected static ?string $modelLabel = 'regra de automação';

    protected static ?string $pluralModelLabel = 'automações';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Regra')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(150)->columnSpan(2),
                    Forms\Components\Toggle::make('is_active')->label('Ativa')->default(true)->inline(false),
                    Forms\Components\Textarea::make('description')->label('Descrição')->rows(2)->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Quando')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('event')->label('Evento')->options(fn () => config('helpdesk.automation.events'))->required()->live(),
                    Forms\Components\Select::make('board_id')->label('Somente no quadro')->relationship('board', 'name')->placeholder('Todos'),
                    Forms\Components\Select::make('department_id')->label('Somente no departamento')->relationship('department', 'name')->placeholder('Todos'),
                ]),

            Forms\Components\Section::make('Se')
                ->schema([
                    Forms\Components\Radio::make('match')->label('Combinar condições')
                        ->options(['all' => 'Todas (E)', 'any' => 'Qualquer uma (OU)'])
                        ->default('all')->inline()->required(),
                    Forms\Components\Repeater::make('conditions')
                        ->hiddenLabel()
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel('Adicionar condição')
                        ->schema([
                            Forms\Components\Select::make('field')->label('Campo')->options(fn () => static::conditionFieldOptions())
                                ->required()->live()->searchable(),
                            Forms\Components\Select::make('operator')->label('Operador')->options(fn () => config('helpdesk.automation.operators'))
                                ->required()->live(),
                            Forms\Components\Select::make('value')->label('Valor')
                                ->options(fn (Get $get) => static::valueOptions($get('field')))
                                ->multiple(fn (Get $get) => in_array($get('operator'), ['in', 'not_in'], true))
                                ->searchable()
                                ->visible(fn (Get $get) => static::valueOptions($get('field')) !== null && static::operatorNeedsValue($get('operator'))),
                            Forms\Components\TextInput::make('value')->label('Valor')
                                ->visible(fn (Get $get) => static::valueOptions($get('field')) === null && static::operatorNeedsValue($get('operator'))),
                        ]),
                ]),

            Forms\Components\Section::make('Então')
                ->schema([
                    Forms\Components\Builder::make('actions')
                        ->hiddenLabel()
                        ->required()
                        ->minItems(1)
                        ->addActionLabel('Adicionar ação')
                        ->blockNumbers(false)
                        ->collapsible()
                        ->blocks(static::actionBlocks()),
                    Forms\Components\Toggle::make('stop_processing')->label('Parar de avaliar as próximas regras deste evento'),
                    Forms\Components\TextInput::make('sort')->label('Ordem de execução')->numeric()->default(0),
                ]),
        ]);
    }

    /** @return array<int, Forms\Components\Builder\Block> */
    public static function actionBlocks(): array
    {
        return collect(config('helpdesk.automation.actions'))
            ->filter(fn (string $class) => is_subclass_of($class, AutomationAction::class))
            ->map(fn (string $class, string $type) => Forms\Components\Builder\Block::make($type)
                ->label($class::label())
                ->schema($class::schema() ?: [Forms\Components\Placeholder::make('no_params')->hiddenLabel()->content('Sem parâmetros.')]))
            ->values()
            ->all();
    }

    public static function conditionFieldOptions(): array
    {
        $base = collect(config('helpdesk.automation.condition_fields'))->map(fn (array $def) => $def[0])->all();
        $custom = CustomField::query()->where('is_active', true)->orderBy('label')->get()
            ->mapWithKeys(fn (CustomField $f) => ['custom.'.$f->key => 'Campo: '.$f->label])->all();

        return $base + $custom;
    }

    /** Opções do valor conforme o tipo do campo; null = texto livre. */
    public static function valueOptions(?string $field): ?array
    {
        if (! $field) {
            return null;
        }

        if (str_starts_with($field, 'custom.')) {
            $cf = CustomField::query()->where('key', substr($field, 7))->first();

            return $cf && $cf->type->hasOptions() ? $cf->optionMap() : null;
        }

        $type = config("helpdesk.automation.condition_fields.{$field}.1");

        return match ($type) {
            'column' => BoardColumn::query()->with('board:id,name')->orderBy('board_id')->orderBy('position')->get()
                ->mapWithKeys(fn (BoardColumn $c) => [(string) $c->id => $c->board->name.' › '.$c->name])->all(),
            'priority' => TicketPriority::query()->orderBy('level')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all(),
            'ticket_type' => TicketType::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all(),
            'department' => Department::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all(),
            'team' => Team::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all(),
            'user' => User::query()->agents()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all(),
            'company' => ClientCompany::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all(),
            'channel' => config('helpdesk.channels'),
            default => null,
        };
    }

    private static function operatorNeedsValue(?string $operator): bool
    {
        return ! in_array($operator, ['is_empty', 'is_not_empty', 'changed'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->modifyQueryUsing(fn ($query) => $query->with(['board', 'department'])
                ->withCount(['runs as failed_runs_count' => fn ($r) => $r->where('status', 'failed')->where('created_at', '>=', now()->subDay())]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Regra')->searchable()->weight('bold')
                    ->description(fn (AutomationRule $record) => collect($record->actions)->map(
                        fn ($a) => ($class = config('helpdesk.automation.actions.'.($a['type'] ?? ''))) ? $class::label() : ($a['type'] ?? '?')
                    )->implode(' → ')),
                Tables\Columns\TextColumn::make('event')->label('Evento')->badge()
                    ->formatStateUsing(fn (string $state) => config("helpdesk.automation.events.{$state}", $state)),
                Tables\Columns\TextColumn::make('board.name')->label('Quadro')->placeholder('Todos'),
                Tables\Columns\TextColumn::make('run_count')->label('Execuções')->numeric(),
                Tables\Columns\TextColumn::make('failed_runs_count')->label('Falhas 24h')->badge()
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('last_run_at')->label('Última')->since()->placeholder('nunca'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Ativa'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')->label('Evento')->options(fn () => config('helpdesk.automation.events')),
                Tables\Filters\TernaryFilter::make('is_active')->label('Ativa'),
            ])
            ->actions([
                Tables\Actions\Action::make('runs')
                    ->label('Log')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->modalHeading(fn (AutomationRule $record) => 'Últimas execuções — '.$record->name)
                    ->modalSubmitAction(false)
                    ->modalContent(fn (AutomationRule $record) => view('filament.automation.runs', [
                        'runs' => $record->runs()->with('ticket:id,number')->latest('created_at')->limit(30)->get(),
                    ])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\ReplicateAction::make()->label('Duplicar')
                    ->excludeAttributes(['run_count', 'last_run_at', 'failed_runs_count'])
                    ->beforeReplicaSaved(fn (AutomationRule $replica) => $replica->fill(['name' => $replica->name.' (cópia)', 'is_active' => false])),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutomationRules::route('/'),
            'create' => Pages\CreateAutomationRule::route('/create'),
            'edit' => Pages\EditAutomationRule::route('/{record}/edit'),
        ];
    }
}
