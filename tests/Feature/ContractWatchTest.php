<?php

declare(strict_types=1);

use App\Contracts\ContractWatch;
use App\Enums\EnvelopeStatus;
use App\Envelopes\Exceptions\FlowException;
use App\Models\ContractAlert;
use App\Models\Envelope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-11-18 15:00:00', 'UTC')); // 12h em Brasília
});

afterEach(fn () => Carbon::setTestNow());

function ctDoc(User $u, string $status = 'completed', string $title = 'Contrato'): Envelope
{
    $e = wzDraft($u, $title);
    $e->update(['status' => $status, 'completed_at' => $status === 'completed' ? now() : null]);

    return $e->refresh();
}

function ctWatch(): ContractWatch
{
    return app(ContractWatch::class);
}

it('grava a vigência normalizando os e-mails', function () {
    $u = wzOwner();
    $e = ctDoc($u);
    ctWatch()->setTerm($e, '2027-03-31', 30, [' Fulano@Empresa.com ', 'fulano@empresa.com', 'outro@x.com']);

    $e->refresh();
    expect($e->contract_expires_on->toDateString())->toBe('2027-03-31')->and($e->contract_alert_days)->toBe(30)
        ->and($e->contract_alert_emails)->toBe(['fulano@empresa.com', 'outro@x.com']);
});

it('recusa data inválida, dias fora da lista e e-mails fora de 1 a 5', function (array $args, string $message) {
    $e = ctDoc(wzOwner());
    expect(fn () => ctWatch()->setTerm($e, ...$args))->toThrow(FlowException::class, $message);
    expect($e->fresh()->contract_expires_on)->toBeNull();
})->with([
    'hoje' => [['2026-11-18', 30, ['a@x.com']], 'depois de hoje'],
    'passada' => [['2026-11-01', 30, ['a@x.com']], 'depois de hoje'],
    'longe demais' => [['2046-11-19', 30, ['a@x.com']], '20 anos'],
    'formato' => [['31/12/2027', 30, ['a@x.com']], 'data'],
    'dias 10' => [['2027-01-01', 10, ['a@x.com']], 'dias'],
    'dias 0' => [['2027-01-01', 0, ['a@x.com']], 'dias'],
    'sem e-mail' => [['2027-01-01', 30, []], 'e-mail'],
    'e-mail inválido' => [['2027-01-01', 30, ['nao-e-email']], 'e-mail'],
    'seis e-mails' => [['2027-01-01', 30, ['a@x.com', 'b@x.com', 'c@x.com', 'd@x.com', 'e@x.com', 'f@x.com']], 'no máximo 5'],
]);

it('aceita amanhã e o limite de 20 anos', function () {
    $e = ctDoc(wzOwner());
    ctWatch()->setTerm($e, '2026-11-19', 7, ['a@x.com']);
    ctWatch()->setTerm($e, '2046-11-18', 90, ['a@x.com']);
    expect($e->fresh()->contract_alert_days)->toBe(90);
});

it('renovar a data apaga os avisos já enviados e remover zera tudo', function () {
    $e = ctDoc(wzOwner());
    ctWatch()->setTerm($e, '2027-01-10', 30, ['a@x.com']);
    ContractAlert::create(['envelope_id' => $e->id, 'kind' => 'before', 'due_on' => '2027-01-10', 'sent_to' => 'a@x.com', 'sent_at' => now()]);

    ctWatch()->setTerm($e->refresh(), '2027-06-10', 30, ['a@x.com']);
    expect(ContractAlert::count())->toBe(0);

    ContractAlert::create(['envelope_id' => $e->id, 'kind' => 'due', 'due_on' => '2027-06-10', 'sent_to' => 'a@x.com', 'sent_at' => now()]);
    ctWatch()->clearTerm($e->refresh());
    $e->refresh();
    expect($e->contract_expires_on)->toBeNull()->and($e->contract_alert_days)->toBeNull()->and($e->contract_alert_emails)->toBeNull()->and(ContractAlert::count())->toBe(0);
});

it('conta os dias pelo calendário de Brasília, inclusive perto da meia-noite', function () {
    $e = ctDoc(wzOwner());
    ctWatch()->setTerm($e, '2026-11-20', 30, ['a@x.com']);

    expect(ctWatch()->daysLeft($e->fresh()))->toBe(2);

    Carbon::setTestNow(Carbon::parse('2026-11-19 02:30:00', 'UTC')); // 23h30 do dia 18 em Brasília
    expect(ctWatch()->daysLeft($e->fresh()))->toBe(2);
    Carbon::setTestNow(Carbon::parse('2026-11-19 03:30:00', 'UTC')); // 00h30 do dia 19 em Brasília
    expect(ctWatch()->daysLeft($e->fresh()))->toBe(1);
    Carbon::setTestNow(Carbon::parse('2026-11-21 12:00:00', 'UTC'));
    expect(ctWatch()->daysLeft($e->fresh()))->toBe(-1);
    expect(ctWatch()->daysLeft(ctDoc(wzOwner('o2@wz.test'))))->toBeNull();
});

it('separa as faixas nos limites', function (int $days, string $band) {
    expect(ctWatch()->band($days))->toBe($band);
})->with([
    [-30, 'overdue'], [-1, 'overdue'], [0, 'd30'], [30, 'd30'], [31, 'd60'], [60, 'd60'], [61, 'd90'], [90, 'd90'], [91, 'valid'], [900, 'valid'],
]);

it('a janela a vencer é 30 dias ou o aviso escolhido, o que for maior', function () {
    $e = ctDoc(wzOwner());
    ctWatch()->setTerm($e, '2027-01-10', 15, ['a@x.com']);
    expect(ctWatch()->windowDays($e->fresh()))->toBe(30);
    ctWatch()->setTerm($e->refresh(), '2027-01-10', 90, ['a@x.com']);
    expect(ctWatch()->windowDays($e->fresh()))->toBe(90);

    Carbon::setTestNow(Carbon::parse('2026-12-15 15:00:00', 'UTC')); // faltam 26 dias
    expect(ctWatch()->dueSoon($e->fresh()))->toBeTrue();
    Carbon::setTestNow(Carbon::parse('2026-09-01 15:00:00', 'UTC')); // faltam 131 dias
    expect(ctWatch()->dueSoon($e->fresh()))->toBeFalse();
});

it('só monitora contratos concluídos, da empresa, fora da lixeira e com vigência', function () {
    $u = wzOwner();
    $other = wzOwner('outro@wz.test');
    $ok = ctDoc($u, 'completed', 'Ok');
    $archived = ctDoc($u, 'completed', 'Arquivado');
    $archived->update(['archived_at' => now()]);
    $draft = ctDoc($u, 'draft', 'Rascunho');
    $going = ctDoc($u, 'in_progress', 'Andamento');
    $cancelled = ctDoc($u, 'cancelled', 'Cancelado');
    $trashed = ctDoc($u, 'completed', 'Lixeira');
    $trashed->update(['trashed_at' => now()]);
    $noTerm = ctDoc($u, 'completed', 'Sem vigência');
    $foreign = ctDoc($other, 'completed', 'Alheio');

    foreach ([$ok, $archived, $draft, $going, $cancelled, $trashed, $foreign] as $d) {
        ctWatch()->setTerm($d->refresh(), '2027-01-10', 30, ['a@x.com']);
    }

    expect(ctWatch()->monitored($u->tenant)->orderBy('title')->pluck('title')->all())->toBe(['Arquivado', 'Ok']);
    expect(ctWatch()->monitored($other->tenant)->pluck('title')->all())->toBe(['Alheio']);
    expect($noTerm->fresh()->contract_expires_on)->toBeNull();
});
