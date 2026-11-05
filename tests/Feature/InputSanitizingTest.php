<?php

namespace Tests\Feature;

use App\Filament\Resources\ClientCompanyResource\Pages\ManageClientCompanies;
use App\Filament\Resources\ContactResource\Pages\ManageContacts;
use App\Filament\Resources\DepartmentResource\Pages\ManageDepartments;
use App\Filament\Resources\TicketResource\Pages\CreateTicket;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\ClientCompany;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Support\InputSanitizer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * Livewire ignora o TrimStrings: estes testes garantem que espaços invisíveis
 * (fim de texto, NBSP, zero-width) e formatos comuns não quebram os formulários.
 */
class InputSanitizingTest extends HelpdeskTestCase
{
    private const NBSP = "\u{00A0}";

    private const ZWSP = "\u{200B}";

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->agent('super-admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_sanitizer_unit_rules(): void
    {
        $this->assertSame('texto', InputSanitizer::clean('  texto'.self::NBSP.self::ZWSP.' '));
        $this->assertNull(InputSanitizer::clean(' '.self::NBSP));
        $this->assertSame('empresa.com.br', InputSanitizer::domain(' Ana@Empresa.com.br '));
        $this->assertSame('empresa.com.br', InputSanitizer::domain('https://www.empresa.com.br/contato?x=1'));
        $this->assertSame('+5511999998888', InputSanitizer::phone('(11) 99999-8888'));
        $this->assertSame('+5511999998888', InputSanitizer::phone('55 11 99999-8888'));
        $this->assertSame('+351912345678', InputSanitizer::phone('+351 912 345 678'));
        $this->assertSame('suporte-n1', InputSanitizer::slug(' Suporte N1 '));
        $this->assertSame('numero_patrimonio', InputSanitizer::slug('Número Patrimônio', '_'));
    }

    /** Caso reportado: e-mail completo digitado no campo de domínio. */
    public function test_company_domain_is_extracted_from_email_or_url(): void
    {
        Livewire::test(ManageClientCompanies::class)
            ->callAction('create', data: ['name' => 'teste empresa', 'domain' => 'contato@acme.com.br '.self::NBSP])
            ->assertHasNoActionErrors();

        $this->assertSame('acme.com.br', ClientCompany::query()->where('name', 'teste empresa')->value('domain'));
    }

    public function test_public_email_provider_is_rejected_as_company_domain(): void
    {
        Livewire::test(ManageClientCompanies::class)
            ->callAction('create', data: ['name' => 'teste empresa', 'domain' => 'brenobertok@gmail.com'])
            ->assertHasActionErrors(['domain']);

        $this->assertSame(0, ClientCompany::query()->count());
    }

    public function test_contact_email_with_invisible_spaces_and_formatted_phone(): void
    {
        Livewire::test(ManageContacts::class)
            ->callAction('create', data: [
                'name' => ' Ana Lima ',
                'email' => 'ana@acme.com.br'.self::ZWSP.' ',
                'phone' => '(11) 98888-7777',
            ])
            ->assertHasNoActionErrors();

        $contact = Contact::query()->firstOrFail();
        $this->assertSame('Ana Lima', $contact->name);
        $this->assertSame('ana@acme.com.br', $contact->email);
        $this->assertSame('+5511988887777', $contact->phone);
    }

    public function test_slug_with_spaces_and_uppercase_is_normalized(): void
    {
        Livewire::test(ManageDepartments::class)
            ->callAction('create', data: ['name' => 'Suporte Técnico', 'slug' => ' Suporte Técnico '])
            ->assertHasNoActionErrors();

        $this->assertSame('suporte-tecnico', Department::query()->value('slug'));
    }

    public function test_user_email_with_trailing_space_and_password_untouched(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Carla', 'email' => 'carla@acme.com.br ', 'password' => 'senha com espaço '])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(User::query()->where('email', 'carla@acme.com.br')->first());

        Auth::logout();
        // Senha não é aparada: espaços fazem parte dela
        $this->assertTrue(Auth::validate(['email' => 'carla@acme.com.br', 'password' => 'senha com espaço ']));
        $this->assertFalse(Auth::validate(['email' => 'carla@acme.com.br', 'password' => 'senha com espaço']));
    }

    public function test_ticket_subject_is_trimmed(): void
    {
        Livewire::test(CreateTicket::class)
            ->fillForm(['subject' => self::NBSP.'Impressora parada  '])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Ticket::query()->where('subject', 'Impressora parada')->exists());
    }

    public function test_validation_messages_are_in_portuguese(): void
    {
        Livewire::test(ManageContacts::class)
            ->callAction('create', data: ['name' => 'X', 'email' => 'nao-e-email'])
            ->assertHasActionErrors(['email']);

        $this->assertStringContainsString('Informe um e-mail válido', __('validation.email', ['attribute' => 'E-mail']));
    }
}
