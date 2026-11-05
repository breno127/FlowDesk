<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientCompanyResource\Pages;
use App\Models\ClientCompany;
use App\Support\InputSanitizer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ClientCompanyResource extends Resource
{
    protected static ?string $model = ClientCompany::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Clientes';

    protected static ?string $modelLabel = 'empresa';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')->label('Razão social / nome')->required()->maxLength(255),
            Forms\Components\TextInput::make('document')->label('CNPJ/CPF')->maxLength(32)->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('domain')->label('Domínio de e-mail')->placeholder('empresa.com.br')
                ->helperText('Contatos com e-mail deste domínio são vinculados à empresa. Pode colar um e-mail ou site: o domínio é extraído.')
                // Aceita "ana@empresa.com.br" ou "https://www.empresa.com.br" e guarda "empresa.com.br"
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('domain', InputSanitizer::domain($state)))
                ->normalizeUsing(fn (?string $state) => InputSanitizer::domain($state))
                ->regex('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/')
                ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                    if ($value && in_array($value, config('helpdesk.public_email_domains', []), true)) {
                        $fail("{$value} é um provedor de e-mail público e não identifica a empresa. Use o domínio corporativo (ex.: empresa.com.br) ou deixe em branco.");
                    }
                })
                ->validationMessages(['regex' => 'Informe um domínio válido, como empresa.com.br.']),
            Forms\Components\TextInput::make('tier')->label('Segmento')->maxLength(32)->datalist(fn () => ClientCompany::query()->whereNotNull('tier')->distinct()->pluck('tier')->all()),
            Forms\Components\Textarea::make('notes')->label('Observações')->rows(3)->columnSpanFull(),
            Forms\Components\Toggle::make('is_active')->label('Ativa')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['contacts', 'tickets as open_tickets_count' => fn ($t) => $t->open()]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Empresa')->searchable()->weight('bold')
                    ->description(fn (ClientCompany $record) => $record->document),
                Tables\Columns\TextColumn::make('domain')->label('Domínio')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('tier')->label('Segmento')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('contacts_count')->label('Contatos'),
                Tables\Columns\TextColumn::make('open_tickets_count')->label('Tickets abertos'),
                Tables\Columns\IconColumn::make('is_active')->label('Ativa')->boolean(),
            ])
            ->filters([
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
            'index' => Pages\ManageClientCompanies::route('/'),
        ];
    }
}
