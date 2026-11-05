<?php

namespace App\Filament\Resources;

use App\Enums\RoutingStrategy;
use App\Filament\Resources\TeamResource\Pages;
use App\Filament\Resources\TeamResource\RelationManagers\MembersRelationManager;
use App\Models\Team;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Organograma de atendimento: equipes por departamento, nível (N1/N2/N3),
 * hierarquia, equipe de escalonamento e estratégia de distribuição.
 */
class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Acessos';

    protected static ?string $modelLabel = 'equipe';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Equipe')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get, ?string $state, string $operation) => $operation === 'create' && $set('slug', Str::slug((string) $state))),
                    Forms\Components\TextInput::make('slug')->required()->alphaDash()->normalizeUsing(fn (?string $state) => \App\Support\InputSanitizer::slug($state))->unique(ignoreRecord: true),
                    Forms\Components\Select::make('department_id')->label('Departamento')->relationship('department', 'name')->required()->preload()->searchable()
                        ->createOptionForm(DepartmentResource::formSchema()),
                    Forms\Components\TextInput::make('tier')->label('Nível (N)')->numeric()->minValue(1)->maxValue(9)->default(1)->required(),
                    Forms\Components\Select::make('parent_id')->label('Equipe superior')
                        ->relationship('parent', 'name', fn ($query, ?Team $record) => $query->when($record, fn ($query) => $query->whereKeyNot($record->id)))
                        ->searchable()->preload(),
                    Forms\Components\Select::make('escalation_team_id')->label('Escalonar para')
                        ->relationship('escalationTeam', 'name', fn ($query, ?Team $record) => $query->when($record, fn ($query) => $query->whereKeyNot($record->id)))
                        ->searchable()->preload()
                        ->helperText('Usado pela ação de automação "Encaminhar para equipe".'),
                    Forms\Components\Toggle::make('is_active')->label('Ativa')->default(true),
                ]),

            Forms\Components\Section::make('Distribuição automática')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('routing_strategy')->label('Estratégia')
                        ->options(RoutingStrategy::class)
                        ->default(RoutingStrategy::LoadBalanced->value)
                        ->required(),
                    Forms\Components\TextInput::make('max_weight_per_agent')->label('Teto de carga por agente')
                        ->numeric()->minValue(1)->placeholder('Sem teto')
                        ->helperText('Soma dos pesos das prioridades dos tickets abertos. Multiplicado pela capacidade do agente.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['department', 'escalationTeam'])->withCount(['members', 'tickets as open_tickets_count' => fn ($t) => $t->open()]))
            ->defaultSort('tier')
            ->columns([
                Tables\Columns\TextColumn::make('tier')->label('N')->badge()->formatStateUsing(fn ($state) => 'N'.$state)->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Equipe')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('department.name')->label('Departamento')->sortable(),
                Tables\Columns\TextColumn::make('routing_strategy')->label('Distribuição')->badge(),
                Tables\Columns\TextColumn::make('members_count')->label('Membros'),
                Tables\Columns\TextColumn::make('open_tickets_count')->label('Abertos'),
                Tables\Columns\TextColumn::make('escalationTeam.name')->label('Escala para')->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')->label('Ativa')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('department_id')->label('Departamento')->relationship('department', 'name'),
                Tables\Filters\SelectFilter::make('tier')->label('Nível')->options(fn () => Team::query()->distinct()->orderBy('tier')->pluck('tier', 'tier')->map(fn ($t) => 'N'.$t)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [MembersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeams::route('/'),
            'create' => Pages\CreateTeam::route('/create'),
            'edit' => Pages\EditTeam::route('/{record}/edit'),
        ];
    }
}
