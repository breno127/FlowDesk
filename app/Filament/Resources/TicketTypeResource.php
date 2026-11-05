<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TicketTypeResource\Pages;
use App\Models\CustomField;
use App\Models\TicketType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Tipos de ticket e o formulário dinâmico (campos EAV) de cada um.
 */
class TicketTypeResource extends Resource
{
    protected static ?string $model = TicketType::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Configuração';

    protected static ?string $modelLabel = 'tipo de ticket';

    protected static ?string $pluralModelLabel = 'tipos de ticket';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tipo')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Forms\Set $set, ?string $state, string $operation) => $operation === 'create' && $set('slug', Str::slug((string) $state))),
                    Forms\Components\TextInput::make('slug')->required()->alphaDash()->normalizeUsing(fn (?string $state) => \App\Support\InputSanitizer::slug($state))->unique(ignoreRecord: true),
                    Forms\Components\Select::make('department_id')->label('Departamento responsável')->relationship('department', 'name')->preload(),
                    Forms\Components\ColorPicker::make('color')->label('Cor'),
                    Forms\Components\TextInput::make('icon')->label('Ícone (heroicon)')->placeholder('heroicon-o-computer-desktop'),
                    Forms\Components\Toggle::make('is_active')->label('Ativo')->default(true)->inline(false),
                    Forms\Components\Textarea::make('description')->label('Descrição')->rows(2)->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Formulário dinâmico')
                ->description('Campos exibidos quando o ticket for deste tipo. Arraste para ordenar.')
                ->schema([
                    Forms\Components\Repeater::make('fieldAssignments')
                        ->relationship()
                        ->hiddenLabel()
                        ->orderColumn('sort')
                        ->reorderableWithDragAndDrop()
                        ->columns(4)
                        ->addActionLabel('Adicionar campo')
                        ->itemLabel(fn (array $state) => CustomField::query()->find($state['custom_field_id'] ?? null)?->label)
                        ->schema([
                            Forms\Components\Select::make('custom_field_id')->label('Campo')
                                ->options(fn () => CustomField::query()->where('is_active', true)->orderBy('label')->get()
                                    ->mapWithKeys(fn (CustomField $f) => [$f->id => "{$f->label} ({$f->type->getLabel()})"]))
                                ->required()
                                ->distinct()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->searchable()
                                ->live()
                                ->createOptionForm(CustomFieldResource::formSchema())
                                ->createOptionUsing(fn (array $data) => CustomField::query()->create($data)->id),
                            Forms\Components\Toggle::make('is_required')->label('Obrigatório')->inline(false),
                            Forms\Components\Toggle::make('visible_to_customer')->label('Visível ao cliente')->default(true)->inline(false),
                            Forms\Components\Select::make('column_span')->label('Largura')->options([1 => 'Meia', 2 => 'Inteira'])->default(1)->required(),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('department')->withCount(['fieldAssignments', 'tickets']))
            ->columns([
                Tables\Columns\ColorColumn::make('color')->label(''),
                Tables\Columns\TextColumn::make('name')->label('Tipo')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('department.name')->label('Departamento')->placeholder('—'),
                Tables\Columns\TextColumn::make('field_assignments_count')->label('Campos'),
                Tables\Columns\TextColumn::make('tickets_count')->label('Tickets'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Ativo'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (TicketType $record) => $record->tickets()->doesntExist()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTicketTypes::route('/'),
            'create' => Pages\CreateTicketType::route('/create'),
            'edit' => Pages\EditTicketType::route('/{record}/edit'),
        ];
    }
}
