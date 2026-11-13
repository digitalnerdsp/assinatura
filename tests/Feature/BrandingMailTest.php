<?php

declare(strict_types=1);

use App\Branding\BrandService;
use App\Envelopes\EnvelopeService;
use App\Mail\OtpCodeMail;
use App\Mail\SignatureRequestedMail;
use App\Models\Plan;
use App\Models\Tenant;
use App\Plans\PlanService;
use App\Signing\ReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function bmTenant(bool $feature = true): Tenant
{
    static $n = 0;
    $n++;
    $p = Plan::create(['name' => 'M'.$n, 'slug' => 'm-'.$n, 'active' => true, 'features' => array_merge(array_fill_keys(Plan::FEATURES, true), ['white_label' => $feature])]);
    $t = Tenant::create(['name' => 'Razão Social '.$n.' Ltda']);
    app(PlanService::class)->assign($t, $p);

    return $t->fresh();
}

function bmSigner(Tenant $t)
{
    $e = app(EnvelopeService::class)->create($t, null, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'Contrato X', [['name' => 'Ana', 'email' => 'ana@ex.com']]);
    app(EnvelopeService::class)->send($e);

    return $e->signers()->first()->fresh();
}

function bmBrand(Tenant $t): void
{
    $im = imagecreatetruecolor(300, 120);
    imagefill($im, 0, 0, imagecolorallocate($im, 10, 100, 200));
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();
    app(BrandService::class)->save($t, ['brand_name' => 'Marca da Empresa', 'brand_primary' => '#0b3d91', 'brand_accent' => '#0b3d91'], UploadedFile::fake()->createWithContent('logo.png', $png));
}

it('os e-mails de convite e de código levam nome, logo e cor da empresa, com o botão em contraste', function () {
    $t = bmTenant();
    bmBrand($t);
    $signer = bmSigner($t->fresh());

    $html = (new SignatureRequestedMail($signer, 'https://x.test/assinar/abc'))->render();
    expect($html)->toContain('Marca da Empresa')->toContain(route('brand.logo', $t), )->toContain('background:#0b3d91')->toContain('https://x.test/assinar/abc');

    $otp = (new OtpCodeMail($signer, '123456'))->render();
    expect($otp)->toContain('Marca da Empresa')->toContain('123456');

    $lembrete = (new SignatureRequestedMail($signer, 'https://x.test/assinar/abc', true))->render();
    expect($lembrete)->toContain('Marca da Empresa')->toContain('lembrete');
});

it('sem marca ou sem o recurso no plano, os e-mails ficam como sempre foram', function () {
    $semMarca = bmTenant();
    $s1 = bmSigner($semMarca);
    $semRecurso = bmTenant(false);
    bmBrand($semRecurso);
    $semRecurso->subscription->update(['overrides' => ['features' => ['white_label' => false]]]);
    $s2 = bmSigner($semRecurso->fresh());

    foreach ([$s1, $s2] as $s) {
        $html = (new SignatureRequestedMail($s, 'https://x.test/assinar/abc'))->render();
        expect($html)->not->toContain('Marca da Empresa')->not->toContain('/marca/')->not->toContain('background:#0b3d91')
            ->toContain('Abrir e assinar o documento');
    }
});

it('o relatório do PDF leva a marca (nome, cor e logo em base64) só quando a empresa tem marca ativa', function () {
    $t = bmTenant();
    bmBrand($t);
    $e = app(EnvelopeService::class)->create($t->fresh(), null, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'Contrato', [['name' => 'Ana', 'email' => 'ana@ex.com']]);

    $report = (new ReportBuilder())->build($e->fresh());
    expect($report['tenant'])->toBe($t->name) // "Emitido por" continua sendo a razão social
        ->and($report['brand']['name'])->toBe('Marca da Empresa')->and($report['brand']['color'])->toBe('#0b3d91')
        ->and(substr(base64_decode($report['brand']['logo']), 0, 4))->toBe("\x89PNG");

    $plain = bmTenant();
    $e2 = app(EnvelopeService::class)->create($plain, null, "%PDF-1.4\n%%EOF\n", 'b.pdf', 'Outro', [['name' => 'Bia', 'email' => 'bia@ex.com']]);
    expect((new ReportBuilder())->build($e2->fresh())['brand'])->toBeNull();
});

it('se o arquivo do logo sumiu, o relatório sai sem logo e sem erro', function () {
    $t = bmTenant();
    bmBrand($t);
    Storage::disk('local')->delete($t->fresh()->brand_logo_path);
    $e = app(EnvelopeService::class)->create($t->fresh(), null, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'Contrato', [['name' => 'Ana', 'email' => 'ana@ex.com']]);

    $report = (new ReportBuilder())->build($e->fresh());
    expect($report['brand']['name'])->toBe('Marca da Empresa')->and($report['brand']['logo'])->toBeNull();
});
