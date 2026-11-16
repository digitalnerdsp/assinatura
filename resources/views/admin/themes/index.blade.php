@extends('layouts.app')
@section('title', 'Temas')
@section('section', 'Plataforma')
@section('content')
@if (session('status'))<div class="alert alert-success border-0">{{ session('status') }}</div>@endif
@error('theme')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror
@error('package')<div class="alert alert-danger border-0"><strong>O pacote foi recusado:</strong><div class="small mt-1" style="white-space:pre-line">{{ $message }}</div></div>@enderror

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="card radius-10">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <h5 class="mb-0">Temas instalados</h5>
                    @if ($activeId)<form method="post" action="{{ route('admin.themes.reset') }}" class="ms-auto">@csrf<button class="btn btn-sm btn-outline-secondary drive-tap">Voltar ao padrão do sistema</button></form>@endif
                </div>
                <p class="text-secondary small">O tema ativo vale para toda a plataforma. Se ele falhar ao abrir, o sistema volta sozinho ao visual de reserva.</p>
                @forelse ($themes as $t)
                    <div class="d-flex flex-wrap align-items-center gap-3 border-top py-3">
                        <img src="{{ is_file($t->directory().'/assets/preview.png') ? route('theme.asset', [$t->slug, $t->version, 'assets/preview.png']) : '' }}" alt="" width="96" height="64" style="object-fit:cover;border-radius:.5rem;background:#e9ecef" @if (! is_file($t->directory().'/assets/preview.png')) hidden @endif>
                        <div class="flex-grow-1" style="min-width:12rem">
                            <div class="fw-semibold">{{ $t->name }} <span class="small text-secondary">{{ $t->version }}</span>
                                @if ($t->id === $activeId)<span class="badge bg-success ms-1">Ativo</span>@endif
                                @if ($t->status === 'error')<span class="badge bg-danger ms-1">Com erro</span>@endif
                                @if ($t->bundled)<span class="badge bg-secondary ms-1">Do sistema</span>@endif
                            </div>
                            <div class="small text-secondary">{{ $t->author ?: 'Autor não informado' }}@if ($t->description) · {{ $t->description }}@endif</div>
                            @if ($t->status === 'error' && $t->error)<div class="small text-danger mt-1">{{ $t->error }}</div>@endif
                        </div>
                        <div class="d-flex gap-2">
                            @if ($t->id !== $activeId)
                                <form method="post" action="{{ route('admin.themes.activate', $t) }}">@csrf<button class="btn btn-sm btn-primary drive-tap">Ativar</button></form>
                                @unless ($t->bundled)
                                <form method="post" action="{{ route('admin.themes.destroy', $t) }}" data-confirm="Remover o tema {{ $t->name }} {{ $t->version }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger drive-tap">Remover</button></form>
                                @endunless
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-secondary border-top pt-3 mb-0">Nenhum tema instalado. O sistema usa o visual de reserva.</p>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card radius-10">
            <div class="card-body">
                <h5>Enviar tema</h5>
                <p class="text-secondary small">Envie um pacote .zip com <code>theme.json</code>, <code>layouts/app.blade.php</code>, <code>pages/dashboard.blade.php</code> e a pasta <code>assets/</code>. O sistema confere o pacote inteiro antes de instalar e recusa código PHP, funções perigosas e arquivos fora da lista.</p>
                <form method="post" action="{{ route('admin.themes.store') }}" enctype="multipart/form-data">
                    @csrf
                    <label class="form-label" for="package">Pacote (.zip, até 25 MB)</label>
                    <input class="form-control mb-3" id="package" type="file" name="package" accept=".zip,application/zip" required>
                    <button class="btn btn-primary drive-tap"><i class="bx bx-upload"></i> Enviar e conferir</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('submit', function (e) {
    var msg = e.target.dataset.confirm;
    if (msg && !window.confirm(msg)) { e.preventDefault(); }
});
</script>
@endpush
