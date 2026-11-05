<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Spatie\Permission\Models\Role;

/**
 * Perfis de acesso com matriz de permissões granular (catálogo em config/helpdesk.php).
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Acessos';

    protected static ?string $modelLabel = 'perfil de acesso';

    protected static ?string $pluralModelLabel = 'perfis de acesso';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Perfil')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nome (slug)')
                        ->required()
                        ->maxLength(100)
                        ->alphaDash()->normalizeUsing(fn (?string $state) => \App\Support\InputSanitizer::slug($state))
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('guard_name', config('auth.defaults.guard')))
                        ->disabled(fn (?Role $record) => static::isSuperAdmin($record))
                        ->helperText(fn (?Role $record) => static::isSuperAdmin($record) ? 'Perfil super-admin: acesso irrestrito, não editável.' : null),
                    Forms\Components\Placeholder::make('users_count')
                        ->label('Usuários com este perfil')
                        ->content(fn (?Role $record) => $record ? $record->users()->count() : 0),
                ]),

            Forms\Components\Section::make('Permissões')
                ->description('Ligue ou desligue cada capacidade. As permissões são aplicadas imediatamente após salvar.')
                ->visible(fn (?Role $record) => ! static::isSuperAdmin($record))
                ->schema([
                    Forms\Components\Grid::make(['default' => 1, 'lg' => 2])
                        ->schema(static::permissionFieldsets()),
                ]),
        ]);
    }

    /** Um CheckboxList por grupo do catálogo, com "selecionar todos". */
    public static function permissionFieldsets(): array
    {
        return collect(config('helpdesk.rbac.permissions'))
            ->map(fn (array $permissions, string $group) => Forms\Components\Fieldset::make($group)
                ->schema([
                    Forms\Components\CheckboxList::make(static::groupField($group))
                        ->hiddenLabel()
                        ->options($permissions)
                        ->descriptions(collect($permissions)->mapWithKeys(fn ($label, $key) => [$key => $key])->all())
                        ->bulkToggleable()
                        ->columns(1)
                        ->gridDirection('row'),
                ]))
            ->values()
            ->all();
    }

    public static function groupField(string $group): string
    {
        return 'permissions_'.Str::slug($group, '_');
    }

    /** @return list<string> */
    public static function extractPermissions(array $data): array
    {
        return collect(config('helpdesk.rbac.permissions'))
            ->keys()
            ->flatMap(fn (string $group) => $data[static::groupField($group)] ?? [])
            ->unique()
            ->values()
            ->all();
    }

    public static function fillPermissions(Role $role): array
    {
        $current = $role->permissions->pluck('name')->all();

        return collect(config('helpdesk.rbac.permissions'))
            ->mapWithKeys(fn (array $permissions, string $group) => [
                static::groupField($group) => array_values(array_intersect(array_keys($permissions), $current)),
            ])
            ->all();
    }

    public static function isSuperAdmin(?Role $role): bool
    {
        return $role?->name === config('helpdesk.rbac.super_admin_role');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('guard_name', config('auth.defaults.guard'))->withCount(['permissions', 'users']))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Perfil')->badge()->searchable()->sortable()
                    ->color(fn (Role $record) => static::isSuperAdmin($record) ? 'danger' : 'primary'),
                Tables\Columns\TextColumn::make('permissions_count')->label('Permissões')
                    ->formatStateUsing(fn (Role $record, $state) => static::isSuperAdmin($record) ? 'todas' : $state),
                Tables\Columns\TextColumn::make('users_count')->label('Usuários'),
                Tables\Columns\TextColumn::make('updated_at')->label('Atualizado')->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ReplicateAction::make()
                    ->label('Duplicar')
                    ->visible(fn (Role $record) => ! static::isSuperAdmin($record) && auth()->user()->can('create', Role::class))
                    ->form([Forms\Components\TextInput::make('name')->label('Nome do novo perfil')->required()->alphaDash()->normalizeUsing(fn (?string $state) => \App\Support\InputSanitizer::slug($state))->unique('roles', 'name')])
                    ->using(function (Role $record, array $data) {
                        $copy = Role::create(['name' => $data['name'], 'guard_name' => $record->guard_name]);
                        $copy->syncPermissions($record->permissions);

                        return $copy;
                    }),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Role $record) => ! static::isSuperAdmin($record))
                    ->before(function (Role $record, Tables\Actions\DeleteAction $action) {
                        if ($record->users()->exists()) {
                            \Filament\Notifications\Notification::make()->title('Perfil em uso')
                                ->body('Remova o perfil dos usuários antes de excluí-lo.')->danger()->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
