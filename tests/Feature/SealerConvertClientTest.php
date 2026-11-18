<?php

declare(strict_types=1);

use App\Documents\ConvertException;
use App\Signing\Exceptions\SigningException;
use App\Signing\Support\SealerClient;
use Illuminate\Http\Client\Factory as Http;

function convertClient($response): array
{
    $http = new Http();
    $http->fake(['sealer/v1/convert' => $response]);

    return [new SealerClient($http, 'http://sealer', 'tok'), $http];
}

it('envia o DOCX com o token e devolve o PDF convertido', function () {
    [$client, $http] = convertClient(Http::response('%PDF-1.7 convertido', 200, ['Content-Type' => 'application/pdf']));

    expect($client->convert('PKdocx'))->toBe('%PDF-1.7 convertido');
    $http->assertSent(fn ($r) => $r->url() === 'http://sealer/v1/convert' && $r->hasHeader('Authorization', 'Bearer tok') && str_contains($r->body(), 'PKdocx'));
});

it('recusa do selador vira ConvertException com o motivo', function () {
    foreach (['invalid_docx', 'timeout', 'convert_failed'] as $reason) {
        [$client] = convertClient(Http::response(['error' => $reason], 422));
        try {
            $client->convert('PKx');
            $this->fail('esperava ConvertException');
        } catch (ConvertException $e) {
            expect($e->reason)->toBe($reason);
        }
    }
});

it('selador sem LibreOffice ou fora do ar vira SigningException', function () {
    [$client] = convertClient(Http::response(['error' => 'unavailable'], 503));
    expect(fn () => $client->convert('PKx'))->toThrow(SigningException::class);

    $http = new Http();
    $http->fake(['sealer/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: sealer')]);
    expect(fn () => (new SealerClient($http, 'http://sealer', 'tok'))->convert('PKx'))->toThrow(SigningException::class);
});
