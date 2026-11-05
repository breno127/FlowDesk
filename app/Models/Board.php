<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Board extends Model
{
    protected $fillable = ['department_id', 'team_id', 'name', 'slug', 'description', 'is_default', 'is_active'];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(function (self $board) {
            if ($board->is_default) {
                static::query()->whereKeyNot($board->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function initialColumn(): ?BoardColumn
    {
        return $this->columns()->where('is_initial', true)->first()
            ?? $this->columns()->first();
    }

    /** Quadros que o usuário pode abrir: globais, do seu departamento ou das suas equipes. */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $teamIds = $user->teamIds();

        // N1: quadros da própria equipe; sem quadro de equipe, os globais/do departamento
        // (os cards continuam filtrados pela equipe em Ticket::scopeVisibleTo)
        if ($user->isKanbanOnly() && Board::query()->whereIn('team_id', $teamIds)->where('is_active', true)->exists()) {
            return $query->whereIn('team_id', $teamIds);
        }

        if (! $user->isKanbanOnly() && ($user->isSuperAdmin() || $user->can('tickets.view_all'))) {
            return $query;
        }
        $departmentIds = Team::query()->whereIn('id', $teamIds)->pluck('department_id')->all();

        return $query->where(function (Builder $q) use ($teamIds, $departmentIds) {
            $q->where(fn (Builder $g) => $g->whereNull('department_id')->whereNull('team_id'))
                ->orWhereIn('team_id', $teamIds)
                ->orWhere(fn (Builder $d) => $d->whereNull('team_id')->whereIn('department_id', $departmentIds));
        });
    }
}
