<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DepartmentResource\Pages;
use App\Models\Department;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class DepartmentResource extends Resource
{
    protected static ?string $model = Department::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Acessos';

    protected static ?string $modelLabel = 'departamento';

    protected static ?int $navigationSort = 4;

    public static function formSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get, ?string $state) => blank($get('slug')) && $set('slug', Str::slug((string) $state))),
            Forms\Components\TextInput::make('slug')->required()->alphaDash()->unique('departments', 'slug', ignoreRecord: true),
            Forms\Components\Select::make('parent_id')->label('Departamento superior')
                ->options(fn (?Department $record) => Department::query()->when($record, fn ($query) => $query->whereKeyNot($record->id))->pluck('name', 'id')),
            Forms\Components\TextInput::make('email')->label('E-mail do setor')->email(),
            Forms\Components\Textarea::make('description')->label('Descrição')->rows(2)->columnSpanFull(),
            Forms\Components\Toggle::make('is_active')->label('Ativo')->default(true),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema(static::formSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent')->withCount(['teams', 'boards']))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Departamento')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('parent.name')->label('Superior')->placeholder('—'),
                Tables\Columns\TextColumn::make('teams_count')->label('Equipes'),
                Tables\Columns\TextColumn::make('boards_count')->label('Quadros'),
                Tables\Columns\IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageDepartments::route('/'),
        ];
    }
}
