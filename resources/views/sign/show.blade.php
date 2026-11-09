@extends('layouts.public')
@section('title', $envelope->title)
@section('content')
<div class="mb-3">
    <h4 class="mb-1">{{ $envelope->title }}</h4>
    <p class="text-secondary mb-0">Olá, <strong>{{ $signer->name }}</strong>. Para assinar: 1) leia o documento, 2) conclua as etapas abaixo, 3) assine.</p>
</div>

<ol class="steps-wizard" aria-label="Etapas">
    @foreach ($steps as $i => $s)
        <li class="{{ $s['done'] ? 'done' : ($s['key'] === $current ? 'current' : '') }}">
            @if ($s['done'])<i class="bx bx-check"></i>@else{{ $i + 1 }}@endif · {{ $s['label'] }}
        </li>
    @endforeach
</ol>

@if ($envelope->message)<div class="alert alert-secondary border-0">{{ $envelope->message }}</div>@endif

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-file me-1"></i> Documento</h6></div>
    <div class="card-body">
        <iframe class="pdf" src="{{ route('sign.pdf', $token) }}" title="Documento"></iframe>
        <p class="text-secondary small mt-2 mb-0">Não consegue ver? <a href="{{ route('sign.pdf', $token) }}" target="_blank" rel="noopener">Abrir em nova aba</a></p>
    </div>
</div>

@if ($waiting)
<div class="alert alert-info border-0 d-flex align-items-center gap-2"><i class="bx bx-time-five fs-4"></i><div>Este documento tem ordem de assinatura. Você será avisado quando for a sua vez; até lá, pode apenas ler o documento.</div></div>

@elseif ($current === 'otp')
<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-envelope me-1"></i> Confirme sua identidade</h6></div>
    <div class="card-body">
        <p>Enviaremos um código de 6 dígitos para o seu e-mail.</p>
        <form method="post" action="{{ route('sign.otp', $token) }}" class="mb-3">@csrf <button class="btn btn-outline-primary">Enviar código</button></form>
        <form method="post" action="{{ route('sign.otp.verify', $token) }}">
            @csrf
            <label for="code" class="form-label">Código recebido</label>
            <input id="code" type="text" class="form-control form-control-lg text-center mb-3" style="max-width:240px;letter-spacing:.4em" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
            <button class="btn btn-primary">Confirmar código</button>
        </form>
    </div>
</div>

@elseif ($current === 'selfie')
<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-camera me-1"></i> Tire uma selfie</h6></div>
    <div class="card-body">
        <p class="text-secondary">Posicione seu rosto no centro, com boa iluminação e sem óculos escuros ou boné.</p>
        <video class="cam" id="cam" autoplay playsinline muted hidden></video>
        <img class="shot" id="shot" alt="Sua selfie" hidden>
        <canvas id="cv" hidden></canvas>
        <div id="camerr" class="alert alert-warning border-0 mt-2" hidden>Não foi possível usar a câmera. Permita o acesso no navegador ou envie uma foto abaixo.</div>
        <form method="post" action="{{ route('sign.selfie', $token) }}" id="selfieform" class="mt-3">
            @csrf
            <input type="hidden" name="image" id="image">
            <input type="hidden" name="source" id="source" value="camera">
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" id="open"><i class="bx bx-camera"></i> Abrir câmera</button>
                <button type="button" class="btn btn-primary" id="snap" hidden><i class="bx bx-aperture"></i> Tirar foto</button>
                <button type="button" class="btn btn-outline-secondary" id="retake" hidden>Tirar outra</button>
                <button class="btn btn-success" id="send" hidden><i class="bx bx-check"></i> Usar esta foto</button>
            </div>
            <details id="fallback" class="mt-3" hidden><summary>Enviar uma foto do aparelho</summary>
                <input type="file" class="form-control mt-2" accept="image/*" capture="user" id="file">
            </details>
        </form>
    </div>
</div>
@push('scripts')
<script>
(function () {
    const $ = id => document.getElementById(id);
    const video = $('cam'), shot = $('shot'), cv = $('cv'), image = $('image'), source = $('source');
    let stream = null;
    const show = (el, on) => el.hidden = !on;
    function stop() { if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } }
    function done(dataUrl, src) { image.value = dataUrl; source.value = src; shot.src = dataUrl; show(shot, true); show(video, false); show($('snap'), false); show($('retake'), true); show($('send'), true); stop(); }
    $('open').addEventListener('click', async function () {
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1280 } }, audio: false });
            video.srcObject = stream; show(video, true); show($('open'), false); show($('snap'), true);
        } catch (e) { show($('camerr'), true); show($('fallback'), true); }
    });
    $('snap').addEventListener('click', function () {
        const w = video.videoWidth, h = video.videoHeight, s = Math.min(1, 960 / Math.max(w, h));
        cv.width = Math.round(w * s); cv.height = Math.round(h * s);
        cv.getContext('2d').drawImage(video, 0, 0, cv.width, cv.height);
        done(cv.toDataURL('image/jpeg', 0.85), 'camera');
    });
    $('retake').addEventListener('click', function () { show(shot, false); show($('retake'), false); show($('send'), false); show($('open'), true); image.value = ''; });
    $('file').addEventListener('change', function (e) {
        const f = e.target.files[0]; if (!f) return;
        const r = new FileReader(); r.onload = () => done(r.result, 'upload'); r.readAsDataURL(f);
    });
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { show($('open'), false); show($('camerr'), true); show($('fallback'), true); }
})();
</script>
@endpush

@elseif ($current === 'document')
<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-id-card me-1"></i> Foto do documento</h6></div>
    <div class="card-body">
        <p class="text-secondary">Fotografe a frente do seu RG ou CNH, em local iluminado, com todos os dados legíveis e sem cortes.</p>
        <form method="post" action="{{ route('sign.document', $token) }}" enctype="multipart/form-data">
            @csrf
            <input type="file" class="form-control" name="image" accept="image/*" capture="environment" required id="docfile">
            <img class="shot mt-3" id="docprev" alt="Prévia" hidden>
            <button class="btn btn-primary mt-3">Enviar documento</button>
        </form>
    </div>
</div>
@push('scripts')
<script>
document.getElementById('docfile').addEventListener('change', function (e) {
    const f = e.target.files[0]; if (!f) return;
    const p = document.getElementById('docprev'); p.src = URL.createObjectURL(f); p.hidden = false;
});
</script>
@endpush

@elseif ($current === 'geolocation')
<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-map-pin me-1"></i> Compartilhe sua localização</h6></div>
    <div class="card-body">
        <p class="text-secondary">Registramos sua localização aproximada como parte da prova de assinatura. Ela não é compartilhada com terceiros.</p>
        <div id="georr" class="alert alert-warning border-0" hidden>Não foi possível obter a localização. Permita o acesso no navegador e tente novamente.</div>
        <form method="post" action="{{ route('sign.geolocation', $token) }}" id="geoform">
            @csrf
            <input type="hidden" name="lat" id="lat"><input type="hidden" name="lng" id="lng"><input type="hidden" name="accuracy" id="acc">
            <button type="button" class="btn btn-primary" id="geobtn"><i class="bx bx-current-location"></i> Permitir localização</button>
        </form>
    </div>
</div>
@push('scripts')
<script>
document.getElementById('geobtn').addEventListener('click', function () {
    const err = document.getElementById('georr');
    if (!navigator.geolocation) { err.hidden = false; return; }
    navigator.geolocation.getCurrentPosition(function (p) {
        document.getElementById('lat').value = p.coords.latitude;
        document.getElementById('lng').value = p.coords.longitude;
        document.getElementById('acc').value = p.coords.accuracy;
        document.getElementById('geoform').submit();
    }, function () { err.hidden = false; }, { enableHighAccuracy: true, timeout: 15000 });
});
</script>
@endpush

@else
<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-pen me-1"></i> Assinar</h6></div>
    <div class="card-body">
        <form method="post" action="{{ route('sign.submit', $token) }}" id="signform">
            @csrf
            <input type="hidden" name="type" id="type" value="drawn">
            <ul class="nav nav-pills nav-fill mb-3" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" id="tab-drawn"><i class="bx bx-edit-alt"></i> Desenhar</button></li>
                <li class="nav-item"><button type="button" class="nav-link" id="tab-typed"><i class="bx bx-text"></i> Digitar</button></li>
            </ul>

            <div id="pane-drawn">
                <p class="text-secondary mt-0">Desenhe sua assinatura com o dedo ou o mouse.</p>
                <canvas class="pad" id="pad"></canvas>
                <input type="hidden" name="drawn" id="drawn">
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="clear">Limpar</button>
            </div>
            <div id="pane-typed" hidden>
                <label for="typed" class="form-label">Digite seu nome</label>
                <input type="text" class="form-control mb-3" name="typed" id="typed" value="{{ old('typed', $signer->name) }}" maxlength="150" disabled>
                <div class="sign-preview" id="preview" aria-hidden="true">{{ $signer->name }}</div>
            </div>

            <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="terms" value="1" id="terms" required>
                <label class="form-check-label" for="terms">Li o documento e concordo em assinar eletronicamente. Declaro que meus dados de acesso (IP, data e hora, dispositivo) e as verificações realizadas serão registrados como prova desta assinatura.</label>
            </div>
            <button class="btn btn-primary btn-lg w-100 mt-3" id="submit"><i class="bx bx-check-shield"></i> Assinar documento</button>
        </form>
    </div>
</div>
<div class="card radius-10">
    <div class="card-body">
        <details><summary class="text-danger">Recusar assinatura</summary>
            <form method="post" action="{{ route('sign.refuse', $token) }}" class="mt-3">
                @csrf
                <label for="reason" class="form-label">Motivo</label>
                <textarea id="reason" class="form-control" name="reason" rows="3" required></textarea>
                <button class="btn btn-sm btn-danger mt-2">Recusar</button>
            </form>
        </details>
    </div>
</div>
@push('scripts')
<script>
(function () {
    const $ = id => document.getElementById(id);
    const pad = $('pad'), ctx = pad.getContext('2d'), drawn = $('drawn'), type = $('type');
    let down = false, dirty = false, sized = false;
    function size() {
        const r = pad.getBoundingClientRect(); if (!r.width) return;
        pad.width = r.width * 2; pad.height = r.height * 2; ctx.scale(2, 2);
        ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111'; sized = true;
    }
    const pos = e => { const r = pad.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
    pad.addEventListener('pointerdown', e => { e.preventDefault(); down = true; ctx.beginPath(); ctx.moveTo(...pos(e)); pad.setPointerCapture(e.pointerId); });
    pad.addEventListener('pointermove', e => { if (!down) return; ctx.lineTo(...pos(e)); ctx.stroke(); dirty = true; });
    const end = () => { if (!down) return; down = false; drawn.value = dirty ? pad.toDataURL('image/png') : ''; };
    pad.addEventListener('pointerup', end); pad.addEventListener('pointercancel', end);
    $('clear').addEventListener('click', () => { ctx.clearRect(0, 0, pad.width, pad.height); dirty = false; drawn.value = ''; });
    function tab(t) {
        const d = t === 'drawn'; type.value = t;
        $('pane-drawn').hidden = !d; $('pane-typed').hidden = d; $('typed').disabled = d;
        $('tab-drawn').classList.toggle('active', d); $('tab-typed').classList.toggle('active', !d);
        if (d && !sized) size();
    }
    $('tab-drawn').addEventListener('click', () => tab('drawn'));
    $('tab-typed').addEventListener('click', () => tab('typed'));
    $('typed').addEventListener('input', e => $('preview').textContent = e.target.value || ' ');
    $('signform').addEventListener('submit', e => {
        if (type.value === 'drawn' && !drawn.value) { e.preventDefault(); alert('Desenhe sua assinatura ou use a aba "Digitar".'); }
    });
    size();
})();
</script>
@endpush
@endif
@endsection
