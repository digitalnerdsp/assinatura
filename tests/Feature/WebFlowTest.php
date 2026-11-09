<?php

declare(strict_types=1);

use App\Mail\OtpCodeMail;
use App\Mail\SignatureRequestedMail;
use App\Models\Envelope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function pdfFile(string $name = 'contrato.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}

function registerUser(string $email = 'dono@acme.com', string $company = 'ACME'): User
{
    test()->post('/cadastro', [
        'company' => $company, 'name' => 'Dono', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', $email)->firstOrFail();
}

function createEnvelopeViaWeb(string $level = 'simple'): Envelope
{
    test()->post('/envelopes', [
        'title' => 'Contrato de serviço', 'document' => pdfFile(), 'signing_order' => 'parallel', 'required_level' => $level,
        'signers' => [['name' => 'Ana', 'email' => 'ana@ex.com'], ['name' => 'Beto', 'email' => 'beto@ex.com']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    return Envelope::latest()->firstOrFail();
}

it('cadastra empresa, cria e envia um envelope pelo painel', function () {
    Storage::fake('local');
    Mail::fake();
    registerUser();

    $envelope = createEnvelopeViaWeb();
    $this->get("/envelopes/{$envelope->id}")->assertOk()->assertSee('Contrato de serviço')->assertSee('cadeia íntegra');

    $this->post("/envelopes/{$envelope->id}/enviar")->assertRedirect();

    Mail::assertSent(SignatureRequestedMail::class, 2);
    expect($envelope->fresh()->status->value)->toBe('sent');
    $this->get('/painel')->assertOk()->assertSee('Contrato de serviço');
});

it('um tenant não enxerga envelopes de outro', function () {
    Storage::fake('local');
    registerUser('a@a.com', 'A');
    $envelope = createEnvelopeViaWeb();
    $this->post('/sair');

    registerUser('b@b.com', 'B');
    $this->get("/envelopes/{$envelope->id}")->assertNotFound();
    $this->get("/envelopes/{$envelope->id}/original")->assertNotFound();
    $this->post("/envelopes/{$envelope->id}/enviar")->assertNotFound();
});

it('rotas do painel exigem login', function () {
    $this->get('/painel')->assertRedirect('/entrar');
});

it('signatário assina pelo link com verificação por código (nível avançado)', function () {
    Storage::fake('local');
    Mail::fake();
    Queue::fake();
    registerUser();
    $envelope = createEnvelopeViaWeb('advanced');
    $this->post("/envelopes/{$envelope->id}/enviar");
    $this->post('/sair');

    $urls = [];
    Mail::assertSent(SignatureRequestedMail::class, function ($m) use (&$urls) {
        $urls[$m->signer->email] = $m->url;

        return true;
    });
    $ana = $urls['ana@ex.com'];

    $this->get($ana)->assertOk()->assertSee('Confirme sua identidade');
    $this->get($ana.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // sem código, a assinatura é recusada
    $this->post($ana.'/assinar', ['type' => 'typed', 'typed' => 'Ana', 'terms' => 1])->assertSessionHasErrors('flow');

    $this->post($ana.'/codigo')->assertRedirect();
    $code = null;
    Mail::assertSent(OtpCodeMail::class, function ($m) use (&$code) {
        $code = $m->code;

        return true;
    });

    $wrong = $code === '000000' ? '111111' : '000000';
    $this->post($ana.'/codigo/confirmar', ['code' => $wrong])->assertSessionHasErrors('flow');
    $this->post($ana.'/codigo/confirmar', ['code' => $code])->assertRedirect();

    $this->get($ana)->assertOk()->assertSee('Assinar documento');
    $this->post($ana.'/assinar', ['type' => 'typed', 'typed' => 'Ana', 'terms' => 1])->assertOk()->assertSee('Documento assinado');

    // não pode assinar de novo
    $this->get($ana)->assertOk()->assertSee('Link indisponível');
});

it('exige aceite dos termos e permite recusar', function () {
    Storage::fake('local');
    Mail::fake();
    registerUser();
    $envelope = createEnvelopeViaWeb();
    $this->post("/envelopes/{$envelope->id}/enviar");
    $this->post('/sair');

    $url = null;
    Mail::assertSent(SignatureRequestedMail::class, function ($m) use (&$url) {
        $url ??= $m->url;

        return true;
    });

    $this->post($url.'/assinar', ['type' => 'typed', 'typed' => 'Ana'])->assertSessionHasErrors('terms');
    $this->post($url.'/recusar', ['reason' => 'Valores incorretos'])->assertOk()->assertSee('Assinatura recusada');
    expect($envelope->fresh()->status->value)->toBe('refused');
});

it('token inválido mostra página de erro sem vazar informações', function () {
    $this->get('/assinar/token-que-nao-existe')->assertOk()->assertSee('Link de assinatura inválido');
});

it('verificação pública confere o hash do arquivo', function () {
    Storage::fake('local');
    registerUser();
    $envelope = createEnvelopeViaWeb();
    $this->post('/sair');

    $this->get("/verificar/{$envelope->verification_code}")->assertOk()->assertSee('Contrato de serviço');

    $original = Storage::disk('local')->get($envelope->document->storage_path);
    $this->post("/verificar/{$envelope->verification_code}", [
        'file' => UploadedFile::fake()->createWithContent('x.pdf', $original),
    ])->assertOk()->assertSee('idêntico ao documento original');

    $this->post("/verificar/{$envelope->verification_code}", [
        'file' => UploadedFile::fake()->createWithContent('x.pdf', $original.'adulterado'),
    ])->assertOk()->assertSee('NÃO corresponde');

    $this->get('/verificar/CODIGO-INEXISTENTE')->assertNotFound();
});
