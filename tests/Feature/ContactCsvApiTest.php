<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function csvUser(): User
{
    test()->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', 'dono@acme.com')->firstOrFail();
}

function csvFile(string $content, string $name = 'contatos.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

it('sugere contatos da empresa ao digitar, no máximo 8', function () {
    $user = csvUser();
    foreach (range(1, 10) as $i) {
        Contact::create(['tenant_id' => $user->tenant_id, 'name' => "Ana {$i}", 'email' => "ana{$i}@ex.com", 'phone' => '+5511999990000']);
    }
    Contact::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'Ana Alheia', 'email' => 'alheia@x.com']);

    $json = $this->getJson('/contatos/sugestoes?q=ana')->assertOk()->json();

    expect($json)->toHaveCount(8)->and(collect($json)->pluck('email'))->not->toContain('alheia@x.com')
        ->and(array_keys($json[0]))->toBe(['name', 'email', 'phone']);
    expect($this->getJson('/contatos/sugestoes?q=a')->json())->toBe([]);
});

it('o formulário de criar documento sabe onde buscar as sugestões', function () {
    csvUser();

    $this->get('/envelopes/novo')->assertOk()->assertSee('data-suggest-url="'.route('contacts.suggest').'"', false);
});

it('importa CSV com ponto e vírgula, BOM e cabeçalhos em português', function () {
    $user = csvUser();
    Contact::create(['tenant_id' => $user->tenant_id, 'name' => 'Ana Editada', 'email' => 'ana@ex.com']);
    $csv = "\xEF\xBB\xBFNome;E-mail;Telefone;CPF;Empresa;Observação\n"
        ."Ana Nova;ANA@ex.com;(11) 99999-8888;;Padaria;\n"
        ."Bruno;bruno@ex.com;;123.456.789-09;;cliente\n"
        ."Sem e-mail;;;;;\n";

    $this->post('/contatos/importar', ['file' => csvFile($csv)])->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Importação concluída: 1 criado(s), 1 atualizado(s), 1 ignorado(s).');

    $ana = Contact::where('email', 'ana@ex.com')->firstOrFail();
    expect($ana->name)->toBe('Ana Editada')->and($ana->phone)->toBe('+5511999998888')->and($ana->company)->toBe('Padaria');
    expect(Contact::where('email', 'bruno@ex.com')->firstOrFail()->document)->toBe('12345678909');
    expect(session('import_errors'))->toContain('Linha 4: e-mail ausente ou inválido.');
});

it('importa CSV com vírgula e cabeçalhos em inglês', function () {
    csvUser();
    $csv = "name,email,phone,company\n\"Silva, Carla\",carla@ex.com,+5521988887777,\"Empresa, S.A.\"\n";

    $this->post('/contatos/importar', ['file' => csvFile($csv)])->assertSessionHasNoErrors();

    $c = Contact::where('email', 'carla@ex.com')->firstOrFail();
    expect($c->name)->toBe('Silva, Carla')->and($c->company)->toBe('Empresa, S.A.')->and($c->phone)->toBe('+5521988887777');
});

it('recusa arquivo que não é CSV ou CSV sem coluna de e-mail', function () {
    csvUser();

    $this->post('/contatos/importar', ['file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF")])->assertSessionHasErrors('file');
    $this->post('/contatos/importar', ['file' => csvFile("nome;telefone\nAna;11999998888\n")])->assertSessionHasErrors('file');
    expect(Contact::count())->toBe(0);
});

it('exporta CSV com BOM, ponto e vírgula e proteção contra fórmula', function () {
    $user = csvUser();
    Contact::create(['tenant_id' => $user->tenant_id, 'name' => '=SOMA(1;2)', 'email' => 'a@ex.com', 'phone' => '+5511999998888', 'company' => '@empresa']);
    Contact::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'Alheio', 'email' => 'alheio@x.com']);

    $response = $this->get('/contatos/exportar')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $body = $response->streamedContent();

    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($body)->toContain('Nome;E-mail;Telefone')->toContain("'=SOMA(1;2)")->toContain("'@empresa")
        ->toContain('+5511999998888')->not->toContain("'+5511999998888")->not->toContain('Alheio');
});

it('API de contatos: listar, criar, ver, editar e apagar, com isolamento e chave somente leitura', function () {
    $tenant = Tenant::create(['name' => 'ACME']);
    [, $plain] = ApiKey::issue($tenant, 'k');
    [, $ro] = ApiKey::issue($tenant, 'ro', true);
    $h = ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
    $alheio = Contact::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'Alheio', 'email' => 'alheio@x.com']);

    $id = $this->postJson('/api/v1/contacts', ['name' => 'Ana', 'email' => 'Ana@Ex.com', 'phone' => '(11) 99999-8888'], $h)
        ->assertCreated()->assertJsonPath('email', 'ana@ex.com')->assertJsonPath('phone', '+5511999998888')->json('id');
    $this->postJson('/api/v1/contacts', ['name' => 'Ana 2', 'email' => 'ana@ex.com'], $h)->assertStatus(422)->assertJsonPath('error', 'contact_email_taken');
    $this->getJson('/api/v1/contacts?q=ana', $h)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/contacts/{$id}", $h)->assertOk()->assertJsonPath('name', 'Ana');
    $this->patchJson("/api/v1/contacts/{$id}", ['company' => 'Ex Ltda'], $h)->assertOk()->assertJsonPath('company', 'Ex Ltda')->assertJsonPath('name', 'Ana');
    $this->getJson("/api/v1/contacts/{$alheio->id}", $h)->assertNotFound();
    $this->patchJson("/api/v1/contacts/{$alheio->id}", ['name' => 'X'], $h)->assertNotFound();
    $this->postJson('/api/v1/contacts', ['name' => 'X', 'email' => 'x@ex.com'], ['Authorization' => "Bearer {$ro}", 'Accept' => 'application/json'])->assertForbidden();
    $this->deleteJson("/api/v1/contacts/{$id}", [], $h)->assertNoContent();
    expect(Contact::where('tenant_id', $tenant->id)->count())->toBe(0);

    $spec = $this->getJson('/api/v1/openapi.json')->json();
    expect($spec['paths'])->toHaveKeys(['/contacts', '/contacts/{id}'])->and($spec['components']['schemas'])->toHaveKey('Contact');
});
