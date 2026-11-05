<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name', 'username', 'email', 'password', 'is_agent', 'is_active', 'timezone', 'phone', 'avatar_path', 'last_seen_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'is_agent' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Formato aceito para @menções (após normalização). */
    public const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,63}$/';

    /**
     * Normaliza o handle: remove espaços e "@" inicial e converte para minúsculas,
     * para casar com o MentionParser. Vazio vira null.
     */
    public static function normalizeUsername(?string $value): ?string
    {
        $value = mb_strtolower(ltrim(trim((string) $value), '@'));

        return $value === '' ? null : $value;
    }

    protected function username(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            set: fn (?string $value) => static::normalizeUsername($value),
        );
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->is_agent;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->using(TeamMembership::class)
            ->withPivot(['is_lead', 'capacity', 'is_available', 'last_assigned_at'])
            ->withTimestamps();
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assignee_id');
    }

    public function scopeAgents(Builder $query): Builder
    {
        return $query->where('is_agent', true)->where('is_active', true);
    }

    /** @return list<int> */
    public function teamIds(): array
    {
        return $this->teams()->pluck('teams.id')->map(fn ($id) => (int) $id)->all();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('helpdesk.rbac.super_admin_role'));
    }
}
