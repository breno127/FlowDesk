<?php

namespace App\Filament\Resources;

use App\Filament\Pages\KanbanBoard;
use App\Filament\Resources\BoardResource\Pages;
use App\Models\Board;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class BoardResource extends Resource
{
    protected static ?string $model = Board::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'Configuração';

    protected static ?string $modelLabel = 'quadro Kanban';

    protected static ?string $pluralModelLabel = 'quadros Kanban';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Quadro')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Forms\Set $set, ?string $state, string $operation) => $operation === 'create' && $set('slug', Str::slug((string) $state))),
                    Forms\Components\TextInput::make('slug')->required()->alphaDash()->normalizeUsing(fn (?string $state) => \App\Support\InputSanitizer::slug($state))->unique(ignoreRecord: true),
                    Forms\Components\Select::make('department_id')->label('Departamento dono')->relationship('department', 'name')->preload()
                        ->placeholder('Global'),
                    Forms\Components\Select::make('team_id')->label('Equipe dona')->relationship('team', 'name')->preload()
                        ->placeholder('Todas do departamento'),
                    Forms\Components\Toggle::make('is_default')->label('Quadro padrão (tickets sem quadro)')->inline(false),
                    Forms\Components\Toggle::make('is_active')->label('Ativo')->default(true)->inline(false),
                    Forms\Components\Textarea::make('description')->label('Descrição')->rows(2)->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Colunas (estados)')
                ->description('Nome livre; a categoria mantém as métricas consistentes e o comportamento define o SLA.')
                ->schema([KanbanBoard::columnsRepeater()]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['department', 'team'])->withCount(['columns', 'tickets as open_tickets_count' => fn ($t) => $t->open()]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Quadro')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('department.name')->label('Departamento')->placeholder('Global'),
                Tables\Columns\TextColumn::make('team.name')->label('Equipe')->placeholder('—'),
                Tables\Columns\TextColumn::make('columns_count')->label('Colunas'),
                Tables\Columns\TextColumn::make('open_tickets_count')->label('Abertos'),
                Tables\Columns\IconColumn::make('is_default')->label('Padrão')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('open')->label('Abrir')->icon('heroicon-o-view-columns')
                    ->url(fn (Board $record) => KanbanBoard::getUrl(['board' => $record->id])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Board $record) => $record->tickets()->doesntExist()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBoards::route('/'),
            'create' => Pages\CreateBoard::route('/create'),
            'edit' => Pages\EditBoard::route('/{record}/edit'),
        ];
    }
}
