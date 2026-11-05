<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sincroniza o catálogo de permissões (config/helpdesk.php) com o banco.
 * Idempotente: pode rodar em todo deploy.
 */
class SyncPermissions extends Command
{
    protected $signature = 'helpdesk:sync-permissions {--prune : Remove permissões que não existem mais no catálogo}';

    protected $description = 'Sincroniza o catálogo de permissões do helpdesk com o banco';

    public function handle(PermissionRegistrar $registrar): int
    {
        $guard = config('auth.defaults.guard', 'web');
        $catalog = collect(config('helpdesk.rbac.permissions'))->flatMap(fn (array $group) => array_keys($group));

        DB::transaction(function () use ($catalog, $guard) {
            foreach ($catalog as $name) {
                Permission::findOrCreate($name, $guard);
            }

            if ($this->option('prune')) {
                $removed = Permission::query()->where('guard_name', $guard)->whereNotIn('name', $catalog->all())->delete();
                $this->components->info("{$removed} permissão(ões) obsoleta(s) removida(s).");
            }
        });

        $registrar->forgetCachedPermissions();

        $this->components->info($catalog->count().' permissões sincronizadas.');

        return self::SUCCESS;
    }
}
