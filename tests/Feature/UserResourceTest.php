<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

class UserResourceTest extends HelpdeskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->agent('super-admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function create(string $username, string $email = 'novo@empresa.com')
    {
        return Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Breno', 'username' => $username, 'email' => $email, 'password' => 'SenhaForte123!'])
            ->call('create');
    }

    /** Caso reportado: espaço invisível no fim + maiúscula. */
    public function test_username_with_trailing_space_and_uppercase_is_normalized(): void
    {
        $this->create('Breno.berto ')->assertHasNoFormErrors();

        $this->assertSame('breno.berto', User::query()->where('email', 'novo@empresa.com')->value('username'));
    }

    public function test_leading_at_sign_is_accepted(): void
    {
        $this->create('@ana-lima')->assertHasNoFormErrors();
        $this->assertSame('ana-lima', User::query()->where('email', 'novo@empresa.com')->value('username'));
    }

    public function test_invalid_characters_are_rejected_with_clear_message(): void
    {
        $this->create('joão silva')->assertHasFormErrors(['username']);
    }

    public function test_duplicate_is_rejected_case_insensitively(): void
    {
        $this->create('breno.berto', 'a@empresa.com')->assertHasNoFormErrors();
        $this->create('BRENO.BERTO', 'b@empresa.com')->assertHasFormErrors(['username']);
    }

    public function test_normalized_username_can_be_mentioned(): void
    {
        $this->create('Breno.Berto')->assertHasNoFormErrors();
        $user = User::query()->where('email', 'novo@empresa.com')->firstOrFail();

        $this->assertTrue(app(\App\Services\Tickets\MentionParser::class)->resolve('Olá @Breno.Berto')->contains($user));
    }
}
