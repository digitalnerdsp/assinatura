@extends('layouts.app')
@section('title', 'Gerar: '.$template->name)
@section('section', 'Modelos')
@section('content')
<form method="post" action="{{ route('templates.generate', $template) }}">
    @csrf
    <div class="row">
        <div class="col-12 col-xl-7">
            <div class="card radius-10">
                <div class="card-header"><h6 class="mb-0"><i class="bx bx-file me-1"></i> Dados do documento</h6></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Título do envelope</label>
                        <input id="title" type="text" class="form-control" name="title" value="{{ old('title', $template->name) }}" required>
                    </div>
                    @foreach ($template->variables() as $v)
                        <div class="mb-3">
                            <label class="form-label" for="var_{{ $v }}">{{ $v }}</label>
                            <input id="var_{{ $v }}" type="text" class="form-control" name="vars[{{ $v }}]" value="{{ old('vars.'.$v) }}" required>
                        </div>
                    @endforeach
                    <div class="mb-3">
                        <label for="message" class="form-label">Mensagem aos signatários <span class="text-secondary">(opcional)</span></label>
                        <textarea id="message" class="form-control" name="message" rows="2">{{ old('message') }}</textarea>
                    </div>
                    <label for="signing_order" class="form-label">Ordem de assinatura</label>
                    <select id="signing_order" class="form-select" name="signing_order">
                        <option value="parallel" @selected(old('signing_order') === 'parallel')>Todos ao mesmo tempo</option>
                        <option value="sequential" @selected(old('signing_order') === 'sequential')>Um após o outro</option>
                    </select>
                </div>
            </div>
            <div class="card radius-10">
                <div class="card-header d-flex align-items-center"><h6 class="mb-0"><i class="bx bx-group me-1"></i> Signatários</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="add"><i class="bx bx-plus"></i> Adicionar</button></div>
                <div class="card-body" id="signers">
                    @php($rows = old('signers', [['name' => '', 'email' => '']]))
                    @foreach ($rows as $i => $s)
                        <div class="border rounded p-3 mb-3 signer-row"><div class="row g-2">
                            <div class="col-md-6"><label class="form-label">Nome</label><input type="text" class="form-control" name="signers[{{ $i }}][name]" value="{{ $s['name'] ?? '' }}" required></div>
                            <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" class="form-control" name="signers[{{ $i }}][email]" value="{{ $s['email'] ?? '' }}" required></div>
                            <div class="col-md-6"><label class="form-label">CPF <span class="text-secondary">(opcional)</span></label><input type="text" class="form-control" name="signers[{{ $i }}][cpf]" value="{{ $s['cpf'] ?? '' }}"></div>
                            <div class="col-md-6"><label class="form-label">Telefone <span class="text-secondary">(WhatsApp/SMS)</span></label><input type="text" class="form-control" name="signers[{{ $i }}][phone]" value="{{ $s['phone'] ?? '' }}"></div>
                        </div></div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-5">
            @include('envelopes._options')
            <button class="btn btn-primary w-100 btn-lg"><i class="bx bx-check"></i> Gerar envelope</button>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
document.getElementById('add').addEventListener('click', function () {
    const wrap = document.getElementById('signers'), i = wrap.querySelectorAll('.signer-row').length;
    const d = document.createElement('div');
    d.className = 'border rounded p-3 mb-3 signer-row';
    d.innerHTML = '<div class="row g-2">' +
        '<div class="col-md-6"><label class="form-label">Nome</label><input type="text" class="form-control" name="signers[' + i + '][name]" required></div>' +
        '<div class="col-md-6"><label class="form-label">E-mail</label><input type="email" class="form-control" name="signers[' + i + '][email]" required></div>' +
        '<div class="col-md-6"><label class="form-label">CPF <span class="text-secondary">(opcional)</span></label><input type="text" class="form-control" name="signers[' + i + '][cpf]"></div>' +
        '<div class="col-md-6"><label class="form-label">Telefone <span class="text-secondary">(WhatsApp/SMS)</span></label><input type="text" class="form-control" name="signers[' + i + '][phone]"></div></div>';
    wrap.appendChild(d);
});
</script>
@endpush
