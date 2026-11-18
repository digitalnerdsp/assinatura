<?php

declare(strict_types=1);

use App\Signing\Exceptions\SigningException;
use App\Signing\Support\SealerClient;
use App\Templates\FillException;
use Illuminate\Http\Client\Factory as Http;

/** @return array{0:SealerClient,1:Http} */
function fillClient($response): array
{
    $http = new Http();
    $http->fake(['sealer/v1/fill' => $response]);

    return [new SealerClient($http, 'http://sealer', 'tok'), $http];
}

const FILL_FIELDS = [['key' => 'nome', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.5, 'height' => 0.05, 'text' => 'Ana', 'multiline' => false]];

it('envia o PDF e as lacunas com o token e devolve o PDF preenchido e o total de páginas', function () {
    [$client, $http] = fillClient(Http::response('%PDF-1.4 preenchido', 200, ['Content-Type' => 'application/pdf', 'X-Page-Count' => '3']));

    $result = $client->fill('%PDF-1.4 original', FILL_FIELDS);

    expect($result['pdf'])->toBe('%PDF-1.4 preenchido')->and($result['pages'])->toBe(3);
    $http->assertSent(fn ($r) => $r->url() === 'http://sealer/v1/fill' && $r->hasHeader('Authorization', 'Bearer tok')
        && str_contains($r->body(), '%PDF-1.4 original') && str_contains($r->body(), '"key":"nome"'));
});

it('lacuna que não cabe vira FillException com a chave do campo', function () {
    [$client, $http] = fillClient(Http::response(['error' => 'does_not_fit', 'field' => 'valor'], 422));

    try {
        $client->fill('%PDF-1.4', FILL_FIELDS);
        $this->fail('esperava FillException');
    } catch (FillException $e) {
        expect($e->fieldKey)->toBe('valor')->and($e->reason)->toBe('does_not_fit');
    }
});

it('PDF inválido vira FillException sem campo', function () {
    [$client, $http] = fillClient(Http::response(['error' => 'invalid_pdf'], 422));

    try {
        $client->fill('lixo', []);
        $this->fail('esperava FillException');
    } catch (FillException $e) {
        expect($e->fieldKey)->toBeNull()->and($e->reason)->toBe('invalid_pdf');
    }
});

it('serviço fora do ar ou com erro de servidor vira SigningException', function () {
    [$client, $http] = fillClient(Http::response('boom', 500));
    expect(fn () => $client->fill('%PDF-1.4', FILL_FIELDS))->toThrow(SigningException::class);

    [$client, $http] = fillClient(fn () => throw new \Illuminate\Http\Client\ConnectionException('recusou'));
    expect(fn () => $client->fill('%PDF-1.4', FILL_FIELDS))->toThrow(SigningException::class, 'indisponível');
});
