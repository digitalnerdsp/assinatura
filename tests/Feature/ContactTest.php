<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Models\Contact;
use App\Models\Envelope;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function contactUser(string $email = 'dono@acme.com', string $company = 'ACME'): User
{
    test()->post('/cadastro', [
        'company' => $company, 'name' => 'Dono', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', $email)->firstOrFail();
}

it('cria, edita e apaga contato pela tela', function () {
    $user = contactUser();

    $this->post('/contatos', ['name' => 'Ana Souza', 'email' => 'Ana@Ex.com', 'phone' => '(11) 99999-8888', 'document' => '123.456.789-09', 'company' => 'Ex Ltda', 'notes' => 'cliente antiga'])
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Contato salvo.');
    $contact = Contact::firstOrFail();
    expect($contact->tenant_id)->toBe($user->tenant_id)->and($contact->email)->toBe('ana@ex.com')
        ->and($contact->phone)->toBe('+5511999998888')->and($contact->document)->toBe('12345678909');

    $this->put("/contatos/{$contact->id}", ['name' => 'Ana S. Lima', 'email' => 'ana@ex.com'])->assertSessionHasNoErrors();
    expect($contact->fresh()->name)->toBe('Ana S. Lima');

    $this->get('/contatos')->assertOk()->assertSee('Ana S. Lima');

    $this->delete("/contatos/{$contact->id}")->assertSessionHas('status', 'Contato apagado.');
    expect(Contact::count())->toBe(0);
});

it('recusa e-mail repetido (sem diferenciar maiúsculas), telefone e documento inválidos', function () {
    contactUser();
    $this->post('/contatos', ['name' => 'Ana', 'email' => 'ana@ex.com']);

    $this->post('/contatos', ['name' => 'Outra Ana', 'email' => 'ANA@EX.COM'])->assertSessionHasErrors('email');
    $this->post('/contatos', ['name' => 'Bruno', 'email' => 'bruno@ex.com', 'phone' => 'abc'])->assertSessionHasErrors('phone');
    $this->post('/contatos', ['name' => 'Bruno', 'email' => 'bruno@ex.com', 'document' => '123456789012345'])->assertSessionHasErrors('document');
    expect(Contact::count())->toBe(1);
});

it('busca por nome, e-mail ou empresa e filtra por etiqueta', function () {
    $user = contactUser();
    $tag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'Clientes', 'color' => 'info']);
    $this->post('/contatos', ['name' => 'Ana Souza', 'email' => 'ana@ex.com', 'company' => 'Padaria Central', 'tags' => [$tag->id]]);
    $this->post('/contatos', ['name' => 'Bruno Lima', 'email' => 'bruno@outra.com']);

    $this->get('/contatos?q=padaria')->assertSee('Ana Souza')->assertDontSee('Bruno Lima');
    $this->get('/contatos?q=BRUNO@')->assertSee('Bruno Lima')->assertDontSee('Ana Souza');
    $this->get('/contatos?tag='.$tag->id)->assertSee('Ana Souza')->assertDontSee('Bruno Lima');
});

it('contato e etiqueta de outra empresa não são acessíveis', function () {
    $other = Tenant::create(['name' => 'Outra']);
    $foreign = Contact::create(['tenant_id' => $other->id, 'name' => 'Alheio', 'email' => 'alheio@x.com']);
    $foreignTag = Tag::create(['tenant_id' => $other->id, 'name' => 'Alheia', 'color' => 'info']);
    contactUser();

    $this->put("/contatos/{$foreign->id}", ['name' => 'Invadido', 'email' => 'alheio@x.com'])->assertNotFound();
    $this->delete("/contatos/{$foreign->id}")->assertNotFound();
    $this->get('/contatos')->assertDontSee('Alheio');
    $this->post('/contatos', ['name' => 'Ana', 'email' => 'ana@ex.com', 'tags' => [$foreignTag->id]])->assertSessionHasErrors('tags');
    expect($foreign->fresh()->name)->toBe('Alheio');
});

it('signatários e testemunhas de um envelope viram contatos (o remetente não)', function () {
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'ACME']);

    app(EnvelopeService::class)->create($tenant, null, "%PDF-1.4\n%%EOF", 'a.pdf', 'Contrato', [
        ['name' => 'Parte Um', 'email' => 'Parte@Ex.com', 'phone' => '(11) 98888-7777', 'cpf' => '111.222.333-44'],
        ['name' => 'Testemunha', 'email' => 'test@ex.com', 'role' => 'witness'],
    ], options: ['sender' => ['name' => 'Empresa X', 'email' => 'empresa@x.com']]);

    $emails = Contact::where('tenant_id', $tenant->id)->orderBy('email')->pluck('email')->all();
    expect($emails)->toBe(['parte@ex.com', 'test@ex.com']);
    $parte = Contact::where('email', 'parte@ex.com')->firstOrFail();
    expect($parte->phone)->toBe('+5511988887777')->and($parte->document)->toBe('11122233344');
});

it('repetir o e-mail não duplica, não sobrescreve o nome editado e preenche o que estava vazio', function () {
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'ACME']);
    Contact::create(['tenant_id' => $tenant->id, 'name' => 'Nome Editado', 'email' => 'parte@ex.com']);

    app(EnvelopeService::class)->create($tenant, null, "%PDF-1.4\n%%EOF", 'a.pdf', 'C', [
        ['name' => 'Outro Nome', 'email' => 'PARTE@ex.com', 'phone' => '(21) 97777-6666'],
    ]);

    $c = Contact::where('tenant_id', $tenant->id)->firstOrFail();
    expect(Contact::where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and($c->name)->toBe('Nome Editado')->and($c->phone)->toBe('+5521977776666');
});

it('apagar o contato não altera o envelope já criado', function () {
    Storage::fake('local');
    $user = contactUser();
    $envelope = app(EnvelopeService::class)->create($user->tenant, $user, "%PDF-1.4\n%%EOF", 'a.pdf', 'C', [['name' => 'Ana', 'email' => 'ana@ex.com']]);
    $contact = Contact::where('email', 'ana@ex.com')->firstOrFail();

    $this->delete("/contatos/{$contact->id}");

    expect(Envelope::findOrFail($envelope->id)->signers()->first()->email)->toBe('ana@ex.com');
});
