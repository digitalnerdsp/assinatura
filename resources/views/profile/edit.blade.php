@extends('layouts.app')
@section('title', 'Meu perfil')
@section('section', 'Perfil')

@section('content')
<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-3">Meu perfil</h5>

                <form method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data">
                    @csrf @method('PUT')

                    <div class="d-flex align-items-center gap-3 mb-4">
                        <img src="{{ $user->avatarUrl() }}" alt="Sua foto" class="rounded-circle" style="width:96px;height:96px;object-fit:cover">
                        <div class="flex-grow-1">
                            <label class="form-label" for="photo">Foto de perfil</label>
                            <input type="file" name="photo" id="photo" class="form-control @error('photo') is-invalid @enderror" accept="image/jpeg,image/png,image/webp">
                            <div class="form-text">JPG, PNG ou WebP, até 5 MB. A imagem é recortada em quadrado.</div>
                            @error('photo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="name">Nome</label>
                        <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required maxlength="120">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input type="text" class="form-control" value="{{ $user->email }}" disabled>
                    </div>

                    <button class="btn btn-primary">Salvar</button>
                </form>

                @if ($user->avatar_path)
                    <form method="post" action="{{ route('profile.photo.destroy') }}" class="mt-3" onsubmit="return confirm('Remover a foto de perfil?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger btn-sm">Remover foto</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card" id="minha-assinatura">
            <div class="card-body">
                <h5 class="card-title mb-3">Minha assinatura</h5>
                <p class="text-secondary">Usada quando você assina como remetente, ao liberar um documento. Crie uma vez e ela é reaproveitada sempre.</p>

                @if ($user->hasSignature())
                    <div class="mb-3 p-3 border rounded bg-white text-center">
                        @if ($user->signature_type === 'drawn')
                            <img src="{{ $user->signature_data }}" alt="Sua assinatura" style="max-width:100%;max-height:120px">
                        @else
                            <span style="font:italic 2rem/1.2 'Brush Script MT','Segoe Script','Snell Roundhand',cursive;color:#111">{{ $user->signature_data }}</span>
                        @endif
                    </div>
                @else
                    <div class="alert alert-warning border-0">Você ainda não tem uma assinatura salva. Crie abaixo para poder enviar documentos como remetente.</div>
                @endif

                <form method="post" action="{{ route('profile.signature.update') }}" id="sigform">
                    @csrf @method('PUT')
                    <input type="hidden" name="type" id="sig-type" value="drawn">
                    <input type="hidden" name="data" id="sig-data">
                    <ul class="nav nav-pills mb-2">
                        <li class="nav-item"><button type="button" class="nav-link active" id="sig-tab-drawn">Desenhar</button></li>
                        <li class="nav-item"><button type="button" class="nav-link" id="sig-tab-typed">Digitar</button></li>
                    </ul>
                    <div id="sig-pane-drawn">
                        <canvas class="pad" id="sig-pad"></canvas>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="sig-clear">Limpar</button>
                    </div>
                    <div id="sig-pane-typed" hidden>
                        <input type="text" class="form-control" id="sig-typed" maxlength="80" placeholder="Seu nome como assinatura">
                        <div class="sign-preview mt-2" id="sig-preview">&nbsp;</div>
                    </div>
                    @error('data')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    <button class="btn btn-primary mt-3">Salvar assinatura</button>
                </form>

                @if ($user->hasSignature())
                    <form method="post" action="{{ route('profile.signature.destroy') }}" class="mt-3" onsubmit="return confirm('Remover a sua assinatura salva?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger btn-sm">Remover assinatura</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const $ = id => document.getElementById(id);
    const pad = $('sig-pad'), ctx = pad.getContext('2d'), data = $('sig-data'), type = $('sig-type');
    let down = false, dirty = false;
    function size() {
        const r = pad.getBoundingClientRect(); if (!r.width) return;
        pad.width = r.width * 2; pad.height = r.height * 2; ctx.scale(2, 2);
        ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
    }
    const pos = e => { const r = pad.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
    pad.addEventListener('pointerdown', e => { e.preventDefault(); down = true; ctx.beginPath(); ctx.moveTo(...pos(e)); pad.setPointerCapture(e.pointerId); });
    pad.addEventListener('pointermove', e => { if (!down) return; ctx.lineTo(...pos(e)); ctx.stroke(); dirty = true; });
    const end = () => { if (!down) return; down = false; if (type.value === 'drawn') data.value = dirty ? pad.toDataURL('image/png') : ''; };
    pad.addEventListener('pointerup', end); pad.addEventListener('pointercancel', end);
    $('sig-clear').addEventListener('click', () => { ctx.clearRect(0, 0, pad.width, pad.height); dirty = false; data.value = ''; });
    function tab(t) {
        const d = t === 'drawn'; type.value = t;
        $('sig-pane-drawn').hidden = !d; $('sig-pane-typed').hidden = d;
        $('sig-tab-drawn').classList.toggle('active', d); $('sig-tab-typed').classList.toggle('active', !d);
        data.value = d ? (dirty ? pad.toDataURL('image/png') : '') : $('sig-typed').value;
        if (d) size();
    }
    $('sig-tab-drawn').addEventListener('click', () => tab('drawn'));
    $('sig-tab-typed').addEventListener('click', () => tab('typed'));
    $('sig-typed').addEventListener('input', e => { data.value = e.target.value; $('sig-preview').textContent = e.target.value || ' '; });
    $('sigform').addEventListener('submit', e => {
        if (!data.value) { e.preventDefault(); alert('Desenhe a assinatura ou use a aba "Digitar".'); }
    });
    size();
})();
</script>
@endpush
