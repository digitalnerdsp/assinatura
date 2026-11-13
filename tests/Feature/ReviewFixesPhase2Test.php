<?php

declare(strict_types=1);

use App\Calendar\CalendarService;
use App\Contacts\ContactService;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Tenant;
use App\Models\User;
use App\Team\TeamService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function p2Owner(): User
{
    test()->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', 'dono@acme.com')->firstOrFail();
}

it('só o responsável cria chaves de API e webhooks (um membro desativado não deixa chave para trás)', function () {
    $owner = p2Owner();
    $member = User::create(['tenant_id' => $owner->tenant_id, 'name' => 'Colega', 'email' => 'colega@acme.com', 'password' => 'senha-muito-forte', 'role' => 'member']);
    auth()->logout();
    $this->actingAs($member);

    $this->get('/api-keys')->assertForbidden();
    $this->post('/api-keys', ['name' => 'minha', 'can_sign_as_company' => 1])->assertForbidden();
    $this->post('/webhooks', ['url' => 'https://exemplo.com/hook', 'events' => ['envelope.completed']])->assertForbidden();
    $this->get('/painel')->assertOk()->assertDontSee(route('api-keys.index'))->assertDontSee(route('webhooks.index'));
    expect(\App\Models\ApiKey::count())->toBe(0);
});

it('contato criado ao mesmo tempo por outro envio não derruba a transação do envelope', function () {
    $t = Tenant::create(['name' => 'ACME']);
    // simula a corrida: outro processo grava o mesmo contato entre a busca e a criação
    Contact::creating(function (Contact $c) {
        DB::table('contacts')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $c->tenant_id, 'name' => 'Outro', 'email' => $c->email, 'created_at' => now(), 'updated_at' => now()]);
    });

    DB::transaction(function () use ($t) {
        app(ContactService::class)->rememberFromSigners($t, [['name' => 'Ana', 'email' => 'ana@ex.com']]);
        // antes: SQLSTATE 25P02 (transação abortada); agora a transação do envelope continua utilizável
        expect(DB::selectOne('select 1 as ok')->ok)->toBe(1);
    });
});

it('convite pendente vencido não prende o e-mail: dá para se cadastrar ou ser convidado de novo', function () {
    $owner = p2Owner();
    [$pending] = app(TeamService::class)->invite($owner, 'Bob', 'bob@x.com');
    $this->travel(8)->days();
    auth()->logout();

    $this->post('/cadastro', [
        'company' => 'Empresa do Bob', 'name' => 'Bob', 'email' => 'bob@x.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    $bob = User::where('email', 'bob@x.com')->firstOrFail();
    expect($bob->id)->not->toBe($pending->id)->and($bob->tenant_id)->not->toBe($owner->tenant_id);

    // e outro dono pode convidar um e-mail cujo convite venceu
    [$p2] = app(TeamService::class)->invite($owner->fresh(), 'Carla', 'carla@x.com');
    $this->travel(8)->days();
    [$again] = app(TeamService::class)->invite($owner->fresh(), 'Carla', 'carla@x.com');
    expect($again->id)->not->toBe($p2->id)->and(User::find($p2->id))->toBeNull();
});

it('o calendário não faz uma consulta por documento para calcular os lembretes', function () {
    $t = Tenant::create(['name' => 'ACME']);
    foreach (range(1, 15) as $i) {
        $doc = Document::create(['tenant_id' => $t->id, 'name' => 'a.pdf', 'storage_path' => 'documents/'.Str::uuid().'.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);
        $e = Envelope::create(['tenant_id' => $t->id, 'document_id' => $doc->id, 'title' => 'D'.$i, 'status' => 'sent', 'verification_code' => strtoupper(Str::random(12)), 'reminder_every_days' => 3]);
        Signer::create(['envelope_id' => $e->id, 'name' => 'A', 'email' => "a{$i}@ex.com", 'invited_at' => now()]);
    }

    DB::enableQueryLog();
    $events = app(CalendarService::class)->events($t, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->addMonth()->endOfMonth());
    $queries = count(DB::getQueryLog());

    expect($events->where('type', 'lembrete')->count())->toBe(15)->and($queries)->toBeLessThan(10);
});
