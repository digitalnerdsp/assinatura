@if (session('invite_link'))
    <div class="alert alert-info border-0">
        <div class="fw-bold mb-1">Link do convite</div>
        <p class="small mb-2">Se o e-mail não chegar (confira o spam), copie este link e envie por outro meio. Ele só aparece agora e vale 7 dias, uma única vez.</p>
        <div class="input-group">
            <input type="text" class="form-control" id="invite-link-field" value="{{ session('invite_link') }}" readonly aria-label="Link do convite">
            <button type="button" class="btn btn-primary drive-tap" id="invite-link-copy">Copiar link</button>
        </div>
    </div>
    <script>
    (function () {
        var f = document.getElementById('invite-link-field'), b = document.getElementById('invite-link-copy');
        b.addEventListener('click', function () {
            f.select();
            (navigator.clipboard ? navigator.clipboard.writeText(f.value) : Promise.reject()).catch(function () { document.execCommand('copy'); }).finally(function () { b.textContent = 'Copiado!'; });
        });
    })();
    </script>
@endif
