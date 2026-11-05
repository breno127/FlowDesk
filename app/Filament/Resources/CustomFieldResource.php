<?php

namespace App\Filament\Resources;

use App\Enums\CustomFieldType;
use App\Filament\Resources\CustomFieldResource\Pages;
use App\Models\CustomField;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CustomFieldResource extends Resource
{
    protected static ?string $model = CustomField::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Configuração';

    protected static ?string $modelLabel = 'campo customizado';

    protected static ?string $pluralModelLabel = 'campos customizados';

    protected static ?int $navigationSort = 3;

    public static function formSchema(): array
    {
        $isType = fn (array $types) => fn (Forms\Get $get) => in_array($get('type'), array_map(fn ($t) => $t->value, $types), true);

        return [
            Forms\Components\TextInput::make('label')->label('Rótulo')->required()->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get, ?string $state) => blank($get('key')) && $set('key', Str::slug((string) $state, '_'))),
            Forms\Components\TextInput::make('key')->label('Chave')->required()->maxLength(64)
                ->regex('/^[a-z][a-z0-9_]*$/')
                ->normalizeUsing(fn (?string $state) => \App\Support\InputSanitizer::slug($state, '_'))
                ->validationMessages(['regex' => 'A chave deve começar com letra e conter apenas letras minúsculas, números e _.'])
                ->unique('custom_fields', 'key', ignoreRecord: true)
                ->helperText('Usada em API, automações (custom.chave) e relatórios.'),
            Forms\Components\Select::make('type')->label('Tipo')->options(CustomFieldType::class)->required()->live()
                ->default(CustomFieldType::Text->value),
            Forms\Components\Toggle::make('is_active')->label('Ativo')->default(true)->inline(false),

            Forms\Components\Repeater::make('options')->label('Opções')
                ->visible($isType([CustomFieldType::Select, CustomFieldType::MultiSelect, CustomFieldType::Radio]))
                ->columns(2)
                ->defaultItems(2)
                ->reorderableWithDragAndDrop()
                ->addActionLabel('Adicionar opção')
                ->schema([
                    Forms\Components\TextInput::make('value')->label('Valor')->required()->distinct(),
                    Forms\Components\TextInput::make('label')->label('Rótulo')->required(),
                ])
                ->columnSpanFull(),

            Forms\Components\Fieldset::make('Validação')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('validation.max_length')->label('Tamanho máx.')->numeric()->minValue(1)
                        ->visible($isType([CustomFieldType::Text, CustomFieldType::Textarea])),
                    Forms\Components\TextInput::make('validation.min')->label('Mínimo')->numeric()
                        ->visible($isType([CustomFieldType::Number, CustomFieldType::Decimal])),
                    Forms\Components\TextInput::make('validation.max')->label('Máximo')->numeric()
                        ->visible($isType([CustomFieldType::Number, CustomFieldType::Decimal])),
                    Forms\Components\TextInput::make('validation.regex')->label('Regex')->placeholder('/^[A-Z]{2}-\d{4}$/')
                        ->visible($isType([CustomFieldType::Text]))
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if (filled($value) && @preg_match($value, '') === false) {
                                $fail('Expressão regular inválida.');
                            }
                        }),
                ])
                ->columnSpanFull(),

            Forms\Components\TextInput::make('placeholder')->label('Placeholder')->maxLength(255),
            Forms\Components\TextInput::make('help_text')->label('Texto de ajuda')->maxLength(255),
            Forms\Components\TextInput::make('default_value')->label('Valor padrão')->maxLength(255),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema(static::formSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['ticketTypes', 'values']))
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('Campo')->searchable()->weight('bold')
                    ->description(fn (CustomField $record) => $record->key),
                Tables\Columns\TextColumn::make('type')->label('Tipo')->badge(),
                Tables\Columns\TextColumn::make('ticket_types_count')->label('Usado em (tipos)'),
                Tables\Columns\TextColumn::make('values_count')->label('Valores gravados'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Ativo'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Tipo')->options(CustomFieldType::class),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Todos os valores deste campo em tickets serão removidos.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCustomFields::route('/'),
        ];
    }
}
