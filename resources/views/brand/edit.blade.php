@extends('layouts.app')
@section('title', 'Marca própria')
@section('section', 'Configurações')
@section('content')
@if (session('warning'))<div class="alert alert-warning border-0">{{ session('warning') }}</div>@endif
@php
    $primary = old('brand_primary', $tenant->brand_primary ?? '#0b63c7');
    $accent = old('brand_accent', $tenant->brand_accent ?? '#0b3d91');
    $name = old('brand_name', $tenant->brand_name);
@endphp
<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card radius-10">
            <div class="card-body">
                <h5 class="mb-1">Marca própria</h5>
                <p class="text-secondary small">Seu logo, suas cores e o nome da sua empresa aparecem no painel, na página onde o signatário assina e nos e-mails de convite, lembrete e código. Domínio próprio e remetente de e-mail próprio ficam para uma próxima versão.</p>

                @if (! $enabled)
                    <div class="alert alert-secondary border-0 mb-0">
                        <strong>O seu plano atual não inclui marca própria.</strong> Esse recurso pode vir incluso no plano ou como pacote extra. Fale com o suporte para liberar.
                    </div>
                @else
                    <form method="post" action="{{ route('brand.update') }}" enctype="multipart/form-data" id="brand-form" class="row g-3">
                        @csrf @method('PUT')
                        <div class="col-12">
                            <label class="form-label" for="b-name">Nome exibido (no lugar de "{{ config('app.name') }}")</label>
                            <input class="form-control @error('brand_name') is-invalid @enderror" id="b-name" name="brand_name" maxlength="60" value="{{ $name }}" placeholder="{{ config('app.name') }}">
                            @error('brand_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label" for="b-primary">Cor principal (botões)</label>
                            <div class="d-flex gap-2"><input type="color" class="form-control form-control-color drive-tap" id="b-primary-picker" value="{{ $primary }}" aria-label="Escolher cor principal"><input class="form-control @error('brand_primary') is-invalid @enderror" id="b-primary" name="brand_primary" maxlength="7" value="{{ old('brand_primary', $tenant->brand_primary) }}" placeholder="#0b63c7"></div>
                            @error('brand_primary')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label" for="b-accent">Cor de destaque (links)</label>
                            <div class="d-flex gap-2"><input type="color" class="form-control form-control-color drive-tap" id="b-accent-picker" value="{{ $accent }}" aria-label="Escolher cor de destaque"><input class="form-control @error('brand_accent') is-invalid @enderror" id="b-accent" name="brand_accent" maxlength="7" value="{{ old('brand_accent', $tenant->brand_accent) }}" placeholder="#0b3d91"></div>
                            @error('brand_accent')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="b-logo">Logo (PNG, JPG ou WebP, até 2 MB)</label>
                            <input type="file" class="form-control @error('logo') is-invalid @enderror" id="b-logo" name="logo" accept="image/png,image/jpeg,image/webp">
                            @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">O logo é reduzido para caber em 400 × 160 px. Cores com contraste baixo (menos de 4,5:1) são trocadas pelo tom mais próximo que passa, e avisamos você.</div>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button class="btn btn-primary drive-tap">Salvar marca</button>
                        </div>
                    </form>
                    @if ($brand['active'])
                        <form method="post" action="{{ route('brand.reset') }}" class="mt-2" data-confirm="Voltar à marca padrão? O logo enviado será apagado.">@csrf @method('DELETE')<button class="btn btn-outline-danger drive-tap">Voltar ao padrão</button></form>
                    @endif
                @endif
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card radius-10">
            <div class="card-body">
                <h6 class="mb-2">Prévia</h6>
                <div class="border rounded p-3" id="brand-preview">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        @if ($brand['logo_url'])<img id="pv-logo" src="{{ $brand['logo_url'] }}" alt="Logo" style="max-height:40px;max-width:160px">@else<span class="brand-badge" id="pv-badge"><i class="bx bx-pen"></i></span>@endif
                        <strong class="fs-5" id="pv-name">{{ $name ?: config('app.name') }}</strong>
                    </div>
                    <p class="mb-2">Olá, Ana. Para assinar, leia o documento e <a href="javascript:;" id="pv-link">abra o contrato</a>.</p>
                    <button type="button" class="btn btn-primary" id="pv-btn" tabindex="-1">Assinar documento</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    document.addEventListener('submit', function (e) { var m = e.target.dataset.confirm; if (m && !window.confirm(m)) e.preventDefault(); });
    var f = document.getElementById('brand-form'); if (!f) return;
    var name = document.getElementById('b-name'), p = document.getElementById('b-primary'), a = document.getElementById('b-accent');
    var pp = document.getElementById('b-primary-picker'), ap = document.getElementById('b-accent-picker');
    var pvName = document.getElementById('pv-name'), pvBtn = document.getElementById('pv-btn'), pvLink = document.getElementById('pv-link');
    function lum(h) { var c = [1, 3, 5].map(function (i) { var v = parseInt(h.substr(i, 2), 16) / 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }); return .2126 * c[0] + .7152 * c[1] + .0722 * c[2]; }
    function ok(h) { return (1.05) / (lum(h) + .05) >= 4.5; }
    function valid(h) { return /^#[0-9a-fA-F]{6}$/.test(h); }
    function paint() {
        pvName.textContent = name.value || @json(config('app.name'));
        if (valid(p.value) && ok(p.value)) { pvBtn.style.background = p.value; pvBtn.style.borderColor = p.value; } else { pvBtn.style.background = ''; pvBtn.style.borderColor = ''; }
        pvLink.style.color = (valid(a.value) && ok(a.value)) ? a.value : '';
    }
    [name, p, a].forEach(function (el) { el.addEventListener('input', paint); });
    pp.addEventListener('input', function () { p.value = pp.value; paint(); });
    ap.addEventListener('input', function () { a.value = ap.value; paint(); });
    paint();
})();
</script>
@endpush
