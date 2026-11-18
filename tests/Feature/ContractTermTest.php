<?php

declare(strict_types=1);

use App\Contracts\ContractWatch;
use App\Models\ContractAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-11-18 15:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

function ctTermBody(array $over = []): array
{
    return $over + ['contract_date' => '2027-03-31', 'contract_alert_days' => 60, 'contract_emails' => ['gestor@wz.test', 'juridico@wz.test']];
}

it('o detalhe mostra o cartão de vigência sem data e com o formulário para definir', function () {
    $u = wzOwner();
    $e = ctDoc($u);

    $this->actingAs($u)->get("/documentos/{$e->id}")->assertOk()->assertSee('Vigência do contrato')->assertSee('Sem vencimento monitorado')
        ->assertSee(route('envelopes.term.save', $e), false)->assertSee('name="contract_date"', false);
});

it('mostra a data, os dias que faltam, o aviso e os e-mails quando há vigência', function () {
    $u = wzOwner();
    $e = ctDoc($u);
    app(ContractWatch::class)->setTerm($e, '2027-03-31', 60, ['gestor@wz.test']);

    $this->actingAs($u)->get("/documentos/{$e->id}")->assertOk()->assertSee('31/03/2027')->assertSee('133 dias')->assertSee('60 dias antes')->assertSee('gestor@wz.test');
});

it('documento em andamento também mostra o cartão, mas avisa que só vale depois de todos assinarem', function () {
    $u = wzOwner();
    $e = ctDoc($u, 'in_progress');
    $this->actingAs($u)->get("/documentos/{$e->id}")->assertOk()->assertSee('Vigência do contrato')->assertSee('só vale depois que todos assinarem');
});

it('rascunho e lixeira não mostram o cartão', function () {
    $u = wzOwner();
    $draft = ctDoc($u, 'draft', 'Rascunho');
    $trashed = ctDoc($u, 'completed', 'Na lixeira');
    $trashed->update(['trashed_at' => now()]);

    $this->actingAs($u)->get("/documentos/{$draft->id}")->assertOk()->assertDontSee('Vigência do contrato');
    $this->actingAs($u)->get("/documentos/{$trashed->id}")->assertOk()->assertDontSee('Vigência do contrato');
});

it('define a vigência pelo detalhe', function () {
    $u = wzOwner();
    $e = ctDoc($u);

    $this->actingAs($u)->put("/documentos/{$e->id}/vigencia", ctTermBody())->assertRedirect("/documentos/{$e->id}")->assertSessionHas('status', 'Vigência do contrato salva.');

    $e->refresh();
    expect($e->contract_expires_on->toDateString())->toBe('2027-03-31')->and($e->contract_alert_days)->toBe(60)->and($e->contract_alert_emails)->toBe(['gestor@wz.test', 'juridico@wz.test']);
});

it('aceita os e-mails digitados numa linha separados por vírgula', function () {
    $u = wzOwner();
    $e = ctDoc($u);
    $this->actingAs($u)->put("/documentos/{$e->id}/vigencia", ctTermBody(['contract_emails' => 'um@wz.test, dois@wz.test;tres@wz.test']))->assertSessionHasNoErrors();

    expect($e->fresh()->contract_alert_emails)->toBe(['um@wz.test', 'dois@wz.test', 'tres@wz.test']);
});

it('renovar troca a data e libera novos avisos', function () {
    $u = wzOwner();
    $e = ctDoc($u);
    app(ContractWatch::class)->setTerm($e, '2027-01-10', 30, ['gestor@wz.test']);
    ContractAlert::create(['envelope_id' => $e->id, 'kind' => 'before', 'due_on' => '2027-01-10', 'sent_to' => 'gestor@wz.test', 'sent_at' => now()]);

    $this->actingAs($u)->put("/documentos/{$e->id}/vigencia", ctTermBody(['contract_date' => '2028-01-10']))->assertSessionHasNoErrors();

    expect($e->fresh()->contract_expires_on->toDateString())->toBe('2028-01-10')->and(ContractAlert::count())->toBe(0);
});

it('dados inválidos voltam com a mensagem e nada muda', function () {
    $u = wzOwner();
    $e = ctDoc($u);
    app(ContractWatch::class)->setTerm($e, '2027-01-10', 30, ['gestor@wz.test']);

    $this->actingAs($u)->from("/documentos/{$e->id}")->put("/documentos/{$e->id}/vigencia", ctTermBody(['contract_date' => '2026-01-01']))->assertRedirect("/documentos/{$e->id}")->assertSessionHasErrors('contract');
    expect($e->fresh()->contract_expires_on->toDateString())->toBe('2027-01-10');
    $this->actingAs($u)->put("/documentos/{$e->id}/vigencia", ctTermBody(['contract_emails' => []]))->assertSessionHasErrors('contract');
});

it('remover a vigência para o monitoramento', function () {
    $u = wzOwner();
    $e = ctDoc($u);
    app(ContractWatch::class)->setTerm($e, '2027-01-10', 30, ['gestor@wz.test']);

    $this->actingAs($u)->delete("/documentos/{$e->id}/vigencia")->assertRedirect("/documentos/{$e->id}")->assertSessionHas('status', 'Vigência do contrato removida.');
    expect($e->fresh()->contract_expires_on)->toBeNull();
});

it('outra empresa recebe 404 e rascunho e lixeira são recusados', function () {
    $u = wzOwner();
    $other = wzOwner('outro@wz.test');
    $theirs = ctDoc($other);
    $draft = ctDoc($u, 'draft', 'Rascunho');
    $trashed = ctDoc($u, 'completed', 'Lixeira');
    $trashed->update(['trashed_at' => now()]);

    $this->actingAs($u)->put("/documentos/{$theirs->id}/vigencia", ctTermBody())->assertNotFound();
    $this->actingAs($u)->delete("/documentos/{$theirs->id}/vigencia")->assertNotFound();
    $this->actingAs($u)->put("/documentos/{$trashed->id}/vigencia", ctTermBody())->assertNotFound();
    $this->actingAs($u)->put("/documentos/{$draft->id}/vigencia", ctTermBody())->assertSessionHasErrors('contract');
    expect($theirs->fresh()->contract_expires_on)->toBeNull()->and($draft->fresh()->contract_expires_on)->toBeNull();
});

it('quem não entrou na conta é mandado ao login', function () {
    $e = ctDoc(wzOwner());
    auth()->logout();
    $this->put("/documentos/{$e->id}/vigencia", ctTermBody())->assertRedirect('/entrar');
    $this->delete("/documentos/{$e->id}/vigencia")->assertRedirect('/entrar');
});
