@extends('layouts.app')
@section('title', $envelope->title)
@section('width', 'sign')
@section('content')
<style>
.steps{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px;padding:0;list-style:none}
.steps li{flex:1 1 120px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:.9rem;background:var(--card);color:var(--muted)}
.steps li.done{border-color:#0f8a4b;color:#0a5c32;background:#d8f3e4}
.steps li.current{border-color:var(--brand);color:var(--ink);font-weight:700;box-shadow:0 0 0 2px var(--brand) inset}
.tabs{display:flex;gap:8px;margin-bottom:12px}
.tabs button{flex:1;padding:10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--ink);font:inherit;cursor:pointer}
.tabs button[aria-selected=true]{border-color:var(--brand);box-shadow:0 0 0 2px var(--brand) inset;font-weight:700}
video.cam,img.shot{width:100%;max-height:60vh;border-radius:8px;background:#000;object-fit:cover}
.preview{font:italic 2.2rem/1.2 "Brush Script MT","Segoe Script","Snell Roundhand",cursive;padding:14px;border:1px dashed var(--muted);border-radius:8px;background:#fff;color:#111;min-height:70px;word-break:break-word}
</style>

<h1>{{ $envelope->title }}</h1>
<p class="muted">Olá, <strong>{{ $signer->name }}</strong>. Para assinar: 1) leia o documento, 2) conclua as etapas abaixo, 3) assine.</p>

<ol class="steps" aria-label="Etapas">
    @foreach ($steps as $i => $s)
        <li class="{{ $s['done'] ? 'done' : ($s['key'] === $current ? 'current' : '') }}">
            {{ $s['done'] ? '✓' : $i + 1 }} · {{ $s['label'] }}
        </li>
    @endforeach
</ol>

@if ($envelope->message)<div class="card">{{ $envelope->message }}</div>@endif

<div class="card">
    <h2>Documento</h2>
    <iframe class="pdf" src="{{ route('sign.pdf', $token) }}" title="Documento"></iframe>
    <p class="muted">Não consegue ver? <a href="{{ route('sign.pdf', $token) }}" target="_blank" rel="noopener">Abrir em nova aba</a></p>
</div>

@if ($current === 'otp')
<div class="card">
    <h2>Confirme sua identidade</h2>
    <p>Enviaremos um código de 6 dígitos para o seu e-mail.</p>
    <form method="post" action="{{ route('sign.otp', $token) }}">@csrf <button class="btn ghost">Enviar código</button></form>
    <form method="post" action="{{ route('sign.otp.verify', $token) }}" style="margin-top:12px">
        @csrf
        <label for="code">Código recebido</label>
        <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
        <p><button class="btn">Confirmar código</button></p>
    </form>
</div>

@elseif ($current === 'selfie')
<div class="card">
    <h2>Tire uma selfie</h2>
    <p class="muted">Posicione seu rosto no centro, com boa iluminação e sem óculos escuros ou boné.</p>
    <video class="cam" id="cam" autoplay playsinline muted hidden></video>
    <img class="shot" id="shot" alt="Sua selfie" hidden>
    <canvas id="cv" hidden></canvas>
    <p id="camerr" class="err" hidden>Não foi possível usar a câmera. Permita o acesso no navegador ou envie uma foto abaixo.</p>
    <form method="post" action="{{ route('sign.selfie', $token) }}" id="selfieform">
        @csrf
        <input type="hidden" name="image" id="image">
        <input type="hidden" name="source" id="source" value="camera">
        <p>
            <button type="button" class="btn" id="open">Abrir câmera</button>
            <button type="button" class="btn" id="snap" hidden>Tirar foto</button>
            <button type="button" class="btn ghost" id="retake" hidden>Tirar outra</button>
            <button class="btn" id="send" hidden>Usar esta foto</button>
        </p>
        <details id="fallback" hidden><summary>Enviar uma foto do aparelho</summary>
            <input type="file" accept="image/*" capture="user" id="file">
        </details>
    </form>
</div>
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

@elseif ($current === 'document')
<div class="card">
    <h2>Foto do documento</h2>
    <p class="muted">Fotografe a frente do seu RG ou CNH, em local iluminado, com todos os dados legíveis e sem cortes.</p>
    <form method="post" action="{{ route('sign.document', $token) }}" enctype="multipart/form-data">
        @csrf
        <input type="file" name="image" accept="image/*" capture="environment" required id="docfile">
        <img class="shot" id="docprev" alt="Prévia" hidden style="margin-top:12px">
        <p><button class="btn">Enviar documento</button></p>
    </form>
</div>
<script>
document.getElementById('docfile').addEventListener('change', function (e) {
    const f = e.target.files[0]; if (!f) return;
    const p = document.getElementById('docprev'); p.src = URL.createObjectURL(f); p.hidden = false;
});
</script>

@elseif ($current === 'geolocation')
<div class="card">
    <h2>Compartilhe sua localização</h2>
    <p class="muted">Registramos sua localização aproximada como parte da prova de assinatura. Ela não é compartilhada com terceiros.</p>
    <p id="georr" class="err" hidden>Não foi possível obter a localização. Permita o acesso no navegador e tente novamente.</p>
    <form method="post" action="{{ route('sign.geolocation', $token) }}" id="geoform">
        @csrf
        <input type="hidden" name="lat" id="lat"><input type="hidden" name="lng" id="lng"><input type="hidden" name="accuracy" id="acc">
        <button type="button" class="btn" id="geobtn">Permitir localização</button>
    </form>
</div>
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

@else
<div class="card">
    <h2>Assinar</h2>
    <form method="post" action="{{ route('sign.submit', $token) }}" id="signform">
        @csrf
        <input type="hidden" name="type" id="type" value="drawn">
        <div class="tabs" role="tablist">
            <button type="button" role="tab" id="tab-drawn" aria-selected="true">✍️ Desenhar</button>
            <button type="button" role="tab" id="tab-typed" aria-selected="false">⌨️ Digitar</button>
        </div>

        <div id="pane-drawn">
            <p class="muted" style="margin-top:0">Desenhe sua assinatura com o dedo ou o mouse.</p>
            <canvas class="pad" id="pad"></canvas>
            <input type="hidden" name="drawn" id="drawn">
            <button type="button" class="btn ghost sm" id="clear">Limpar</button>
        </div>
        <div id="pane-typed" hidden>
            <label for="typed">Digite seu nome</label>
            <input type="text" name="typed" id="typed" value="{{ old('typed', $signer->name) }}" maxlength="150" disabled>
            <div class="preview" id="preview" aria-hidden="true">{{ $signer->name }}</div>
        </div>

        <label style="margin-top:16px;font-weight:400"><input type="checkbox" name="terms" value="1" required>
            Li o documento e concordo em assinar eletronicamente. Declaro que meus dados de acesso (IP, data e hora, dispositivo) e as
            verificações realizadas serão registrados como prova desta assinatura.</label>
        <p><button class="btn" id="submit">Assinar documento</button></p>
    </form>
</div>
<div class="card">
    <details><summary>Recusar assinatura</summary>
        <form method="post" action="{{ route('sign.refuse', $token) }}">
            @csrf
            <label for="reason">Motivo</label>
            <textarea id="reason" name="reason" rows="3" required></textarea>
            <p><button class="btn danger sm">Recusar</button></p>
        </form>
    </details>
</div>
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
        $('tab-drawn').setAttribute('aria-selected', d); $('tab-typed').setAttribute('aria-selected', !d);
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
@endif
@endsection
