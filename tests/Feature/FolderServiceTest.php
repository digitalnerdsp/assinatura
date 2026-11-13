<?php

declare(strict_types=1);

use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\TrashService;
use App\Folders\FolderService;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function folderSvc(): FolderService
{
    return app(FolderService::class);
}

function folderEnvelope(Tenant $tenant, ?Folder $folder, string $status, bool $archived = false, bool $trashed = false): Envelope
{
    $doc = Document::create(['tenant_id' => $tenant->id, 'name' => 'a.pdf', 'storage_path' => 'documents/'.Str::uuid().'.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);

    return Envelope::create([
        'tenant_id' => $tenant->id, 'document_id' => $doc->id, 'title' => 'Doc '.$status, 'status' => $status,
        'verification_code' => strtoupper(Str::random(12)), 'folder_id' => $folder?->id,
        'archived_at' => $archived ? now() : null, 'trashed_at' => $trashed ? now() : null,
    ]);
}

function folderReason(callable $fn): ?string
{
    try {
        $fn();
    } catch (FlowException $e) {
        return $e->reason;
    }

    return null;
}

it('cria pastas e subpastas até 4 níveis e recusa o 5º', function () {
    $t = Tenant::create(['name' => 'ACME']);
    $a = folderSvc()->create($t, 'A');
    $b = folderSvc()->create($t, 'B', $a);
    $c = folderSvc()->create($t, 'C', $b);
    $d = folderSvc()->create($t, 'D', $c);

    expect(folderSvc()->level($d))->toBe(4)
        ->and(folderReason(fn () => folderSvc()->create($t, 'E', $d)))->toBe('folder_too_deep');
});

it('nome único por pasta pai, sem diferenciar maiúsculas', function () {
    $t = Tenant::create(['name' => 'ACME']);
    $a = folderSvc()->create($t, 'Contratos');
    $other = folderSvc()->create($t, 'Outra');

    expect(folderReason(fn () => folderSvc()->create($t, 'contratos')))->toBe('folder_name_taken');
    folderSvc()->create($t, 'Contratos', $other); // mesmo nome em outra pasta: ok
    $b = folderSvc()->create($t, 'Zeta', $a);
    expect(folderReason(fn () => folderSvc()->rename($b, 'zeta')))->toBeNull(); // renomear para si mesma (só caixa) é permitido
    folderSvc()->create($t, 'Irmã', $a);
    expect(folderReason(fn () => folderSvc()->rename($b, 'IRMÃ')))->toBe('folder_name_taken')
        ->and(folderReason(fn () => folderSvc()->create($t, '   ')))->toBe('invalid_name')
        ->and(folderReason(fn () => folderSvc()->create($t, str_repeat('x', 121))))->toBe('invalid_name');
});

it('o índice único do banco segura a corrida entre duas criações', function () {
    $t = Tenant::create(['name' => 'ACME']);
    $row = fn () => ['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'name' => 'Contratos', 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()];
    DB::table('folders')->insert($row());

    expect(fn () => DB::table('folders')->insert(['name' => 'CONTRATOS'] + $row()))->toThrow(QueryException::class);
});

it('mover recusa ciclo, nível excedido com subpastas junto e pasta de outra empresa', function () {
    $t = Tenant::create(['name' => 'ACME']);
    $a = folderSvc()->create($t, 'A');
    $a1 = folderSvc()->create($t, 'A1', $a);
    $a11 = folderSvc()->create($t, 'A11', $a1);

    expect(folderReason(fn () => folderSvc()->move($a, $a)))->toBe('folder_cycle')
        ->and(folderReason(fn () => folderSvc()->move($a, $a11)))->toBe('folder_cycle');

    // cadeia X1 > X2 > X3 > X4 e uma pasta F com filho G (altura 2)
    $x1 = folderSvc()->create($t, 'X1');
    $x2 = folderSvc()->create($t, 'X2', $x1);
    $x3 = folderSvc()->create($t, 'X3', $x2);
    $x4 = folderSvc()->create($t, 'X4', $x3);
    $f = folderSvc()->create($t, 'F');
    folderSvc()->create($t, 'G', $f);
    expect(folderReason(fn () => folderSvc()->move($f, $x3)))->toBe('folder_too_deep')   // F no nível 4, G no 5
        ->and(folderReason(fn () => folderSvc()->move($f, $x4)))->toBe('folder_too_deep');
    folderSvc()->move($f, $x2);                                                          // F nível 3, G nível 4: cabe
    expect(folderSvc()->level($f->fresh()))->toBe(3);

    $foreign = Folder::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'Alheia']);
    expect(folderReason(fn () => folderSvc()->move($a, $foreign)))->toBe('folder_other_tenant');

    folderSvc()->move($a1->fresh(), null); // para a raiz
    expect($a1->fresh()->parent_id)->toBeNull();

    $dup = folderSvc()->create($t, 'A', $x1); // já existe "A" na raiz
    expect(folderReason(fn () => folderSvc()->move($dup, null)))->toBe('folder_name_taken');
});

it('devolve caminho, nível, descendentes e árvore', function () {
    $t = Tenant::create(['name' => 'ACME']);
    $b = folderSvc()->create($t, 'B');
    $a = folderSvc()->create($t, 'A');
    $a1 = folderSvc()->create($t, 'A1', $a);
    $a2 = folderSvc()->create($t, 'A2', $a1);
    Folder::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'Alheia']);

    expect(folderSvc()->path($a2)->pluck('name')->all())->toBe(['A', 'A1', 'A2'])
        ->and(folderSvc()->level($a))->toBe(1)
        ->and(collect(folderSvc()->descendantIds($a))->sort()->values()->all())->toBe(collect([$a->id, $a1->id, $a2->id])->sort()->values()->all());

    $tree = folderSvc()->tree($t);
    expect(collect($tree)->pluck('name')->all())->toBe(['A', 'B'])
        ->and($tree[0]['children'][0]['name'])->toBe('A1')->and($tree[0]['children'][0]['children'][0]['level'])->toBe(3);
});

/** Pasta A (com subpasta A1) e, em cada uma, 3 documentos que podem ir à lixeira e 5 que não. */
function folderWithDocs(Tenant $t, ?Folder $parent = null): array
{
    $a = folderSvc()->create($t, 'A', $parent);
    $a1 = folderSvc()->create($t, 'A1', $a);
    foreach ([$a, $a1] as $f) {
        foreach (['draft', 'cancelled', 'expired'] as $s) {
            folderEnvelope($t, $f, $s);
        }
        foreach (['sent', 'in_progress', 'completed', 'refused'] as $s) {
            folderEnvelope($t, $f, $s);
        }
        folderEnvelope($t, $f, 'completed', archived: true);
    }

    return [$a, $a1];
}

it('apagar manda para a lixeira o que pode e sobe o resto para a pasta pai', function () {
    Storage::fake('local');
    $t = Tenant::create(['name' => 'ACME']);
    $p = folderSvc()->create($t, 'P');
    [$a, $a1] = folderWithDocs($t, $p);

    $result = folderSvc()->delete($a);

    expect($result)->toBe(['trashed' => 6, 'kept' => 10, 'folders' => 1])
        ->and(Folder::whereIn('id', [$a->id, $a1->id])->count())->toBe(0)
        ->and(Folder::find($p->id))->not->toBeNull();
    $trashed = Envelope::whereNotNull('trashed_at')->pluck('status')->map->value->unique()->sort()->values()->all();
    expect($trashed)->toBe(['cancelled', 'draft', 'expired']);
    expect(Envelope::whereNull('trashed_at')->where('folder_id', $p->id)->count())->toBe(10)
        ->and(Envelope::whereNull('trashed_at')->whereNotNull('archived_at')->where('folder_id', $p->id)->count())->toBe(2);
});

it('apagar uma pasta primária deixa os documentos que ficam sem pasta (raiz)', function () {
    Storage::fake('local');
    $t = Tenant::create(['name' => 'ACME']);
    [$a] = folderWithDocs($t);

    folderSvc()->delete($a);

    expect(Envelope::whereNull('trashed_at')->whereNull('folder_id')->count())->toBe(10);
});

it('os números de impact() batem com o que delete() faz', function () {
    Storage::fake('local');
    $t = Tenant::create(['name' => 'ACME']);
    $p = folderSvc()->create($t, 'Pasta Pai');
    [$a] = folderWithDocs($t, $p);

    $impact = folderSvc()->impact($a);
    $result = folderSvc()->delete($a);

    expect($impact)->toBe(['trash' => 6, 'keep' => 10, 'folders' => 1, 'destination' => 'Pasta Pai'])
        ->and([$result['trashed'], $result['kept'], $result['folders']])->toBe([$impact['trash'], $impact['keep'], $impact['folders']]);
});

it('o destino é null quando a pasta é primária', function () {
    $t = Tenant::create(['name' => 'ACME']);
    $a = folderSvc()->create($t, 'A');

    expect(folderSvc()->impact($a)['destination'])->toBeNull();
});

it('apagar não conta nem mexe em documentos que já estão na lixeira', function () {
    Storage::fake('local');
    $t = Tenant::create(['name' => 'ACME']);
    $a = folderSvc()->create($t, 'A');
    $already = folderEnvelope($t, $a, 'draft', trashed: true);
    $before = $already->fresh()->trashed_at;

    expect(folderSvc()->impact($a))->toMatchArray(['trash' => 0, 'keep' => 0]);
    folderSvc()->delete($a);

    expect($already->fresh()->trashed_at->equalTo($before))->toBeTrue();
});

it('restaurar da lixeira um documento de pasta apagada devolve à raiz', function () {
    Storage::fake('local');
    $t = Tenant::create(['name' => 'ACME']);
    $a = folderSvc()->create($t, 'A');
    $draft = folderEnvelope($t, $a, 'draft');

    folderSvc()->delete($a);
    expect($draft->fresh()->trashed_at)->not->toBeNull();
    app(TrashService::class)->restore($draft->fresh());

    expect($draft->fresh()->trashed_at)->toBeNull()->and($draft->fresh()->folder_id)->toBeNull();
});
