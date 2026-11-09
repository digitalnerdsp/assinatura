@extends('layouts.app')
@section('title', $envelope->title)
@section('width', 'sign')
@section('content')
<h1>{{ $envelope->title }}</h1>
<p class="muted">Olá, {{ $signer->name }}. Leia o documento abaixo e assine ao final.</p>
@if ($envelope->message)<div class="card">{{ $envelope->message }}</div>@endif

<div class="card">
    <iframe class="pdf" src="{{ route('sign.pdf', $token) }}" title="Documento"></iframe>
    <p class="muted">Não consegue ver? <a href="{{ route('sign.pdf', $token) }}" target="_blank" rel="noopener">Abrir em nova aba</a></p>
</div>

@if ($needsOtp && ! $signer->otp_verified_at)
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
@else
<div class="card">
    <h2>Assinar</h2>
    <form method="post" action="{{ route('sign.submit', $token) }}" id="signform">
        @csrf
        <label><input type="radio" name="type" value="typed" checked> Digitar meu nome</label>
        <input type="text" name="typed" id="typed" value="{{ old('typed', $signer->name) }}" maxlength="150">
        <label style="margin-top:14px"><input type="radio" name="type" value="drawn"> Desenhar minha assinatura</label>
        <canvas class="pad" id="pad" hidden></canvas>
        <input type="hidden" name="drawn" id="drawn">
        <button type="button" class="btn ghost sm" id="clear" hidden>Limpar</button>
        <label style="margin-top:16px;font-weight:400"><input type="checkbox" name="terms" value="1" required>
            Li o documento e concordo em assinar eletronicamente. Declaro que meus dados de acesso (IP, data e hora, dispositivo)
            serão registrados como prova desta assinatura.</label>
        <p><button class="btn">Assinar documento</button></p>
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
    const pad = document.getElementById('pad'), clear = document.getElementById('clear'), drawn = document.getElementById('drawn');
    const radios = document.querySelectorAll('input[name=type]');
    const ctx = pad.getContext('2d');
    let down = false, dirty = false;
    function size() { const r = pad.getBoundingClientRect(); pad.width = r.width * 2; pad.height = r.height * 2; ctx.scale(2, 2); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111'; }
    function pos(e) { const r = pad.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; }
    pad.addEventListener('pointerdown', e => { down = true; ctx.beginPath(); ctx.moveTo(...pos(e)); pad.setPointerCapture(e.pointerId); });
    pad.addEventListener('pointermove', e => { if (!down) return; ctx.lineTo(...pos(e)); ctx.stroke(); dirty = true; });
    pad.addEventListener('pointerup', () => { down = false; drawn.value = dirty ? pad.toDataURL('image/png') : ''; });
    clear.addEventListener('click', () => { ctx.clearRect(0, 0, pad.width, pad.height); dirty = false; drawn.value = ''; });
    radios.forEach(r => r.addEventListener('change', () => {
        const d = document.querySelector('input[name=type]:checked').value === 'drawn';
        pad.hidden = clear.hidden = !d; document.getElementById('typed').disabled = d;
        if (d) size();
    }));
})();
</script>
@endif
@endsection
