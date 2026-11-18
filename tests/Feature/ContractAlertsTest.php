<?php

declare(strict_types=1);

use App\Contracts\ContractAlerts;
use App\Mail\ContractExpiryMail;
use App\Models\ContractAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-18 11:00:00', 'UTC')); // 08:00 em Brasília
});

afterEach(fn () => Carbon::setTestNow());

it('avisa só os e-mails indicados quando entra no prazo escolhido', function () {
    $u = wzOwner();
    $e = ctDue($u, 30, 'Aluguel', 30);
    $e->update(['contract_alert_emails' => ['um@wz.test', 'dois@wz.test']]);

    expect(app(ContractAlerts::class)->send())->toBe(1);

    Mail::assertSent(ContractExpiryMail::class, 2);
    Mail::assertSent(ContractExpiryMail::class, fn ($m) => $m->hasTo('um@wz.test') && $m->kind === 'before' && $m->daysLeft === 30);
    Mail::assertSent(ContractExpiryMail::class, fn ($m) => $m->hasTo('dois@wz.test'));
    Mail::assertNotSent(ContractExpiryMail::class, fn ($m) => $m->hasTo($u->email));
    expect(ContractAlert::count())->toBe(1)->and(ContractAlert::first()->kind)->toBe('before');
});

it('não avisa antes do prazo escolhido', function () {
    ctDue(wzOwner(), 31, 'Cedo demais', 30);

    expect(app(ContractAlerts::class)->send())->toBe(0);
    Mail::assertNothingSent();
});

it('envia cada aviso uma única vez mesmo rodando todo dia', function () {
    ctDue(wzOwner(), 10, 'Uma vez', 30);

    app(ContractAlerts::class)->send();
    Carbon::setTestNow(Carbon::now()->addDay());
    app(ContractAlerts::class)->send();

    Mail::assertSent(ContractExpiryMail::class, 1);
});

it('no dia do vencimento manda o aviso de vencimento, além do aviso antecipado', function () {
    $u = wzOwner();
    ctDue($u, 10, 'Dois avisos', 30);

    app(ContractAlerts::class)->send();
    Carbon::setTestNow(Carbon::now()->addDays(10));
    expect(app(ContractAlerts::class)->send())->toBe(1);

    Mail::assertSent(ContractExpiryMail::class, fn ($m) => $m->kind === 'due' && $m->daysLeft === 0);
    expect(ContractAlert::pluck('kind')->sort()->values()->all())->toBe(['before', 'due']);
});

it('contrato que já venceu sem aviso recebe só o aviso de vencimento, uma vez', function () {
    ctDue(wzOwner(), -4, 'Venceu', 30);

    expect(app(ContractAlerts::class)->send())->toBe(1);
    app(ContractAlerts::class)->send();

    Mail::assertSent(ContractExpiryMail::class, 1);
    Mail::assertSent(ContractExpiryMail::class, fn ($m) => $m->kind === 'due' && $m->daysLeft === -4);
});

it('renovar a data libera novos avisos', function () {
    $u = wzOwner();
    $e = ctDue($u, 5, 'Renovado', 30);
    app(ContractAlerts::class)->send();

    app(\App\Contracts\ContractWatch::class)->setTerm($e, Carbon::now('America/Sao_Paulo')->addDays(20)->toDateString(), 30, ['gestor@wz.test']);
    expect(ContractAlert::count())->toBe(0);
    expect(app(ContractAlerts::class)->send())->toBe(1);

    Mail::assertSent(ContractExpiryMail::class, 2);
});

it('só considera contratos concluídos e fora da lixeira, e ignora empresa suspensa', function () {
    $u = wzOwner();
    ctDue($u, 5, 'Andamento')->update(['status' => 'in_progress']);
    ctDue($u, 5, 'Lixeira')->update(['trashed_at' => now()]);
    $susp = wzOwner('susp@wz.test');
    ctDue($susp, 5, 'Suspensa');
    $susp->tenant->forceFill(['suspended_at' => now()])->save();

    expect(app(ContractAlerts::class)->send())->toBe(0);
    Mail::assertNothingSent();
});

it('uma falha de envio não marca como enviado nem impede os outros contratos', function () {
    $u = wzOwner();
    $bad = ctDue($u, 5, 'Falha');
    $ok = ctDue($u, 6, 'Funciona');
    $calls = 0;
    Mail::shouldReceive('to')->andReturnUsing(function () use (&$calls) {
        $calls++;
        $m = Mockery::mock();
        $m->shouldReceive('send')->andReturnUsing(function () use ($calls) {
            if ($calls === 1) {
                throw new RuntimeException('smtp fora do ar');
            }
        });

        return $m;
    });

    expect(app(ContractAlerts::class)->send())->toBe(1)
        ->and(ContractAlert::count())->toBe(1)
        ->and(ContractAlert::first()->envelope_id)->toBe($ok->id)
        ->and(ContractAlert::where('envelope_id', $bad->id)->exists())->toBeFalse();
});

it('o comando contracts:send-alerts roda e informa o total', function () {
    ctDue(wzOwner(), 5, 'Pelo comando');

    $this->artisan('contracts:send-alerts')->expectsOutputToContain('1 aviso')->assertExitCode(0);
});

it('o comando está agendado todo dia às 11:00 UTC (08:00 em Brasília)', function () {
    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'contracts:send-alerts'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 11 * * *');
});

it('o e-mail traz título, data, dias e link para o contrato', function () {
    $u = wzOwner();
    $e = ctDue($u, 12, 'Contrato de TI', 30);

    $html = (new ContractExpiryMail($e, 'before', 12))->render();

    expect($html)->toContain('Contrato de TI')->and($html)->toContain('12 dias')->and($html)->toContain(Carbon::now('America/Sao_Paulo')->addDays(12)->format('d/m/Y'))
        ->and($html)->toContain(route('envelopes.show', $e));
    expect((new ContractExpiryMail($e, 'due', 0))->envelope()->subject)->toContain('vence hoje')
        ->and((new ContractExpiryMail($e, 'due', -3))->envelope()->subject)->toContain('venceu')
        ->and((new ContractExpiryMail($e, 'before', 12))->envelope()->subject)->toContain('12 dias');
});

it('falha em um dos e-mails registra só quem recebeu e não repete o envio no dia seguinte', function () {
    $u = wzOwner();
    $e = ctDue($u, 5, 'Parcial');
    $e->update(['contract_alert_emails' => ['bom@wz.test', 'ruim@wz.test', 'outro@wz.test']]);
    Mail::shouldReceive('to')->andReturnUsing(function ($to) {
        $m = Mockery::mock();
        $m->shouldReceive('send')->andReturnUsing(function () use ($to) {
            if ($to === 'ruim@wz.test') {
                throw new RuntimeException('rejeitado');
            }
        });

        return $m;
    });

    expect(app(ContractAlerts::class)->send())->toBe(1)->and(ContractAlert::first()->sent_to)->toBe('bom@wz.test, outro@wz.test');
    Carbon::setTestNow(Carbon::now()->addDay());
    expect(app(ContractAlerts::class)->send())->toBe(0);
});

it('quando ninguém recebe, não deixa registro e tenta de novo', function () {
    ctDue(wzOwner(), 5, 'Ninguém');
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp fora'));

    expect(app(ContractAlerts::class)->send())->toBe(0)->and(ContractAlert::count())->toBe(0);
});

it('trocar só os e-mails ou o prazo não reenvia o aviso que já saiu; trocar a data libera', function () {
    $u = wzOwner();
    $e = ctDue($u, 5, 'Edição');
    app(ContractAlerts::class)->send();
    $watch = app(\App\Contracts\ContractWatch::class);
    $due = $e->fresh()->contract_expires_on->toDateString();

    $watch->setTerm($e->fresh(), $due, 60, ['novo@wz.test']);
    expect(ContractAlert::count())->toBe(1);
    expect(app(ContractAlerts::class)->send())->toBe(0);

    $watch->setTerm($e->fresh(), Carbon::now('America/Sao_Paulo')->addDays(9)->toDateString(), 60, ['novo@wz.test']);
    expect(ContractAlert::count())->toBe(0);
});

it('lista de e-mails malformada na vigência volta com erro em vez de 500', function () {
    $u = wzOwner();
    $e = ctDoc($u);

    $this->actingAs($u)->put("/documentos/{$e->id}/vigencia", ['contract_date' => '2027-03-31', 'contract_alert_days' => 30, 'contract_emails' => [['x']]])->assertSessionHasErrors();
    expect($e->fresh()->contract_expires_on)->toBeNull();
});
