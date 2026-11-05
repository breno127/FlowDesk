<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BusinessHourScheduleResource\Pages;
use App\Models\BusinessHourSchedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Horário de operação e feriados — o SLA só consome tempo dentro destas janelas.
 */
class BusinessHourScheduleResource extends Resource
{
    protected static ?string $model = BusinessHourSchedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'SLA';

    protected static ?string $modelLabel = 'horário de operação';

    protected static ?string $pluralModelLabel = 'horários e feriados';

    protected static ?int $navigationSort = 2;

    public const WEEKDAYS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Calendário')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(120),
                    Forms\Components\Select::make('timezone')->label('Fuso horário')
                        ->options(fn () => collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz]))
                        ->searchable()
                        ->required()
                        ->default(config('app.timezone')),
                    Forms\Components\Toggle::make('is_default')->label('Padrão')->inline(false),
                ]),

            Forms\Components\Grid::make(['default' => 1, 'xl' => 2])->schema([
                Forms\Components\Section::make('Expediente')
                    ->description('Vários intervalos por dia são permitidos (ex.: 08–12 e 13–18).')
                    ->schema([
                        Forms\Components\Repeater::make('intervals')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(3)
                            ->addActionLabel('Adicionar intervalo')
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state) => (self::WEEKDAYS[$state['weekday'] ?? 0] ?? '').' '.substr((string) ($state['starts_at'] ?? ''), 0, 5).'–'.substr((string) ($state['ends_at'] ?? ''), 0, 5))
                            ->collapsible()
                            ->schema([
                                Forms\Components\Select::make('weekday')->label('Dia')->options(self::WEEKDAYS)->required(),
                                Forms\Components\TimePicker::make('starts_at')->label('Início')->seconds(false)->required(),
                                Forms\Components\TimePicker::make('ends_at')->label('Fim')->seconds(false)->required()
                                    ->helperText('00:00 = meia-noite')
                                    ->rule(fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                        $end = substr((string) $value, 0, 5);
                                        if ($end !== '00:00' && $end <= substr((string) $get('starts_at'), 0, 5)) {
                                            $fail('O fim deve ser posterior ao início.');
                                        }
                                    }),
                            ]),
                    ]),

                Forms\Components\Section::make('Feriados')
                    ->schema([
                        Forms\Components\Repeater::make('holidays')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(3)
                            ->addActionLabel('Adicionar feriado')
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state) => $state['name'] ?? null)
                            ->collapsible()
                            ->schema([
                                Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(120),
                                Forms\Components\DatePicker::make('date')->label('Data')->required()->native(false),
                                Forms\Components\Toggle::make('is_recurring')->label('Repete todo ano')->inline(false),
                            ]),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['intervals', 'holidays']))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Calendário')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('timezone')->label('Fuso'),
                Tables\Columns\TextColumn::make('intervals_count')->label('Intervalos'),
                Tables\Columns\TextColumn::make('holidays_count')->label('Feriados'),
                Tables\Columns\IconColumn::make('is_default')->label('Padrão')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBusinessHourSchedules::route('/'),
            'create' => Pages\CreateBusinessHourSchedule::route('/create'),
            'edit' => Pages\EditBusinessHourSchedule::route('/{record}/edit'),
        ];
    }
}
