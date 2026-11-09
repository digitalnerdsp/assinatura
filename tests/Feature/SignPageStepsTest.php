<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('a página guia o signatário passo a passo: selfie, documento, localização e assinatura', function () {
    Storage::fake('local');
    Queue::fake();
    $tenant = Tenant::create(['name' => 'ACME']);
    $svc = app(EnvelopeService::class);
    $envelope = $svc->create($tenant, null, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'Contrato',
        [['name' => 'Ana', 'email' => 'ana@ex.com']],
        verifications: ['selfie' => true, 'document' => true, 'geolocation' => true]);
    $token = array_values($svc->send($envelope))[0];
    $base = "/assinar/{$token}";

    $this->get($base)->assertOk()->assertSee('Tire uma selfie')->assertSee('Foto do documento')->assertDontSee('Assinar documento');

    // assinar sem concluir as etapas é recusado
    $this->post("$base/assinar", ['type' => 'typed', 'typed' => 'Ana', 'terms' => 1])->assertSessionHasErrors('flow');

    $im = imagecreatetruecolor(400, 400);
    ob_start(); imagejpeg($im); $jpeg = ob_get_clean();

    $this->post("$base/selfie", ['image' => 'data:image/jpeg;base64,'.base64_encode($jpeg), 'source' => 'camera'])->assertRedirect($base);
    $this->get($base)->assertOk()->assertSee('Foto do documento')->assertDontSee('Tire uma selfie</h2>', false);

    $this->post("$base/documento", ['image' => UploadedFile::fake()->createWithContent('rg.jpg', $jpeg)])->assertRedirect($base);
    $this->post("$base/localizacao", ['lat' => -23.55, 'lng' => -46.63, 'accuracy' => 20])->assertRedirect($base);

    $this->get($base)->assertOk()->assertSee('Assinar documento')->assertSee('Desenhar');
    $this->post("$base/assinar", ['type' => 'typed', 'typed' => 'Ana', 'terms' => 1])->assertOk()->assertSee('Documento assinado');
});

it('rejeita selfie com conteúdo que não é imagem', function () {
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'ACME']);
    $svc = app(EnvelopeService::class);
    $envelope = $svc->create($tenant, null, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'C', [['name' => 'Ana', 'email' => 'ana@ex.com']], verifications: ['selfie' => true]);
    $token = array_values($svc->send($envelope))[0];

    $this->post("/assinar/{$token}/selfie", ['image' => 'data:image/jpeg;base64,'.base64_encode('isto não é imagem')])
        ->assertSessionHasErrors('flow');
});
