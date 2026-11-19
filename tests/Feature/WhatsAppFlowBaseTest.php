<?php

declare(strict_types=1);

use App\Assistant\Flow\FlowStore;
use App\Assistant\Flow\SignerLine;
use App\Assistant\ToolGroups;
use App\Documents\DocumentIntake;
use App\Envelopes\Exceptions\FlowException;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-12-10 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

// ------------------------------------------------------------------ "Nome e-mail"

it('lê nome e e-mail em várias ordens e formatos', function () {
    expect(SignerLine::parse('Maria Souza maria@email.com'))->toBe(['name' => 'Maria Souza', 'email' => 'maria@email.com'])
        ->and(SignerLine::parse('maria@email.com Maria Souza'))->toBe(['name' => 'Maria Souza', 'email' => 'maria@email.com'])
        ->and(SignerLine::parse('Maria Souza <Maria@Email.com>'))->toBe(['name' => 'Maria Souza', 'email' => 'maria@email.com'])
        ->and(SignerLine::parse('Maria Souza, maria@email.com'))->toBe(['name' => 'Maria Souza', 'email' => 'maria@email.com'])
        ->and(SignerLine::parse('  Maria   Souza   -  maria@email.com  '))->toBe(['name' => 'Maria Souza', 'email' => 'maria@email.com']);
});

it('só o e-mail usa a parte antes do @ como nome', function () {
    expect(SignerLine::parse('joao.silva@empresa.com.br'))->toBe(['name' => 'Joao Silva', 'email' => 'joao.silva@empresa.com.br']);
});

it('sem e-mail devolve só o nome; vazio ou lixo devolve null', function () {
    expect(SignerLine::parse('Maria Souza'))->toBe(['name' => 'Maria Souza', 'email' => null])
        ->and(SignerLine::parse('   '))->toBeNull()
        ->and(SignerLine::parse(''))->toBeNull()
        ->and(SignerLine::parse('@@@ ...'))->toBeNull();
});

it('e-mail inválido não vira e-mail, e nome gigante é recusado', function () {
    expect(SignerLine::parse('Maria maria@@email')['email'])->toBeNull()
        ->and(SignerLine::parse(str_repeat('a', 200).' a@b.com'))->toBeNull()
        ->and(SignerLine::parse("Maria\nSouza maria@email.com")['name'])->toBe('Maria Souza'); // quebra de linha vira espaço
});

it('mais de um e-mail na mesma mensagem é recusado (uma pessoa por mensagem)', function () {
    expect(SignerLine::parse('a@x.com b@y.com'))->toBeNull();
});

// ------------------------------------------------------------------ arquivo

it('fromBytes aceita PDF e recusa o que não é PDF nem DOCX', function () {
    $r = app(DocumentIntake::class)->fromBytes('%PDF-1.4 conteúdo', 'contrato.pdf');

    expect($r['pdf'])->toBe('%PDF-1.4 conteúdo')->and($r['name'])->toBe('contrato.pdf')->and($r['source_sha256'])->toBeNull();
    expect(fn () => app(DocumentIntake::class)->fromBytes('MZ binário', 'virus.pdf'))->toThrow(FlowException::class);
    expect(fn () => app(DocumentIntake::class)->fromBytes('PK zip qualquer', 'planilha.xlsx'))->toThrow(FlowException::class);
});

// ------------------------------------------------------------------ permissão do grupo

it('o grupo de envio existe, vem ligado por padrão e a empresa pode desligar', function () {
    expect(ToolGroups::GROUPS)->toHaveKey('send_documents');
    $t = Tenant::create(['name' => 'X']);

    expect(ToolGroups::allows($t, 'send_documents'))->toBeTrue();

    $t->forceFill(['assistant_tools' => ['consult']])->save();
    expect(ToolGroups::allows($t->refresh(), 'send_documents'))->toBeFalse();

    $t->forceFill(['assistant_tools' => ['consult', 'send_documents']])->save();
    expect(ToolGroups::allows($t->refresh(), 'send_documents'))->toBeTrue();
});

it('o grupo de envio não libera nenhuma ferramenta de IA', function () {
    expect(ToolGroups::GROUPS['send_documents']['tools'])->toBe([]);
});

// ------------------------------------------------------------------ estado do fluxo

function wfUser(): User
{
    static $n = 0;
    $n++;
    $t = Tenant::create(['name' => 'Empresa WF '.$n]);

    return User::create(['tenant_id' => $t->id, 'name' => 'Ana', 'email' => "ana{$n}@wf.test", 'password' => 'senha-muito-forte', 'role' => 'owner']);
}

it('abre um fluxo por usuário, avança e limpa', function () {
    $u = wfUser();
    $s = app(FlowStore::class);

    expect($s->current($u))->toBeNull();

    $f = $s->open($u, 'title', ['envelope_id' => 'abc', 'filename' => 'c.pdf']);
    expect($s->current($u)->step)->toBe('title')->and($s->current($u)->data['envelope_id'])->toBe('abc');

    $s->advance($f, 'signers', ['title' => 'Contrato']);
    $cur = $s->current($u);
    expect($cur->step)->toBe('signers')->and($cur->data)->toMatchArray(['envelope_id' => 'abc', 'title' => 'Contrato']);

    $s->open($u, 'title', ['envelope_id' => 'novo']); // abrir de novo substitui (um fluxo por usuário)
    expect(\App\Models\WhatsAppFlow::query()->where('user_id', $u->id)->count())->toBe(1)->and($s->current($u)->data['envelope_id'])->toBe('novo');

    $s->clear($u);
    expect($s->current($u))->toBeNull();
});

it('o fluxo expira em 30 minutos e cada passo renova o prazo', function () {
    $u = wfUser();
    $s = app(FlowStore::class);
    $f = $s->open($u, 'signers', []);

    CarbonImmutable::setTestNow('2026-12-10 12:20:00');
    $s->advance($f->refresh(), 'signers', ['x' => 1]); // renova
    CarbonImmutable::setTestNow('2026-12-10 12:45:00');
    expect($s->current($u))->not->toBeNull(); // 25 min desde a última ação

    CarbonImmutable::setTestNow('2026-12-10 13:30:00');
    expect($s->current($u))->toBeNull(); // passou de 30 min
});

it('um usuário não enxerga o fluxo de outro', function () {
    $a = wfUser();
    $b = wfUser();
    app(FlowStore::class)->open($a, 'title', []);

    expect(app(FlowStore::class)->current($b))->toBeNull();
});

it('a limpeza diária apaga conversas de envio abandonadas há mais de um dia', function () {
    $old = wfUser();
    $recent = wfUser();
    $f = app(FlowStore::class)->open($old, 'signers', []);
    $f->forceFill(['expires_at' => now()->subDays(2)])->save();
    app(FlowStore::class)->open($recent, 'signers', []);

    $r = app(\App\Assistant\AssistantPruner::class)->run();

    expect($r['flows'])->toBe(1)->and(\App\Models\WhatsAppFlow::query()->count())->toBe(1);
});
