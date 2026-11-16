<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SigningService;
use App\Models\Envelope;
use App\Models\SignatureField;
use App\Models\Signer;
use App\Models\User;
use App\Signing\ReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const WZ_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

beforeEach(function () {
    Storage::fake('local');
    Queue::fake([\App\Jobs\SealEnvelope::class]);
});

/**
 * Documento enviado com a Ana (assinatura + visto + 2 textos) e o Beto (assinatura + texto).
 *
 * @return array{0:User,1:Envelope,2:Signer,3:Signer,4:string,5:string} usuário, documento, Ana, Beto, token da Ana, token do Beto
 */
function wzTyped(): array
{
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com'), wzPerson('Beto', 'beto@x.com')]);
    [$ana, $beto] = $e->refresh()->signers()->orderBy('position')->get()->all();
    app(EnvelopeService::class)->syncFields($e, [
        wzField($ana->id, ['type' => 'signature']),
        wzField($ana->id, ['type' => 'initials', 'x' => 0.6, 'y' => 0.5, 'width' => 0.15, 'height' => 0.07]),
        wzField($ana->id, ['type' => 'text', 'y' => 0.6, 'height' => 0.04]),
        wzField($ana->id, ['type' => 'text', 'y' => 0.7, 'height' => 0.04]),
        wzField($beto->id, ['type' => 'signature', 'page' => 2]),
        wzField($beto->id, ['type' => 'text', 'page' => 2, 'y' => 0.6, 'height' => 0.04]),
    ]);
    $tokens = app(EnvelopeService::class)->send($e->refresh());

    return [$u, $e->refresh(), $ana->refresh(), $beto->refresh(), $tokens[$ana->id], $tokens[$beto->id]];
}

function wzTextIds(Signer $s): array
{
    return $s->envelope->signatureFields()->where('signer_id', $s->id)->where('type', 'text')->orderBy('y')->pluck('id')->all();
}

function wzSignBody(Signer $s, array $over = []): array
{
    $ids = wzTextIds($s);

    return $over + [
        'type' => 'typed', 'typed' => $s->name, 'terms' => 1,
        'initials_type' => 'typed', 'initials_typed' => 'AS',
        'texts' => array_combine($ids, array_map(fn ($i) => "Texto {$i}", array_keys($ids))),
    ];
}

it('o assistente aceita os três tipos de campo', function () {
    expect(SignatureField::TYPES)->toBe(['signature', 'initials', 'text']);
});

it('o remetente só pode ter campo de assinatura', function () {
    $u = wzOwner();
    $u->forceFill(['signature_type' => 'typed', 'signature_data' => 'Dono'])->save();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana')], true);
    $sender = $e->refresh()->signers()->where('is_sender', true)->first();
    $svc = app(EnvelopeService::class);

    $svc->syncFields($e, [wzField($sender->id, ['type' => 'signature'])]);
    expect(fn () => $svc->syncFields($e, [wzField($sender->id, ['type' => 'initials'])]))->toThrow(FlowException::class, 'remetente');
    expect(fn () => $svc->syncFields($e, [wzField($sender->id, ['type' => 'text'])]))->toThrow(FlowException::class, 'remetente');
});

it('a tela do signatário pede o visto e cada texto só quando existem esses campos', function () {
    [, , $ana, $beto, $tokenAna, $tokenBeto] = wzTyped();

    $this->get("/assinar/{$tokenAna}")->assertOk()->assertSee('Seu visto')->assertSee('initials_type', false)
        ->assertSee('name="texts['.wzTextIds($ana)[0].']"', false)->assertSee('name="texts['.wzTextIds($ana)[1].']"', false);
    $this->get("/assinar/{$tokenBeto}")->assertOk()->assertDontSee('Seu visto')->assertSee('name="texts['.wzTextIds($beto)[0].']"', false);
});

it('sem o visto não assina e nada é gravado', function () {
    [, $e, $ana, , $token] = wzTyped();
    $body = wzSignBody($ana);
    unset($body['initials_type'], $body['initials_typed']);

    $this->post("/assinar/{$token}/assinar", $body)->assertRedirect()->assertSessionHasErrors();
    expect($ana->fresh()->status->value)->not->toBe('signed')->and($ana->fresh()->signature)->toBeNull();

    expect(fn () => app(SigningService::class)->sign($ana->fresh(), 'typed', 'Ana', true, null, null, array_combine(wzTextIds($ana), ['a', 'b'])))
        ->toThrow(FlowException::class, 'visto');
});

it('visto desenhado inválido ou digitado vazio é recusado', function () {
    [, , $ana, , $token] = wzTyped();

    $this->post("/assinar/{$token}/assinar", wzSignBody($ana, ['initials_type' => 'drawn', 'initials_drawn' => 'não sou imagem']))->assertSessionHasErrors();
    $this->post("/assinar/{$token}/assinar", wzSignBody($ana, ['initials_type' => 'typed', 'initials_typed' => '   ']))->assertSessionHasErrors();
    expect($ana->fresh()->signature)->toBeNull();
});

it('sem preencher todos os textos não assina', function () {
    [, , $ana, , $token] = wzTyped();
    [$t1, $t2] = wzTextIds($ana);

    $this->post("/assinar/{$token}/assinar", wzSignBody($ana, ['texts' => [$t1 => 'só um']]))->assertSessionHasErrors();
    $this->post("/assinar/{$token}/assinar", wzSignBody($ana, ['texts' => [$t1 => 'um', $t2 => '   ']]))->assertSessionHasErrors();
    $this->post("/assinar/{$token}/assinar", wzSignBody($ana, ['texts' => [$t1 => 'um', $t2 => str_repeat('a', 201)]]))->assertSessionHasErrors();
    expect($ana->fresh()->signature)->toBeNull();
});

it('adulterar: texto de campo de outro signatário é ignorado e o próprio continua obrigatório', function () {
    [, $e, $ana, $beto, $token] = wzTyped();
    $betoText = wzTextIds($beto)[0];
    [$t1] = wzTextIds($ana);

    $this->post("/assinar/{$token}/assinar", wzSignBody($ana, ['texts' => [$t1 => 'um', $betoText => 'invasão']]))->assertSessionHasErrors();
    expect(SignatureField::find($betoText)->value)->toBeNull();

    $this->post("/assinar/{$token}/assinar", wzSignBody($ana, ['texts' => array_combine(wzTextIds($ana), ['um', 'dois']) + [$betoText => 'invasão']]))->assertOk();
    expect(SignatureField::find($betoText)->value)->toBeNull()->and($ana->fresh()->status->value)->toBe('signed');
});

it('assinar grava o visto na assinatura e cada texto no seu campo', function () {
    [, $e, $ana, , $token] = wzTyped();
    $ids = wzTextIds($ana);

    $this->post("/assinar/{$token}/assinar", wzSignBody($ana, ['texts' => [$ids[0] => 'Rua das Flores, 10', $ids[1] => ' 12/12/2026 ']]))->assertOk();

    $sig = $ana->fresh()->signature;
    expect($sig->initials_type)->toBe('typed')->and($sig->initials_data)->toBe('AS')
        ->and(SignatureField::find($ids[0])->value)->toBe('Rua das Flores, 10')->and(SignatureField::find($ids[1])->value)->toBe('12/12/2026');
});

it('o visto desenhado é gravado como imagem', function () {
    [, , $ana, , $token] = wzTyped();
    $this->post("/assinar/{$token}/assinar", wzSignBody($ana, ['initials_type' => 'drawn', 'initials_drawn' => WZ_PNG]))->assertOk();

    expect($ana->fresh()->signature->initials_type)->toBe('drawn')->and($ana->fresh()->signature->initials_data)->toBe(WZ_PNG);
});

it('o carimbo recebe os três tipos com o conteúdo de cada um', function () {
    [, $e, $ana, , $token] = wzTyped();
    $ids = wzTextIds($ana);
    $this->post("/assinar/{$token}/assinar", wzSignBody($ana, ['initials_type' => 'drawn', 'initials_drawn' => WZ_PNG, 'texts' => [$ids[0] => 'Linha um', $ids[1] => 'Linha dois']]))->assertOk();

    $placements = collect(app(ReportBuilder::class)->build($e->fresh())['placements'])->groupBy('kind');

    expect($placements->keys()->sort()->values()->all())->toBe(['initials', 'signature', 'text'])
        ->and($placements['text']->pluck('text')->sort()->values()->all())->toBe(['Linha dois', 'Linha um'])
        ->and($placements['initials'][0]['image'])->toBe(substr(WZ_PNG, strlen('data:image/png;base64,')))
        ->and($placements['signature'][0]['name'])->toBe('Ana');
});

it('visto digitado vira texto no carimbo e campo de quem ainda não assinou não aparece', function () {
    [, $e, $ana, , $token] = wzTyped();
    $this->post("/assinar/{$token}/assinar", wzSignBody($ana))->assertOk();

    $placements = collect(app(ReportBuilder::class)->build($e->fresh())['placements']);
    $initials = $placements->firstWhere('kind', 'initials');

    expect($initials['text'])->toBe('AS')->and($initials['image'])->toBeNull()
        ->and($placements->where('page', 2))->toHaveCount(0); // o Beto ainda não assinou
});

it('documentos sem visto nem texto continuam assinando como antes', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com')]);
    app(EnvelopeService::class)->syncFields($e->refresh(), [wzField($e->signers()->first()->id)]);
    $token = array_values(app(EnvelopeService::class)->send($e->refresh()))[0];

    $this->post("/assinar/{$token}/assinar", ['type' => 'typed', 'typed' => 'Ana', 'terms' => 1])->assertOk();
    expect($e->signers()->first()->fresh()->signature->initials_data)->toBeNull();
});
