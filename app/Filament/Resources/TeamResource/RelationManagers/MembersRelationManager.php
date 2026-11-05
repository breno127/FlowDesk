<?php

namespace App\Filament\Resources\TeamResource\RelationManagers;

use App\Models\User;
use App\Repositories\Contracts\AgentWorkloadRepositoryInterface;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Membros da equipe com capacidade, liderança, disponibilidade e carga atual.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Membros';

    protected static ?string $icon = 'heroicon-o-users';

    /** @var array<int, int>|null */
    private ?array $loads = null;

    private function pivotSchema(): array
    {
        return [
            Forms\Components\TextInput::make('capacity')->label('Capacidade')->numeric()->step(0.05)->minValue(0.05)->maxValue(5)->default(1)
                ->helperText('1 = carga padrão; 0,5 = meio período'),
            Forms\Components\Toggle::make('is_lead')->label('Líder (recebe alertas de SLA)'),
            Forms\Components\Toggle::make('is_available')->label('Disponível para distribuição')->default(true),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Agente')->searchable()->weight('bold'),
                Tables\Columns\IconColumn::make('pivot.is_lead')->label('Líder')->boolean(),
                Tables\Columns\TextColumn::make('pivot.capacity')->label('Capacidade')->numeric(2),
                Tables\Columns\TextColumn::make('load')->label('Carga atual')
                    ->state(fn (User $record) => $this->loadFor($record->id))
                    ->badge()
                    ->color(function (User $record) {
                        $cap = $this->getOwnerRecord()->max_weight_per_agent;
                        if (! $cap) {
                            return 'gray';
                        }
                        $ratio = $this->loadFor($record->id) / max(0.01, $cap * (float) $record->pivot->capacity);

                        return $ratio >= 1 ? 'danger' : ($ratio >= 0.75 ? 'warning' : 'success');
                    }),
                Tables\Columns\ToggleColumn::make('is_available')->label('Disponível')
                    ->getStateUsing(fn (User $record) => (bool) $record->pivot->is_available)
                    ->updateStateUsing(function (User $record, bool $state) {
                        $this->getOwnerRecord()->members()->updateExistingPivot($record->id, ['is_available' => $state]);

                        return $state;
                    }),
                Tables\Columns\TextColumn::make('pivot.last_assigned_at')->label('Última distribuição')->since()->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Adicionar agente')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn ($query) => $query->where('is_agent', true)->where('is_active', true))
                    ->form(fn (Tables\Actions\AttachAction $action) => [$action->getRecordSelect(), ...$this->pivotSchema()]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->form($this->pivotSchema()),
                Tables\Actions\DetachAction::make()->label('Remover'),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make(),
            ]);
    }

    private function loadFor(int $userId): int
    {
        if ($this->loads === null) {
            $ids = $this->getOwnerRecord()->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            $this->loads = app(AgentWorkloadRepositoryInterface::class)->openWeightByAgent($ids);
        }

        return $this->loads[$userId] ?? 0;
    }
}
