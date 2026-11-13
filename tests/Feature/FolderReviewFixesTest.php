<?php

declare(strict_types=1);

use App\Envelopes\Exceptions\FlowException;
use App\Folders\FolderService;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---- I-1 e I-2: a migration dedupe nomes antigos e o down() não quebra com subpastas de mesmo nome

it('a migration deduplica nomes que só diferem na caixa e o down() desfaz mesmo com subpastas de mesmo nome', function () {
    $migration = require base_path('database/migrations/2026_11_16_000001_add_parent_to_folders.php');
    $t = Tenant::create(['name' => 'ACME']);
    $svc = app(FolderService::class);
    $contratos = $svc->create($t, 'Contratos');
    $rh = $svc->create($t, 'RH');
    $svc->create($t, '2026', $contratos);
    $svc->create($t, '2026', $rh); // permitido: pastas pai diferentes

    $migration->down(); // não pode falhar com os dois "2026"

    // esquema antigo: a unicidade era por (empresa, nome) e diferenciava maiúsculas
    $now = now();
    DB::table('folders')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'name' => 'contratos', 'created_at' => $now->copy()->addMinute(), 'updated_at' => $now]);

    $migration->up(); // não pode abortar por causa de "Contratos" x "contratos"

    $names = DB::table('folders')->where('tenant_id', $t->id)->pluck('name');
    expect($names->map(fn ($n) => mb_strtolower($n))->unique()->count())->toBe($names->count())
        ->and($names->contains('Contratos'))->toBeTrue()          // o mais antigo mantém o nome
        ->and($names->contains('contratos (2)'))->toBeTrue();     // o repetido ganha sufixo
});

// ---- I-3: ciclo forçado não trava as consultas e as mudanças usam trava por empresa

it('um ciclo já gravado não faz as consultas recursivas rodarem para sempre', function () {
    $t = Tenant::create(['name' => 'ACME']);
    $svc = app(FolderService::class);
    $a = $svc->create($t, 'A');
    $b = $svc->create($t, 'B', $a);
    DB::table('folders')->where('id', $a->id)->update(['parent_id' => $b->id]); // estado corrompido: A > B > A
    DB::statement("SET LOCAL statement_timeout = '4000'"); // se travar, vira erro em vez de pendurar o teste

    expect(collect($svc->descendantIds($a->fresh()))->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
    expect($svc->impact($a->fresh())['folders'])->toBe(1);
    try {
        $svc->move($a->fresh(), null);
        $this->fail('um ciclo não pode passar pelo limite de níveis');
    } catch (FlowException $e) {
        expect($e->reason)->toBe('folder_too_deep');
    }
});

it('criar e mover pastas pegam uma trava por empresa para evitar ciclo por corrida', function () {
    $t = Tenant::create(['name' => 'ACME']);
    $svc = app(FolderService::class);
    $queries = [];
    DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

    $a = $svc->create($t, 'A');
    $b = $svc->create($t, 'B');
    $svc->move($b, $a);

    $locks = collect($queries)->filter(fn ($sql) => str_contains($sql, 'pg_advisory_xact_lock'))->count();
    expect($locks)->toBeGreaterThanOrEqual(3);
});

// ---- I-4: criar documento e ver documento mostram o caminho da pasta, não só o nome

function folderPathUser(): User
{
    test()->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', 'dono@acme.com')->firstOrFail();
}

it('os seletores de pasta mostram o caminho quando há nomes repetidos', function () {
    $user = folderPathUser();
    $svc = app(FolderService::class);
    $contratos = $svc->create($user->tenant, 'Contratos');
    $rh = $svc->create($user->tenant, 'RH');
    $svc->create($user->tenant, '2026', $contratos);
    $svc->create($user->tenant, '2026', $rh);

    $this->get('/envelopes/novo')->assertOk()->assertSee('Contratos › 2026')->assertSee('RH › 2026');

    $doc = Document::create(['tenant_id' => $user->tenant_id, 'name' => 'a.pdf', 'storage_path' => 'documents/'.Str::uuid().'.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);
    $envelope = Envelope::create(['tenant_id' => $user->tenant_id, 'document_id' => $doc->id, 'title' => 'X', 'status' => 'draft', 'verification_code' => strtoupper(Str::random(12))]);
    $this->get("/envelopes/{$envelope->id}")->assertOk()->assertSee('Contratos › 2026')->assertSee('RH › 2026');
});
