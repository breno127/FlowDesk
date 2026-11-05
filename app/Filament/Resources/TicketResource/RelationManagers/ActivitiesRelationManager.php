<?php

namespace App\Filament\Resources\TicketResource\RelationManagers;

use App\Models\TicketActivity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Trilha de auditoria — somente leitura.
 */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Auditoria';

    protected static ?string $icon = 'heroicon-o-finger-print';

    private const LABELS = [
        'created' => 'Criado',
        'updated' => 'Atualizado',
        'moved' => 'Movido',
        'assigned' => 'Atribuído',
        'auto_assign_skipped' => 'Distribuição sem agente',
        'csat_recorded' => 'CSAT registrado',
        'deleted' => 'Excluído',
        'restored' => 'Restaurado',
    ];

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('update', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('causer:id,name'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s')->fontFamily('mono')->size('sm'),
                Tables\Columns\TextColumn::make('event')->label('Evento')->badge()
                    ->formatStateUsing(fn (string $state) => self::LABELS[$state] ?? $state),
                Tables\Columns\TextColumn::make('causer.name')->label('Por')->placeholder('Sistema / automação'),
                Tables\Columns\TextColumn::make('properties')->label('Detalhes')->wrap()
                    ->state(fn (TicketActivity $record) => $this->describe($record)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')->label('Evento')->options(self::LABELS),
            ]);
    }

    private function describe(TicketActivity $activity): ?string
    {
        $p = $activity->properties ?? [];

        return match ($activity->event) {
            'moved' => ($p['from']['name'] ?? '?').' → '.($p['to']['name'] ?? '?'),
            'assigned' => ($p['automatic'] ?? false) ? 'Distribuição automática' : 'Atribuição manual',
            'updated' => collect($p['changes'] ?? [])->keys()->implode(', '),
            'created' => 'Canal: '.config('helpdesk.channels.'.($p['channel'] ?? ''), $p['channel'] ?? '—'),
            'csat_recorded' => 'Nota '.($p['score'] ?? '?'),
            default => null,
        };
    }
}
