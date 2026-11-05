<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TicketPriorityResource\Pages;
use App\Models\TicketPriority;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TicketPriorityResource extends Resource
{
    protected static ?string $model = TicketPriority::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Configuração';

    protected static ?string $modelLabel = 'prioridade';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(60)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get, ?string $state) => blank($get('slug')) && $set('slug', Str::slug((string) $state))),
            Forms\Components\TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true),
            Forms\Components\ColorPicker::make('color')->label('Cor')->required()->default('#8dbea3'),
            Forms\Components\TextInput::make('level')->label('Nível (maior = mais crítico)')->numeric()->minValue(1)->maxValue(255)->required(),
            Forms\Components\TextInput::make('weight')->label('Peso na distribuição')->numeric()->minValue(1)->maxValue(1000)->required()->default(1)
                ->helperText('Quanto este ticket "pesa" na carga do agente.'),
            Forms\Components\Toggle::make('is_default')->label('Padrão')->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('level', 'desc')
            ->reorderable('level')
            ->columns([
                Tables\Columns\ColorColumn::make('color')->label(''),
                Tables\Columns\TextColumn::make('name')->label('Prioridade')->weight('bold'),
                Tables\Columns\TextColumn::make('level')->label('Nível')->sortable(),
                Tables\Columns\TextColumn::make('weight')->label('Peso'),
                Tables\Columns\IconColumn::make('is_default')->label('Padrão')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (TicketPriority $record) => $record->tickets()->doesntExist()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageTicketPriorities::route('/'),
        ];
    }
}
