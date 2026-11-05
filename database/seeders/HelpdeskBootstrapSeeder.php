<?php

namespace Database\Seeders;

use App\Models\Board;
use App\Models\BusinessHourSchedule;
use App\Models\TicketPriority;
use App\Services\Kanban\BoardService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Configuração inicial a partir de config('helpdesk.bootstrap').
 * Idempotente: só cria o que ainda não existe — nunca sobrescreve o que foi
 * ajustado pelo painel.
 */
class HelpdeskBootstrapSeeder extends Seeder
{
    public function run(BoardService $boards): void
    {
        $config = config('helpdesk.bootstrap');

        Artisan::call('helpdesk:sync-permissions');

        DB::transaction(function () use ($config, $boards) {
            foreach ($config['priorities'] as $priority) {
                TicketPriority::query()->firstOrCreate(['slug' => $priority['slug']], $priority);
            }

            if (BusinessHourSchedule::query()->doesntExist()) {
                $schedule = BusinessHourSchedule::query()->create([
                    'name' => $config['business_hours']['name'],
                    'timezone' => $config['business_hours']['timezone'],
                    'is_default' => true,
                ]);
                $schedule->intervals()->createMany($config['business_hours']['intervals']);
            }

            if (Board::query()->doesntExist()) {
                $boards->createFromTemplate($config['board'], ['is_default' => true]);
            }

            $guard = config('auth.defaults.guard', 'web');
            $all = Permission::query()->where('guard_name', $guard)->pluck('name');

            foreach ($config['roles'] as $roleName => $patterns) {
                $role = Role::findOrCreate($roleName, $guard);

                if ($role->permissions()->exists()) {
                    continue; // já customizado pelo painel
                }

                $granted = $patterns === '*'
                    ? $all
                    : $all->filter(fn (string $p) => collect($patterns)->contains(fn ($pattern) => Str::is($pattern, $p)));

                $role->syncPermissions($granted->all());
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
