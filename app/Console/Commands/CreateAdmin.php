<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class CreateAdmin extends Command
{
    protected $signature = 'helpdesk:create-admin {--name=} {--email=} {--password=}';

    protected $description = 'Cria (ou promove) um usuário super-admin do helpdesk';

    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Nome');
        $email = $this->option('email') ?? $this->ask('E-mail');
        $password = $this->option('password') ?? $this->secret('Senha (mín. 12 caracteres)');

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::findOrCreate(config('helpdesk.rbac.super_admin_role'), config('auth.defaults.guard', 'web'));

        $user = User::query()->updateOrCreate(['email' => $email], [
            'name' => $name,
            'username' => User::query()->where('email', '!=', $email)->where('username', Str::before($email, '@'))->exists()
                ? null
                : Str::lower(Str::before($email, '@')),
            'password' => $password,
            'is_agent' => true,
            'is_active' => true,
        ]);

        $user->assignRole($role);

        $this->components->info("Super-admin pronto: {$user->email}");

        return self::SUCCESS;
    }
}
