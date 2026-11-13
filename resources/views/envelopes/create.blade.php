@extends('layouts.app')
@section('title', 'Novo envelope')
@section('section', 'Assinaturas')
@section('content')
<form method="post" action="{{ route('envelopes.store') }}" data-suggest-url="{{ route('contacts.suggest') }}" enctype="multipart/form-data" id="envelope-form">
    @csrf
    <div class="row">
        <div class="col-12 col-xl-7">
            <div class="card radius-10">
                <div class="card-header"><h6 class="mb-0"><i class="bx bx-file me-1"></i> Documento</h6></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Título</label>
                        <input id="title" type="text" class="form-control" name="title" value="{{ old('title') }}" required>
                    </div>
                    <div class="mb-3">
                        <label for="document" class="form-label">PDF (até {{ config('signing.max_pdf_mb') }} MB)</label>
                        <input id="document" type="file" class="form-control" name="document" accept="application/pdf" required>
                    </div>
                    <div class="mb-3">
                        <label for="message" class="form-label">Mensagem aos signatários <span class="text-secondary">(opcional)</span></label>
                        <textarea id="message" class="form-control" name="message" rows="3">{{ old('message') }}</textarea>
                    </div>
                    <label for="signing_order" class="form-label">Ordem de assinatura</label>
                    <select id="signing_order" class="form-select" name="signing_order">
                        <option value="parallel" @selected(old('signing_order') === 'parallel')>Todos ao mesmo tempo</option>
                        <option value="sequential" @selected(old('signing_order') === 'sequential')>Um após o outro (na ordem abaixo; cada um só é avisado na sua vez)</option>
                    </select>
                </div>
            </div>

            <div class="card radius-10">
                <div class="card-header d-flex align-items-center"><h6 class="mb-0"><i class="bx bx-group me-1"></i> Signatários</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="add"><i class="bx bx-plus"></i> Adicionar</button></div>
                <div class="card-body" id="signers">
                    @if ($senderPolicy !== 'never')
                        <div class="border rounded p-3 mb-3" id="sender-row">
                            <strong><i class="bx bx-badge-check"></i> Eu (remetente)</strong>
                            <span class="text-secondary">{{ $senderUser->name }} &lt;{{ $senderUser->email }}&gt;</span>
                            @if ($senderPolicy === 'always')
                                <input type="hidden" name="sender_signs" value="1">
                                <div class="small text-secondary mt-1">Sua assinatura é aplicada ao liberar o documento, sem selfie nem código.</div>
                            @else
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" name="sender_signs" value="1" id="sender_signs" checked>
                                    <label class="form-check-label" for="sender_signs">Eu também assino este documento</label>
                                </div>
                            @endif
                            @unless ($hasSignature)
                                <div class="alert alert-warning border-0 mt-2 mb-0">Crie a sua assinatura em <a href="{{ route('profile.edit') }}#minha-assinatura">Meu perfil</a> antes de criar o documento.</div>
                            @endunless
                            @error('sender')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                        </div>
                    @endif
                    @php($rows = old('signers', [['name' => '', 'email' => '']]))
                    @foreach ($rows as $i => $s)
                        <div class="border rounded p-3 mb-3 signer-row">
                            <div class="row g-2">
                                <div class="col-md-6"><label class="form-label">Nome</label><input type="text" class="form-control signer-name" name="signers[{{ $i }}][name]" value="{{ $s['name'] ?? '' }}" required></div>
                                <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" class="form-control" name="signers[{{ $i }}][email]" value="{{ $s['email'] ?? '' }}" required></div>
                                <div class="col-md-6"><label class="form-label">CPF <span class="text-secondary">(opcional)</span></label><input type="text" class="form-control" name="signers[{{ $i }}][cpf]" value="{{ $s['cpf'] ?? '' }}"></div>
                                <div class="col-md-6"><label class="form-label">Telefone <span class="text-secondary">(WhatsApp/SMS)</span></label><input type="text" class="form-control" name="signers[{{ $i }}][phone]" value="{{ $s['phone'] ?? '' }}" placeholder="(11) 99999-8888"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card radius-10">
                <div class="card-header d-flex align-items-center"><h6 class="mb-0"><i class="bx bx-user-voice me-1"></i> Testemunhas (opcional)</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="add-witness"><i class="bx bx-plus"></i> Adicionar testemunha</button></div>
                <div class="card-body" id="witnesses">
                    <p class="text-secondary small mb-0" id="witnesses-empty">Para contratos que exigem testemunhas. Elas assinam depois dos signatários e o documento só conclui com todas.</p>
                    @foreach (old('witnesses', []) as $i => $w)
                        <div class="border rounded p-3 mb-3 witness-row">
                            <div class="d-flex"><strong class="small">Testemunha</strong><button type="button" class="btn-close ms-auto remove-witness" aria-label="Remover testemunha"></button></div>
                            <div class="row g-2">
                                <div class="col-md-6"><label class="form-label">Nome</label><input type="text" class="form-control signer-name witness-name" name="witnesses[{{ $i }}][name]" value="{{ $w['name'] ?? '' }}" required></div>
                                <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" class="form-control" name="witnesses[{{ $i }}][email]" value="{{ $w['email'] ?? '' }}" required></div>
                                <div class="col-md-6"><label class="form-label">CPF <span class="text-secondary">(opcional)</span></label><input type="text" class="form-control" name="witnesses[{{ $i }}][cpf]" value="{{ $w['cpf'] ?? '' }}"></div>
                                <div class="col-md-6"><label class="form-label">Telefone <span class="text-secondary">(WhatsApp/SMS)</span></label><input type="text" class="form-control" name="witnesses[{{ $i }}][phone]" value="{{ $w['phone'] ?? '' }}"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card radius-10" id="placement-card">
                <div class="card-header"><h6 class="mb-0"><i class="bx bx-pointer me-1"></i> Posição da assinatura no PDF <span class="text-secondary fw-normal">(opcional)</span></h6></div>
                <div class="card-body">
                    <p class="text-secondary mb-3">Escolha o PDF, selecione o signatário e clique na página onde a assinatura dele deve aparecer. Sem posições, a assinatura fica apenas na página de relatório ao final.</p>
                    <div id="placement-empty" class="text-secondary small">Selecione um PDF para posicionar as assinaturas.</div>
                    <div id="placement" class="d-none">
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                            <select id="place-signer" class="form-select form-select-sm w-auto"></select>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="prev-page"><i class="bx bx-chevron-left"></i></button>
                            <span class="small" id="page-label">Página 1</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="next-page"><i class="bx bx-chevron-right"></i></button>
                            <span class="small text-secondary ms-auto" id="place-count"></span>
                        </div>
                        <div id="page-wrap" style="position:relative;display:inline-block;max-width:100%;border:1px solid var(--bs-border-color);cursor:crosshair">
                            <canvas id="page-canvas" style="display:block;max-width:100%;height:auto"></canvas>
                            <div id="markers" style="position:absolute;inset:0;pointer-events:none"></div>
                        </div>
                    </div>
                    <div id="fields"></div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            @include('envelopes._options')
            <button class="btn btn-primary w-100 btn-lg"><i class="bx bx-check"></i> Criar envelope</button>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/contact-suggest.js') }}?v=1"></script>
<script>
function signerRow(i) {
    return '<div class="row g-2">' +
        '<div class="col-md-6"><label class="form-label">Nome</label><input type="text" class="form-control signer-name" name="signers[' + i + '][name]" required></div>' +
        '<div class="col-md-6"><label class="form-label">E-mail</label><input type="email" class="form-control" name="signers[' + i + '][email]" required></div>' +
        '<div class="col-md-6"><label class="form-label">CPF <span class="text-secondary">(opcional)</span></label><input type="text" class="form-control" name="signers[' + i + '][cpf]"></div>' +
        '<div class="col-md-6"><label class="form-label">Telefone <span class="text-secondary">(WhatsApp/SMS)</span></label><input type="text" class="form-control" name="signers[' + i + '][phone]" placeholder="(11) 99999-8888"></div></div>';
}
document.getElementById('add').addEventListener('click', function () {
    const wrap = document.getElementById('signers'), i = wrap.querySelectorAll('.signer-row').length;
    const d = document.createElement('div');
    d.className = 'border rounded p-3 mb-3 signer-row';
    d.innerHTML = signerRow(i);
    wrap.appendChild(d);
    refreshSigners();
});

// Testemunhas: mesmo padrão, com remover. Os campos mudam de índice ao remover, então os índices das
// posições no PDF (signer = índice na lista signatários + testemunhas) são ajustados junto.
function witnessRow(i) {
    return '<div class="d-flex"><strong class="small">Testemunha</strong><button type="button" class="btn-close ms-auto remove-witness" aria-label="Remover testemunha"></button></div>' +
        '<div class="row g-2">' +
        '<div class="col-md-6"><label class="form-label">Nome</label><input type="text" class="form-control signer-name witness-name" name="witnesses[' + i + '][name]" required></div>' +
        '<div class="col-md-6"><label class="form-label">E-mail</label><input type="email" class="form-control" name="witnesses[' + i + '][email]" required></div>' +
        '<div class="col-md-6"><label class="form-label">CPF <span class="text-secondary">(opcional)</span></label><input type="text" class="form-control" name="witnesses[' + i + '][cpf]"></div>' +
        '<div class="col-md-6"><label class="form-label">Telefone <span class="text-secondary">(WhatsApp/SMS)</span></label><input type="text" class="form-control" name="witnesses[' + i + '][phone]"></div></div>';
}
function renumberWitnesses() {
    document.querySelectorAll('#witnesses .witness-row').forEach(function (row, i) {
        row.querySelectorAll('input[name^="witnesses["]').forEach(function (inp) {
            inp.name = inp.name.replace(/^witnesses\[\d+\]/, 'witnesses[' + i + ']');
        });
    });
    var empty = document.getElementById('witnesses-empty');
    if (empty) empty.style.display = document.querySelectorAll('#witnesses .witness-row').length ? 'none' : '';
}
document.getElementById('add-witness').addEventListener('click', function () {
    const wrap = document.getElementById('witnesses'), i = wrap.querySelectorAll('.witness-row').length;
    const d = document.createElement('div');
    d.className = 'border rounded p-3 mb-3 witness-row';
    d.innerHTML = witnessRow(i);
    wrap.appendChild(d);
    renumberWitnesses();
    refreshSigners();
});
document.getElementById('witnesses').addEventListener('click', function (e) {
    const btn = e.target.closest('.remove-witness');
    if (!btn) return;
    const all = Array.from(document.querySelectorAll('.signer-name')), row = btn.closest('.witness-row');
    const g = all.indexOf(row.querySelector('.signer-name'));
    row.remove();
    renumberWitnesses();
    // descarta as posições da testemunha removida e desloca as das seguintes
    if (typeof fields !== 'undefined') {
        fields = fields.filter(function (f) { return f.signer !== g; }).map(function (f) { return (f.signer !== 'sender' && f.signer > g) ? Object.assign({}, f, { signer: f.signer - 1 }) : f; });
    }
    refreshSigners();
});
document.addEventListener('DOMContentLoaded', renumberWitnesses);
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
// Posicionamento das assinaturas: cada clique cria um campo {signer, page, x, y} (frações da página, origem no topo-esquerda).
const BOX_W = 0.28, BOX_H = 0.07;
let pdfDoc = null, page = 1, fields = [];
const wrap = document.getElementById('page-wrap'), canvas = document.getElementById('page-canvas');

function signerNames() {
    let witnessNo = 0;
    return Array.from(document.querySelectorAll('.signer-name')).map((el, i) => {
        const w = el.classList.contains('witness-name');
        if (w) witnessNo++;
        return (w ? 'Testemunha: ' : '') + (el.value.trim() || (w ? 'Testemunha ' + witnessNo : 'Signatário ' + (i + 1)));
    });
}
function senderOn() { // o remetente assina neste documento? (linha fixa ou caixa marcada)
    if (!document.getElementById('sender-row')) return false;
    const cb = document.getElementById('sender_signs');
    return cb ? cb.checked : true;
}
function refreshSigners() {
    const sel = document.getElementById('place-signer'), keep = sel.value;
    sel.innerHTML = (senderOn() ? '<option value="sender">Eu (remetente)</option>' : '') +
        signerNames().map((n, i) => '<option value="' + i + '">' + n.replace(/</g, '&lt;') + '</option>').join('');
    if (keep !== '') sel.value = keep;
    drawMarkers();
}
document.getElementById('signers').addEventListener('input', refreshSigners);
document.getElementById('witnesses').addEventListener('input', refreshSigners);
if (document.getElementById('sender_signs')) document.getElementById('sender_signs').addEventListener('change', refreshSigners);

function drawMarkers() {
    const names = signerNames(), box = document.getElementById('markers'), hidden = document.getElementById('fields');
    box.innerHTML = ''; hidden.innerHTML = '';
    fields = fields.filter(f => f.signer === 'sender' ? senderOn() : f.signer < names.length);
    fields.forEach((f, idx) => {
        hidden.insertAdjacentHTML('beforeend',
            '<input type="hidden" name="signature_fields[' + idx + '][signer]" value="' + f.signer + '">' +
            '<input type="hidden" name="signature_fields[' + idx + '][page]" value="' + f.page + '">' +
            '<input type="hidden" name="signature_fields[' + idx + '][x]" value="' + f.x.toFixed(5) + '">' +
            '<input type="hidden" name="signature_fields[' + idx + '][y]" value="' + f.y.toFixed(5) + '">');
        if (f.page !== page) return;
        const m = document.createElement('div');
        m.style.cssText = 'position:absolute;pointer-events:auto;cursor:pointer;border:2px dashed #0d6efd;background:rgba(13,110,253,.12);font-size:11px;color:#0d6efd;overflow:hidden;padding:2px 4px;' +
            'left:' + (f.x * 100) + '%;top:' + (f.y * 100) + '%;width:' + (BOX_W * 100) + '%;height:' + (BOX_H * 100) + '%';
        m.title = 'Clique para remover';
        m.textContent = (f.signer === 'sender' ? 'Eu (remetente)' : names[f.signer]) + ' ✕';
        m.addEventListener('click', e => { e.stopPropagation(); fields.splice(idx, 1); drawMarkers(); });
        box.appendChild(m);
    });
    document.getElementById('place-count').textContent = fields.length ? fields.length + ' posição(ões) marcada(s)' : '';
}

let renderChain = Promise.resolve();
function renderPage() { // uma renderização por vez (o canvas não aceita duas ao mesmo tempo)
    renderChain = renderChain.then(doRender).catch(() => {});
    return renderChain;
}
async function doRender() {
    const p = await pdfDoc.getPage(page);
    const base = p.getViewport({ scale: 1 }), scale = Math.min(1.4, 640 / base.width);
    const vp = p.getViewport({ scale });
    canvas.width = vp.width; canvas.height = vp.height;
    await p.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    document.getElementById('page-label').textContent = 'Página ' + page + ' de ' + pdfDoc.numPages;
    drawMarkers();
}

wrap.addEventListener('click', e => {
    const r = canvas.getBoundingClientRect();
    if (!pdfDoc || !r.width || !r.height) return; // página ainda carregando
    const x = Math.min(Math.max((e.clientX - r.left) / r.width, 0), 1 - BOX_W);
    const y = Math.min(Math.max((e.clientY - r.top) / r.height, 0), 1 - BOX_H);
    const pick = document.getElementById('place-signer').value;
    fields.push({ signer: pick === 'sender' ? 'sender' : parseInt(pick || '0', 10), page: page, x: x, y: y });
    drawMarkers();
});
document.getElementById('prev-page').addEventListener('click', () => { if (pdfDoc && page > 1) { page--; renderPage(); } });
document.getElementById('next-page').addEventListener('click', () => { if (pdfDoc && page < pdfDoc.numPages) { page++; renderPage(); } });

document.getElementById('document').addEventListener('change', async function () {
    fields = []; page = 1; pdfDoc = null;
    document.getElementById('placement').classList.add('d-none');
    document.getElementById('placement-empty').classList.remove('d-none');
    drawMarkers();
    const file = this.files[0];
    if (!file || typeof pdfjsLib === 'undefined') return;
    try {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        pdfDoc = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
        document.getElementById('placement').classList.remove('d-none');
        document.getElementById('placement-empty').classList.add('d-none');
        refreshSigners();
        await renderPage();
    } catch (err) {
        document.getElementById('placement-empty').textContent = 'Não foi possível exibir a prévia do PDF aqui; a assinatura ficará na página de relatório.';
    }
});
refreshSigners();
</script>
@endpush
