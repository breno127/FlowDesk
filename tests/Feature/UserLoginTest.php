<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/** Garante que a senha definida no painel é a que funciona no login. */
class UserLoginTest extends HelpdeskTestCase
{
    public function test_password_set_in_panel_works_for_login(): void
    {
        $this->actingAs($this->agent('super-admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Novo', 'email' => 'novo@empresa.com', 'password' => 'Teste-Senha-Forte-123'])
            ->call('create')
            ->assertHasNoFormErrors();

        Auth::logout();
        $this->assertTrue(Auth::attempt(['email' => 'novo@empresa.com', 'password' => 'Teste-Senha-Forte-123']));

        // Editar sem preencher a senha mantém a atual
        Auth::logout();
        $this->actingAs(User::query()->where('email', '!=', 'novo@empresa.com')->firstOrFail());
        $user = User::query()->where('email', 'novo@empresa.com')->firstOrFail();
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['name' => 'Novo Nome', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        Auth::logout();
        $this->assertTrue(Auth::attempt(['email' => 'novo@empresa.com', 'password' => 'Teste-Senha-Forte-123']));
    }
}
