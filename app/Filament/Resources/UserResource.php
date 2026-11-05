<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Acessos';

    protected static ?string $modelLabel = 'usuário';

    protected static ?string $pluralModelLabel = 'usuários e agentes';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Section::make('Identificação')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state) {
                                if (blank($get('username')) && $state) {
                                    $set('username', Str::of($state)->ascii()->lower()->trim()->replaceMatches('/\s+/', '.')
                                        ->replaceMatches('/[^a-z0-9._-]/', '')->substr(0, 64)->toString());
                                }
                            }),
                        Forms\Components\TextInput::make('username')->label('Username (@menções)')
                            ->prefix('@')
                            ->maxLength(65)
                            ->autocomplete('off')
                            ->helperText('Letras minúsculas, números, ponto, hífen ou sublinhado.')
                            // Normaliza ao sair do campo: o usuário vê o valor que será salvo
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('username', User::normalizeUsername($state)))
                            ->dehydrateStateUsing(fn (?string $state) => User::normalizeUsername($state))
                            // Valida o valor normalizado (espaços, "@" e maiúsculas não geram erro)
                            ->rule(fn (?User $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                                $username = User::normalizeUsername($value);

                                if ($username === null) {
                                    return;
                                }
                                if (! preg_match(User::USERNAME_PATTERN, $username)) {
                                    $fail('Use de 2 a 64 caracteres: letras, números, ponto, hífen ou sublinhado (sem espaços nem acentos), começando por letra ou número.');

                                    return;
                                }
                                if (User::query()->where('username', $username)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                                    $fail("O username @{$username} já está em uso.");
                                }
                            }),
                        Forms\Components\TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('phone')->label('Telefone')->tel()->maxLength(32),
                        Forms\Components\TextInput::make('password')->label('Senha')
                            ->password()->revealable()
                            ->minLength(12)
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText(fn (string $operation) => $operation === 'edit' ? 'Deixe em branco para manter a atual.' : null),
                        Forms\Components\Select::make('timezone')->label('Fuso horário')
                            ->options(fn () => collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz]))
                            ->searchable()
                            ->placeholder(config('app.timezone')),
                    ]),

                Forms\Components\Section::make('Acesso')
                    ->columnSpan(1)
                    ->schema([
                        Forms\Components\Toggle::make('is_active')->label('Ativo')->default(true)
                            ->disabled(fn (?User $record) => $record?->is(auth()->user())),
                        Forms\Components\Toggle::make('is_agent')->label('Agente (acessa o painel)')->default(true),
                        Forms\Components\Select::make('roles')->label('Perfis de acesso')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->disabled(fn () => ! auth()->user()->can('org.manage_roles'))
                            ->dehydrated(fn () => auth()->user()->can('org.manage_roles')),
                        Forms\Components\Select::make('teams')->label('Equipes')
                            ->relationship('teams', 'name')
                            ->multiple()
                            ->preload()
                            ->helperText('Capacidade e liderança são ajustadas em Equipes › Membros.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['roles', 'teams']))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable()->sortable()->weight('bold')
                    ->description(fn (User $record) => $record->username ? '@'.$record->username : null),
                Tables\Columns\TextColumn::make('email')->label('E-mail')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('roles.name')->label('Perfis')->badge(),
                Tables\Columns\TextColumn::make('teams.name')->label('Equipes')->badge()->color('gray'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Ativo')
                    ->disabled(fn (User $record) => $record->is(auth()->user())),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Visto')->since()->placeholder('nunca'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')->label('Perfil')->relationship('roles', 'name')->multiple(),
                Tables\Filters\SelectFilter::make('teams')->label('Equipe')->relationship('teams', 'name')->multiple(),
                Tables\Filters\TernaryFilter::make('is_active')->label('Ativo'),
                Tables\Filters\TernaryFilter::make('is_agent')->label('Agente'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->hidden(fn (User $record) => $record->is(auth()->user())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
