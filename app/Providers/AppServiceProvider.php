<?php

namespace App\Providers;

use App\Listeners\SendTicketAlerts;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Repositories\Contracts\AgentWorkloadRepositoryInterface;
use App\Repositories\Contracts\SlaTimerRepositoryInterface;
use App\Repositories\Contracts\TicketRepositoryInterface;
use App\Repositories\Eloquent\AgentWorkloadRepository;
use App\Repositories\Eloquent\SlaTimerRepository;
use App\Repositories\Eloquent\TicketRepository;
use App\Services\CustomFields\CustomFieldService;
use App\Services\Sla\BusinessTimeCalculator;
use App\Support\InputSanitizer;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public array $bindings = [
        TicketRepositoryInterface::class => TicketRepository::class,
        SlaTimerRepositoryInterface::class => SlaTimerRepository::class,
        AgentWorkloadRepositoryInterface::class => AgentWorkloadRepository::class,
    ];

    public function register(): void
    {
        // Caches por request/job (resetados entre jobs no worker)
        $this->app->scoped(BusinessTimeCalculator::class);
        $this->app->scoped(CustomFieldService::class);
    }

    public function boot(): void
    {
        // Super-admin ignora todas as checagens de permissão
        Gate::before(function (User $user) {
            return $user->hasRole(config('helpdesk.rbac.super_admin_role')) ? true : null;
        });

        Gate::policy(Role::class, RolePolicy::class);

        Event::subscribe(SendTicketAlerts::class);

        $this->registerInputSanitizing();

        FilamentAsset::register([
            Css::make('helpdesk-theme', resource_path('css/helpdesk-theme.css')),
        ]);
    }

    /**
     * Livewire não passa pelo TrimStrings: limpa espaços/invisíveis de todos os
     * campos de texto ANTES da validação e ao salvar (senhas ficam intactas).
     */
    private function registerInputSanitizing(): void
    {
        $clean = fn (mixed $state, Field $component) => ($component instanceof TextInput && $component->isPassword())
            ? $state
            : InputSanitizer::clean($state);

        TextInput::configureUsing(fn (TextInput $input) => $input
            ->mutateStateForValidationUsing($clean)
            ->mutateDehydratedStateUsing($clean));

        Textarea::configureUsing(fn (Textarea $input) => $input
            ->mutateStateForValidationUsing($clean)
            ->mutateDehydratedStateUsing($clean));

        // Campos com formato próprio: ->normalizeUsing(fn ($state) => InputSanitizer::slug($state))
        Field::macro('normalizeUsing', function (\Closure $normalize): static {
            /** @var Field $this */
            return $this->mutateStateForValidationUsing($normalize)->mutateDehydratedStateUsing($normalize);
        });
    }
}
