<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Envelopes\SigningService;
use App\Evidence\EvidenceLogger;
use App\Jobs\SealEnvelope;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Signing\Contracts\SigningProvider;
use App\Signing\Data\CertificateInfo;
use App\Signing\Data\SealedDocument;
use App\Signing\Data\SealRequest;
use App\Signing\ReportBuilder;
use App\Signing\SigningManager;
use App\Signing\Support\SealerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

function jpegBytes(): string
{
    $im = imagecreatetruecolor(320, 320);
    ob_start();
    imagejpeg($im);

    return (string) ob_get_clean();
}

function fullyVerifiedEnvelope(): Envelope
{
    Storage::fake('local');
    Queue::fake();
    $tenant = Tenant::create(['name' => 'ACME Ltda']);
    $svc = app(EnvelopeService::class);
    $envelope = $svc->create($tenant, null, "%PDF-1.4\n%%EOF\n", 'contrato.pdf', 'Contrato X',
        [['name' => 'Ana Souza', 'email' => 'ana@ex.com', 'cpf' => '123.456.789-09']],
        verifications: ['otp' => true, 'selfie' => true, 'document' => true, 'geolocation' => true]);
    $svc->send($envelope);

    $signing = app(SigningService::class);
    $signer = $envelope->signers()->first();
    $ctx = new App\Evidence\RequestContext('203.0.113.9', 'Mozilla/5.0 (Android) Teste');

    foreach (range(1, 3) as $_) {
        $signing->view($signer->fresh(), $ctx); // visualizações repetidas
    }
    $code = $signing->requestOtp($signer->fresh(), 'email', $ctx);
    $signing->verifyOtp($signer->fresh(), $code, $ctx);
    $signing->submitSelfie($signer->fresh(), jpegBytes(), 'camera', $ctx);
    $signing->submitDocument($signer->fresh(), jpegBytes(), 'upload', $ctx);
    $signing->recordGeolocation($signer->fresh(), -23.496284, -47.430349, 13.9, $ctx);
    $signing->sign($signer->fresh(), 'drawn', 'data:image/png;base64,AAAA', true, $ctx);

    return $envelope->fresh();
}

it('monta os dados do relatório com identificação do signatário', function () {
    $report = (new ReportBuilder())->build(fullyVerifiedEnvelope());

    expect($report['tenant'])->toBe('ACME Ltda')
        ->and($report['code'])->toHaveLength(12)
        ->and($report['url'])->toContain('/verificar/'.$report['code'])
        ->and($report['document_sha256'])->toHaveLength(64)
        ->and($report['chain_hash'])->toHaveLength(64);

    $s = $report['signers'][0];
    expect($s['name'])->toBe('Ana Souza')
        ->and($s['status'])->toBe('Assinado')
        ->and($s['ip'])->toBe('203.0.113.9')
        ->and($s['device'])->toContain('Mozilla/5.0')
        ->and($s['signature'])->toBe('Desenhada - nível avançado')
        ->and($s['checks'])->toContain('Código por e-mail', 'Selfie (câmera)', 'Localização')
        ->and($s['geo'])->toBe('-23.496, -47.430 (±13.9 m)');
});

it('protege dados sensíveis: CPF mascarado e nenhuma imagem no relatório', function () {
    $report = (new ReportBuilder())->build(fullyVerifiedEnvelope());
    $json = json_encode($report);

    expect($report['signers'][0]['cpf'])->toBe('***.456.789-**')
        ->and($json)->not->toContain('123.456.789-09')
        ->and($json)->not->toContain('base64')
        ->and($json)->not->toContain('-23.496284'); // localização arredondada
});

it('resume visualizações repetidas em uma linha e mantém a ordem dos eventos', function () {
    $events = (new ReportBuilder())->build(fullyVerifiedEnvelope())['events'];
    $views = array_values(array_filter($events, fn ($e) => str_starts_with($e['label'], 'Documento visualizado')));

    expect($views)->toHaveCount(1)->and($views[0]['label'])->toBe('Documento visualizado (3 vezes)');
    expect(array_column($events, 'seq'))->toBe(array_values(collect(array_column($events, 'seq'))->sort()->all()));
});

it('a selagem envia o relatório ao provider e registra se foi anexado', function () {
    $envelope = fullyVerifiedEnvelope();
    $captured = null;

    $provider = new class($captured) implements SigningProvider {
        public function __construct(private &$captured)
        {
        }

        public function name(): string { return 'fake'; }
        public function isAvailable(): bool { return true; }
        public function certificate(): CertificateInfo
        {
            return new CertificateInfo('S1', 'AC', 'CN=X', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 year'), false);
        }
        public function seal(SealRequest $request): SealedDocument
        {
            $this->captured = $request;

            return new SealedDocument($request->pdf.'%sealed', hash('sha256', $request->pdf.'%sealed'), 'fake', $this->certificate(), new DateTimeImmutable(), false, decorated: false);
        }
    };

    $manager = new SigningManager(new SealerClient(new Http(), 'http://s', 't'), new NullLogger(),
        ['platform_provider' => 'fake', 'allow_local_test' => false], fn () => $provider);

    (new SealEnvelope($envelope->id))->handle($manager, app(EvidenceLogger::class));

    expect($captured->report['code'])->toBe($envelope->verification_code)
        ->and($captured->report['signers'])->toHaveCount(1);

    $sealedEvent = $envelope->evidenceEvents()->where('type', 'envelope_sealed')->first();
    expect($sealedEvent->payload['report_attached'])->toBeFalse();
});
