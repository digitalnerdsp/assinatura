<?php

declare(strict_types=1);

use App\Calendar\CalendarService;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function calDoc(Tenant $t, string $status, array $extra = []): Envelope
{
    $doc = Document::create(['tenant_id' => $t->id, 'name' => 'a.pdf', 'storage_path' => 'documents/'.Str::uuid().'.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);

    return Envelope::create($extra + [
        'tenant_id' => $t->id, 'document_id' => $doc->id, 'title' => 'Doc '.$status, 'status' => $status,
        'verification_code' => strtoupper(Str::random(12)),
    ]);
}

function calUser(): User
{
    test()->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', 'dono@acme.com')->firstOrFail();
}

function calEvents(Tenant $t, string $from, string $to): array
{
    return app(CalendarService::class)
        ->events($t, CarbonImmutable::parse($from, 'America/Sao_Paulo'), CarbonImmutable::parse($to, 'America/Sao_Paulo'))
        ->map(fn ($e) => $e['date'].' '.$e['type'].' '.$e['title'])->sort()->values()->all();
}

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'America/Sao_Paulo')));

it('mostra criação, conclusão, prazo e lembrete nos dias certos no fuso de São Paulo', function () {
    $t = Tenant::create(['name' => 'ACME']);
    // 02:30 UTC do dia 5 ainda é dia 4 em São Paulo
    calDoc($t, 'completed', ['title' => 'Concluido', 'created_at' => CarbonImmutable::parse('2026-11-05 02:30', 'UTC'), 'sent_at' => CarbonImmutable::parse('2026-11-05 02:30', 'UTC'), 'completed_at' => CarbonImmutable::parse('2026-11-06 15:00', 'America/Sao_Paulo')]);
    $open = calDoc($t, 'sent', ['title' => 'Aberto', 'created_at' => CarbonImmutable::parse('2026-11-08 10:00', 'America/Sao_Paulo'), 'expires_at' => CarbonImmutable::parse('2026-11-20 10:00', 'America/Sao_Paulo'), 'reminder_every_days' => 3]);
    Signer::create(['envelope_id' => $open->id, 'name' => 'Ana', 'email' => 'ana@ex.com', 'invited_at' => CarbonImmutable::parse('2026-11-08 10:00', 'America/Sao_Paulo')]);
    Signer::create(['envelope_id' => $open->id, 'name' => 'Bia', 'email' => 'bia@ex.com', 'invited_at' => CarbonImmutable::parse('2026-11-08 10:00', 'America/Sao_Paulo'), 'last_reminded_at' => CarbonImmutable::parse('2026-11-09 10:00', 'America/Sao_Paulo')]);

    expect(calEvents($t, '2026-11-01', '2026-11-30'))->toBe([
        '2026-11-04 criado Concluido',
        '2026-11-06 concluido Concluido',
        '2026-11-08 criado Aberto',
        '2026-11-11 lembrete Aberto',
        '2026-11-20 prazo Aberto',
    ]);
});

it('prazo só para documento aberto e sem lembrete quando os lembretes estão desligados ou esgotados', function () {
    $t = Tenant::create(['name' => 'ACME']);
    calDoc($t, 'refused', ['title' => 'Recusado', 'created_at' => CarbonImmutable::parse('2026-10-01'), 'expires_at' => CarbonImmutable::parse('2026-11-20 12:00')]);
    $off = calDoc($t, 'sent', ['title' => 'SemLembrete', 'created_at' => CarbonImmutable::parse('2026-10-01'), 'expires_at' => CarbonImmutable::parse('2026-11-21 12:00'), 'reminder_every_days' => null]);
    Signer::create(['envelope_id' => $off->id, 'name' => 'Ana', 'email' => 'ana@ex.com', 'invited_at' => now()]);
    $max = calDoc($t, 'sent', ['title' => 'Esgotado', 'created_at' => CarbonImmutable::parse('2026-10-01'), 'reminder_every_days' => 2]);
    Signer::create(['envelope_id' => $max->id, 'name' => 'Ana', 'email' => 'ana@ex.com', 'invited_at' => now(), 'reminders_count' => (int) config('signing.max_reminders')]);

    expect(calEvents($t, '2026-11-01', '2026-11-30'))->toBe(['2026-11-21 prazo SemLembrete']);
});

it('não mostra documentos da lixeira nem de outra empresa', function () {
    $t = Tenant::create(['name' => 'ACME']);
    calDoc($t, 'draft', ['title' => 'Lixo', 'created_at' => now(), 'trashed_at' => now()]);
    calDoc(Tenant::create(['name' => 'Outra']), 'draft', ['title' => 'Alheio', 'created_at' => now()]);

    expect(calEvents($t, '2026-11-01', '2026-11-30'))->toBe([]);
});

it('a tela do mês e da semana mostra os eventos com link para o documento', function () {
    $user = calUser();
    $e = calDoc($user->tenant, 'draft', ['title' => 'Contrato Mensal', 'created_at' => CarbonImmutable::parse('2026-11-03 10:00', 'America/Sao_Paulo')]);
    calDoc($user->tenant, 'draft', ['title' => 'Outra Semana', 'created_at' => CarbonImmutable::parse('2026-11-25 10:00', 'America/Sao_Paulo')]);

    $this->get('/calendario')->assertOk()->assertSee('Novembro de 2026')
        ->assertSee('Contrato Mensal')->assertSee('Outra Semana')->assertSee(route('envelopes.show', $e), false);

    $this->get('/calendario?view=week&date=2026-11-03')->assertOk()
        ->assertSee('Contrato Mensal')->assertDontSee('Outra Semana')
        ->assertViewHas('days', fn ($days) => count($days) === 7);
});

it('parâmetros inválidos caem no mês atual sem erro', function () {
    calUser();

    $this->get('/calendario?view=ano&date=ontem')->assertOk()->assertSee('Novembro de 2026')
        ->assertViewHas('view', 'month');
});
