<?php

declare(strict_types=1);

use App\Branding\BrandService;
use App\Branding\Contrast;
use App\Envelopes\EnvelopeService;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function brTenant(bool $feature = true): Tenant
{
    static $n = 0;
    $n++;
    $p = Plan::create(['name' => 'B'.$n, 'slug' => 'b-'.$n, 'active' => true, 'features' => array_fill_keys(Plan::FEATURES, true), 'limits' => []]);
    $p->update(['features' => array_merge($p->features, ['white_label' => $feature])]);
    $t = Tenant::create(['name' => 'Empresa '.$n]);
    app(PlanService::class)->assign($t, $p);

    return $t->fresh();
}

function brUser(Tenant $t, string $role = 'owner'): User
{
    return User::create(['tenant_id' => $t->id, 'name' => 'Pessoa', 'email' => $role.$t->id.'@x.com', 'password' => 'senha-muito-forte', 'role' => $role]);
}

function brPng(int $w = 300, int $h = 120): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 20, 120, 200));
    ob_start();
    imagepng($im);

    return (string) ob_get_clean();
}

function brLogo(?string $bytes = null, string $name = 'logo.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes ?? brPng());
}

it('o cálculo de contraste segue a regra do projeto (≥ 4,5:1) e sugere o tom mais próximo que passa', function () {
    expect(round(Contrast::ratio('#ffffff', '#000000'), 1))->toBe(21.0)
        ->and(Contrast::ratio('#ffffff', '#767676'))->toBeGreaterThanOrEqual(4.5)
        ->and(Contrast::ratio('#ffffff', '#777777'))->toBeLessThan(4.5);

    $fixed = Contrast::nearestAccessible('#ffe600', '#ffffff');
    expect(Contrast::ratio('#ffffff', $fixed))->toBeGreaterThanOrEqual(4.5)->and($fixed)->not->toBe('#ffe600');
    expect(Contrast::nearestAccessible('#0b3d91', '#ffffff'))->toBe('#0b3d91'); // já passa: não mexe
});

it('salva logo reprocessado, cores e nome; cor sem contraste é ajustada e a tela avisa', function () {
    $t = brTenant();
    $this->actingAs(brUser($t));

    $res = $this->put('/configuracoes/marca', ['brand_name' => 'Minha Empresa', 'brand_primary' => '#ffe600', 'brand_accent' => '#0b3d91', 'logo' => brLogo(brPng(1200, 600))])
        ->assertRedirect()->assertSessionHasNoErrors();
    $res->assertSessionHas('warning'); // a cor amarela foi ajustada

    $t = $t->fresh();
    expect($t->brand_name)->toBe('Minha Empresa')->and($t->brand_accent)->toBe('#0b3d91')
        ->and(Contrast::ratio('#ffffff', $t->brand_primary))->toBeGreaterThanOrEqual(4.5)->and($t->brand_primary)->not->toBe('#ffe600');

    $bytes = Storage::disk('local')->get($t->brand_logo_path);
    $info = getimagesizefromstring($bytes);
    expect($info[2])->toBe(IMAGETYPE_PNG)->and($info[0])->toBeLessThanOrEqual(400)->and($info[1])->toBeLessThanOrEqual(160);

    // trocar o logo apaga o anterior
    $old = $t->brand_logo_path;
    $this->put('/configuracoes/marca', ['brand_name' => 'Minha Empresa', 'logo' => brLogo(brPng(200, 200))])->assertSessionHasNoErrors();
    expect(Storage::disk('local')->exists($old))->toBeFalse()->and(Storage::disk('local')->exists($t->fresh()->brand_logo_path))->toBeTrue();
});

it('recusa SVG, arquivo grande demais, arquivo que não é imagem, imagem enorme e cor inválida', function () {
    $t = brTenant();
    $this->actingAs(brUser($t));

    $this->put('/configuracoes/marca', ['logo' => brLogo('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg')])->assertSessionHasErrors('logo');
    $this->put('/configuracoes/marca', ['logo' => UploadedFile::fake()->create('grande.png', 3000, 'image/png')])->assertSessionHasErrors('logo');
    $this->put('/configuracoes/marca', ['logo' => brLogo('isto não é uma imagem', 'logo.png')])->assertSessionHasErrors('logo'); // extensão trocada
    $this->put('/configuracoes/marca', ['logo' => brLogo(brPng(10, 10))])->assertSessionHasErrors('logo');                       // pequena demais
    $this->put('/configuracoes/marca', ['brand_primary' => 'vermelho'])->assertSessionHasErrors('brand_primary');
    $this->put('/configuracoes/marca', ['brand_name' => str_repeat('a', 61)])->assertSessionHasErrors('brand_name');

    expect($t->fresh()->brand_logo_path)->toBeNull();
    expect(Storage::disk('local')->allFiles('brand'))->toBe([]);
});

it('sem o recurso no plano a tela explica e não deixa salvar; só o responsável edita', function () {
    $t = brTenant(false);
    $this->actingAs(brUser($t))->get('/configuracoes/marca')->assertOk()->assertSee('não inclui');
    $this->put('/configuracoes/marca', ['brand_name' => 'X'])->assertForbidden();
    expect($t->fresh()->brand_name)->toBeNull();

    $t2 = brTenant();
    $this->actingAs(brUser($t2, 'member'))->get('/configuracoes/marca')->assertForbidden();
    $this->put('/configuracoes/marca', ['brand_name' => 'X'])->assertForbidden();
});

it('o painel e a página de assinatura usam a marca da empresa certa, nunca a de outra', function () {
    $a = brTenant();
    $b = brTenant();
    app(BrandService::class)->save($a, ['brand_name' => 'Alfa Contratos', 'brand_primary' => '#0b3d91', 'brand_accent' => '#0b3d91'], brLogo());
    app(BrandService::class)->save($b, ['brand_name' => 'Beta Serviços'], null);

    $this->actingAs(brUser($a))->get('/painel')->assertOk()->assertSee('Alfa Contratos')->assertSee('#0b3d91')->assertDontSee('Beta Serviços')
        ->assertSee(route('brand.logo', $a), false);

    $envelope = app(EnvelopeService::class)->create($a, null, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'Contrato', [['name' => 'Ana', 'email' => 'ana@ex.com']]);
    app(EnvelopeService::class)->send($envelope);
    auth()->logout();
    $this->get($envelope->signers()->first()->fresh()->signUrl())->assertOk()->assertSee('Alfa Contratos')->assertSee('#0b3d91')->assertDontSee('Beta Serviços');

    $envB = app(EnvelopeService::class)->create($b, null, "%PDF-1.4\n%%EOF\n", 'b.pdf', 'Outro', [['name' => 'Bia', 'email' => 'bia@ex.com']]);
    app(EnvelopeService::class)->send($envB);
    $this->get($envB->signers()->first()->fresh()->signUrl())->assertOk()->assertSee('Beta Serviços')->assertDontSee('Alfa Contratos');
});

it('o nome da marca é sempre escapado e remover volta ao padrão', function () {
    $t = brTenant();
    $this->actingAs(brUser($t));
    app(BrandService::class)->save($t, ['brand_name' => '<script>alert(1)</script>'], null);

    $this->get('/painel')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);

    $this->delete('/configuracoes/marca')->assertRedirect();
    $t = $t->fresh();
    expect($t->brand_name)->toBeNull()->and($t->brand_logo_path)->toBeNull()->and($t->brand_primary)->toBeNull();
    $this->get('/painel')->assertOk()->assertSee(config('app.name'));
});

it('o logo é público só para quem tem o recurso e nunca entrega o de outra empresa', function () {
    $a = brTenant();
    $semLogo = brTenant();
    $semRecurso = brTenant();
    app(BrandService::class)->save($a, ['brand_name' => 'A'], brLogo());
    $semRecurso->forceFill(['brand_logo_path' => 'brand/'.$semRecurso->id.'/logo-x.png'])->save();
    Storage::disk('local')->put('brand/'.$semRecurso->id.'/logo-x.png', brPng());
    $semRecurso->subscription->update(['overrides' => ['features' => ['white_label' => false]]]);

    $this->get(route('brand.logo', $a))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(route('brand.logo', $semLogo))->assertNotFound();
    $this->get(route('brand.logo', $semRecurso))->assertNotFound();
    $this->get('/marca/'.\Illuminate\Support\Str::uuid().'/logo')->assertNotFound();
});
