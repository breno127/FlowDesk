<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContactResource\Pages;
use App\Models\ClientCompany;
use App\Models\Contact;
use App\Support\InputSanitizer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Clientes';

    protected static ?string $modelLabel = 'contato';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** Reutilizado no "criar contato" inline do formulário de ticket. */
    public static function formSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(255),
            Forms\Components\TextInput::make('email')->label('E-mail')->email()->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state) {
                    if ($state && blank($get('client_company_id')) && str_contains($state, '@')) {
                        $set('client_company_id', ClientCompany::query()->where('domain', InputSanitizer::domain($state))->value('id'));
                    }
                }),
            Forms\Components\TextInput::make('phone')->label('Telefone')->tel()->placeholder('(11) 99999-9999')
                ->helperText('Qualquer formato; é salvo no padrão internacional (+55…), usado pelo WhatsApp.')
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('phone', InputSanitizer::phone($state)))
                ->normalizeUsing(fn (?string $state) => InputSanitizer::phone($state))
                ->regex('/^\+[1-9]\d{7,14}$/')
                ->validationMessages(['regex' => 'Telefone inválido. Exemplo: (11) 99999-9999 ou +55 11 99999-9999.']),
            Forms\Components\TextInput::make('whatsapp_id')->label('WhatsApp (wa_id)')->maxLength(32)
                ->unique('contacts', 'whatsapp_id', ignoreRecord: true),
            Forms\Components\Select::make('client_company_id')->label('Empresa')
                ->options(fn () => ClientCompany::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable(),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema(static::formSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('company')->withCount('tickets'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Contato')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('email')->label('E-mail')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')->label('Telefone')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('company.name')->label('Empresa')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('tickets_count')->label('Tickets'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('client_company_id')->label('Empresa')->relationship('company', 'name')->searchable(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageContacts::route('/'),
        ];
    }
}
