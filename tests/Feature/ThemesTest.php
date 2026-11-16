<?php

declare(strict_types=1);

use App\Envelopes\Exceptions\FlowException;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\Theme;
use App\Models\User;
use App\Themes\ThemeInstaller;
use App\Themes\ThemeManager;
use App\Themes\ThemeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function thZip(array $override = [], array $remove = []): string
{
    $files = array_merge([
        'theme.json' => json_encode(['name' => 'Tema teste', 'slug' => 'tema-teste', 'version' => '1.0.0', 'contract' => 1, 'author' => 'Equipe']),
        'layouts/app.blade.php' => "<!doctype html><html><body><main>@yield('content')</main></body></html>",
        'pages/dashboard.blade.php' => "@extends('theme::layouts.app')\n@section('content')<p>Total {{ \$kpi['total'] ?? 0 }}</p>@endsection",
        'assets/style.css' => 'body{margin:0}',
    ], $override);
    foreach ($remove as $r) {
        unset($files[$r]);
    }
    $path = tempnam(sys_get_temp_dir(), 'th').'.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    return $path;
}

function thUser(string $email, ?string $platformRole = null): User
{
    $u = User::create(['tenant_id' => Tenant::create(['name' => 'Empresa '.$email])->id, 'name' => 'P '.$email, 'email' => $email, 'password' => 'senha-muito-forte', 'role' => 'owner']);
    if ($platformRole !== null) {
        $u->forceFill(['platform_role' => $platformRole])->save();
    }

    return $u->fresh();
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/themes-test-'.bin2hex(random_bytes(4));
    config(['themes.path' => $this->dir]);
    config(['platform.super_admins' => []]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('aceita um pacote válido', function () {
    expect((new ThemeValidator())->validate(thZip()))->toBe([]);
});

it('recusa código executável, caminhos perigosos e arquivos proibidos', function (array $override, string $needle) {
    $problems = (new ThemeValidator())->validate(thZip($override));
    expect(implode(' | ', $problems))->toContain($needle);
})->with([
    'diretiva @php' => [['pages/dashboard.blade.php' => "@php(\$x = 1)\n@extends('theme::layouts.app')"], '@php'],
    'tag php' => [['layouts/app.blade.php' => '<?php echo 1; ?>'], '<?php'],
    'função perigosa' => [['layouts/app.blade.php' => '{{ system("ls") }}'], 'system'],
    'saída sem escape' => [['layouts/app.blade.php' => '{!! $x !!}'], '{!!'],
    'include de fora' => [['layouts/app.blade.php' => "@include('layouts.legacy')"], '@include'],
    'acesso a classe do sistema' => [['layouts/app.blade.php' => '{{ \App\Models\User::count() }}'], '\App\\'],
    'arquivo php' => [['assets/x.php' => '<?php'], 'x.php'],
    'path traversal' => [['../fora.css' => 'a{}'], '..'],
    'extensão fora da lista' => [['assets/x.exe' => 'MZ'], 'x.exe'],
    'js com eval' => [['assets/a.js' => 'eval("1")'], 'eval'],
    'theme.json inválido' => [['theme.json' => '{"name":"X"}'], 'theme.json'],
    'slug inválido' => [['theme.json' => json_encode(['name' => 'X', 'slug' => '../x', 'version' => '1.0.0', 'contract' => 1])], 'slug'],
    'contrato não suportado' => [['theme.json' => json_encode(['name' => 'X', 'slug' => 'x', 'version' => '1.0.0', 'contract' => 99])], 'contrato'],
]);

it('recusa pacote sem layout ou sem painel e zip grande demais', function () {
    expect(implode(' ', (new ThemeValidator())->validate(thZip([], ['layouts/app.blade.php']))))->toContain('layouts/app.blade.php');
    expect(implode(' ', (new ThemeValidator())->validate(thZip([], ['pages/dashboard.blade.php']))))->toContain('pages/dashboard.blade.php');
    config(['themes.max_zip_mb' => 0.0001]);
    expect(implode(' ', (new ThemeValidator())->validate(thZip(['assets/big.css' => str_repeat('a', 5000)]))))->toContain('grande');
});

it('instala em quarentena, registra e não repete a mesma versão', function () {
    $by = thUser('s@x.com', 'super_admin');
    $theme = app(ThemeInstaller::class)->install($by, thZip());

    expect($theme->slug)->toBe('tema-teste')->and($theme->version)->toBe('1.0.0')->and($theme->status)->toBe('installed')
        ->and(File::exists($this->dir.'/tema-teste/1.0.0/layouts/app.blade.php'))->toBeTrue()
        ->and(File::exists($this->dir.'/tema-teste/1.0.0/theme.json'))->toBeTrue();

    expect(fn () => app(ThemeInstaller::class)->install($by, thZip()))->toThrow(FlowException::class);
});

it('não instala pacote recusado e não deixa sobras', function () {
    $by = thUser('s@x.com', 'super_admin');
    expect(fn () => app(ThemeInstaller::class)->install($by, thZip(['layouts/app.blade.php' => '<?php echo 1;'])))->toThrow(FlowException::class);
    expect(Theme::count())->toBe(0)->and(File::exists($this->dir.'/tema-teste'))->toBeFalse();
});

it('ativa o tema, usa o layout dele e volta ao padrão', function () {
    $by = thUser('s@x.com', 'super_admin');
    $m = app(ThemeManager::class);
    expect($m->layout())->toBe('layouts.legacy')->and($m->page('dashboard'))->toBe('dashboard');

    $theme = app(ThemeInstaller::class)->install($by, thZip());
    $m->activate($theme);
    expect(Setting::get('active_theme'))->toBe($theme->getKey());

    $m = new ThemeManager();
    expect($m->layout())->toBe('theme::layouts.app')->and($m->page('dashboard'))->toBe('theme::pages.dashboard');

    $m->reset();
    expect((new ThemeManager())->layout())->toBe('layouts.legacy');
});

it('recusa ativar tema que não renderiza e marca erro', function () {
    $by = thUser('s@x.com', 'super_admin');
    $theme = app(ThemeInstaller::class)->install($by, thZip(['layouts/app.blade.php' => "<main>@yield('content')</main>@include('theme::nao-existe')"]));

    expect(fn () => app(ThemeManager::class)->activate($theme))->toThrow(FlowException::class);
    expect(Setting::get('active_theme'))->toBeNull()->and($theme->fresh()->status)->toBe('error');
});

it('volta ao padrão se a pasta do tema ativo sumiu', function () {
    $by = thUser('s@x.com', 'super_admin');
    $theme = app(ThemeInstaller::class)->install($by, thZip());
    app(ThemeManager::class)->activate($theme);
    File::deleteDirectory($this->dir.'/tema-teste');

    expect((new ThemeManager())->layout())->toBe('layouts.legacy');
});

it('serve só assets permitidos do tema', function () {
    $by = thUser('s@x.com', 'super_admin');
    app(ThemeInstaller::class)->install($by, thZip());

    $r = $this->get('/temas/tema-teste/1.0.0/assets/style.css');
    $r->assertOk();
    expect($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($r->headers->get('Content-Type'))->toContain('text/css');
    $this->get('/temas/tema-teste/1.0.0/layouts/app.blade.php')->assertNotFound();
    $this->get('/temas/tema-teste/1.0.0/theme.json')->assertNotFound();
    $this->get('/temas/tema-teste/1.0.0/assets/../theme.json')->assertNotFound();
    $this->get('/temas/..%2F/1.0.0/assets/style.css')->assertNotFound();
});

it('só super admin acessa a central de temas', function () {
    $this->actingAs(thUser('a@x.com', 'admin'))->get('/admin/temas')->assertForbidden();
    $this->actingAs(thUser('c@x.com'))->get('/admin/temas')->assertNotFound();
    $this->actingAs(thUser('s@x.com', 'super_admin'))->get('/admin/temas')->assertOk()->assertSee('Temas');
});

it('envia, ativa, volta ao padrão e remove pela central', function () {
    $super = thUser('s@x.com', 'super_admin');
    $file = new UploadedFile(thZip(), 'tema.zip', 'application/zip', null, true);

    $this->actingAs($super)->post('/admin/temas', ['package' => $file])->assertRedirect('/admin/temas');
    $theme = Theme::firstOrFail();

    $this->actingAs($super)->post("/admin/temas/{$theme->id}/ativar")->assertRedirect('/admin/temas');
    expect(Setting::get('active_theme'))->toBe($theme->id);

    $this->actingAs($super)->delete("/admin/temas/{$theme->id}")->assertSessionHasErrors('theme');
    expect(Theme::count())->toBe(1);

    $this->actingAs($super)->post('/admin/temas/padrao')->assertRedirect('/admin/temas');
    expect(Setting::get('active_theme'))->toBeNull();

    $this->actingAs($super)->delete("/admin/temas/{$theme->id}")->assertRedirect('/admin/temas');
    expect(Theme::count())->toBe(0)->and(File::exists($this->dir.'/tema-teste/1.0.0'))->toBeFalse();
});

it('upload recusado mostra o motivo', function () {
    $super = thUser('s@x.com', 'super_admin');
    $file = new UploadedFile(thZip(['layouts/app.blade.php' => '<?php echo 1;']), 'tema.zip', 'application/zip', null, true);

    $this->actingAs($super)->post('/admin/temas', ['package' => $file])->assertSessionHasErrors('package');
    expect(Theme::count())->toBe(0);
});

it('o layout do tema recebe menu por perfil, marca, usuário e avisos', function () {
    $super = thUser('s@x.com', 'super_admin');
    $layout = "<html><body><nav>@foreach (\$menu as \$m)<a class=\"mi\" href=\"{{ \$m['url'] }}\">{{ \$m['label'] }}</a>@foreach (\$m['children'] as \$c)<i class=\"ch\">{{ \$c['label'] }}</i>@endforeach @endforeach</nav>"
        ."<b>{{ \$brand['name'] }}</b><u>{{ \$user->name }}</u><div id=\"flash\">{{ \$flash }}</div><div id=\"legacy\">{{ \$legacyCss[1] }}</div><main>@yield('content')</main>{{ \$legacyScripts }}</body></html>";
    $theme = app(ThemeInstaller::class)->install($super, thZip(['layouts/app.blade.php' => $layout]));
    app(ThemeManager::class)->activate($theme);

    $this->actingAs($super)->withSession(['status' => 'Salvo com sucesso'])->get('/painel')
        ->assertOk()->assertSee('Painel')->assertSee('Documentos criados')->assertSee('Usuários da plataforma')->assertSee('Temas')
        ->assertSee('P s@x.com')->assertSee('Salvo com sucesso')->assertSee('legacy-scope.css', false)->assertSee('bootstrap.bundle.min.js', false)
        ->assertSee('Total 0');

    $comum = thUser('c@x.com');
    $this->actingAs($comum)->get('/painel')->assertOk()->assertDontSee('Usuários da plataforma')->assertSee('Plano e uso');
    $membro = User::create(['tenant_id' => $comum->tenant_id, 'name' => 'Membro', 'email' => 'm@x.com', 'password' => 'senha-muito-forte', 'role' => 'member']);
    $this->actingAs($membro->fresh())->get('/painel')->assertOk()->assertDontSee('Plano e uso')->assertDontSee('Webhooks');
});

it('telas internas continuam abrindo dentro do tema ativo', function () {
    $super = thUser('s@x.com', 'super_admin');
    $theme = app(ThemeInstaller::class)->install($super, thZip(['layouts/app.blade.php' => "<html><body><main>@yield('content')</main>{{ \$legacyScripts }}</body></html>"]));
    app(ThemeManager::class)->activate($theme);

    $this->actingAs($super)->get('/admin/temas')->assertOk()->assertSee('Temas instalados');
});

it('fecha contornos comuns da revisão de segurança', function (array $override, string $needle) {
    $problems = implode(' | ', (new ThemeValidator())->validate(thZip($override)));
    expect($problems)->toContain($needle);
})->with([
    'função montada em string' => [['layouts/app.blade.php' => "{{ ('str'.'rev')('abc') }}"], 'chamada dinâmica'],
    'variável como função' => [['layouts/app.blade.php' => "@if(\$f('abc')) x @endif"], 'chamada dinâmica'],
    'comentário antes do parêntese' => [['layouts/app.blade.php' => '{{ config/**/(\'app.name\') }}'], 'comentário'],
    'backtick' => [['layouts/app.blade.php' => '{{ `id` }}'], 'backtick'],
    'container interno' => [['layouts/app.blade.php' => '{{ get_class($__env->getContainer()) }}'], '$__env'],
    'new' => [['layouts/app.blade.php' => '{{ (new \ArrayObject([1]))->count() }}'], 'new'],
    'include dinâmico' => [['layouts/app.blade.php' => '@include($v)'], '@include'],
    'extends dinâmico' => [['pages/dashboard.blade.php' => '@extends($v)'], '@extends'],
    'componente dinâmico' => [['layouts/app.blade.php' => '<x-dynamic-component :component="$c" />'], '<x-'],
    'slug com quebra de linha' => [['theme.json' => json_encode(['name' => 'X', 'slug' => "t\n", 'version' => '1.0.0', 'contract' => 1])], 'slug'],
    'versão com quebra de linha' => [['theme.json' => json_encode(['name' => 'X', 'slug' => 't', 'version' => "1.0.0\n", 'contract' => 1])], 'versão'],
    'slug como lista' => [['theme.json' => json_encode(['name' => 'X', 'slug' => ['a'], 'version' => '1.0.0', 'contract' => 1])], 'slug'],
    'nome como lista' => [['theme.json' => json_encode(['name' => ['a'], 'slug' => 't', 'version' => '1.0.0', 'contract' => 1])], 'name'],
    'nome de arquivo com dois-pontos' => [['assets/a.php:x.css' => 'a{}'], 'a.php:x.css'],
    'nome reservado do Windows' => [['assets/con.css' => 'a{}'], 'con.css'],
    'php dentro de css' => [['assets/x.css' => '<?php echo 1;'], '<?'],
]);

it('limita o número de arquivos do pacote', function () {
    config(['themes.max_files' => 3]);
    expect(implode(' ', (new ThemeValidator())->validate(thZip(['assets/a.css' => 'a{}', 'assets/b.css' => 'b{}']))))->toContain('arquivos');
});

it('os temas do sistema continuam passando no validador', function (string $slug) {
    \Illuminate\Support\Facades\Artisan::call('themes:pack', ['slug' => $slug, '--out' => sys_get_temp_dir().'/bundled-'.$slug.'.zip']);
    expect((new ThemeValidator())->validate(sys_get_temp_dir().'/bundled-'.$slug.'.zip'))->toBe([]);
})->with(['able', 'w3crm']);

it('o item Documentos do menu aponta para Documentos criados', function () {
    $u = thUser('menu-doc@example.com');
    $this->actingAs($u)->get('/painel');
    $docs = collect((new \App\Themes\MenuBuilder())->for($u))->firstWhere('label', 'Documentos');
    expect($docs['url'])->toBe(route('envelopes.index'))->and(route('envelopes.index', [], false))->toBe('/documentos');
});

it('atualizar o tema do sistema troca o ativo para a versão nova e tira a antiga', function () {
    $dir = $this->dir.'/able/0.9.0';
    File::ensureDirectoryExists($dir);
    $old = Theme::create(['slug' => 'able', 'name' => 'Able Pro', 'version' => '0.9.0', 'contract' => 1, 'status' => 'installed', 'bundled' => true]);
    Setting::put('active_theme', $old->id);

    $this->artisan('themes:install-bundled')->assertExitCode(0);

    $new = Theme::query()->where('slug', 'able')->where('bundled', true)->first();
    expect(Theme::query()->where('slug', 'able')->count())->toBe(1)
        ->and($new->version)->not->toBe('0.9.0')
        ->and(Setting::get('active_theme'))->toBe($new->id)
        ->and(File::exists($dir))->toBeFalse();
});
