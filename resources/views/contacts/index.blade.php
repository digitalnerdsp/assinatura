@extends('layouts.app')
@section('title', 'Contatos')
@section('section', 'Contatos')
@section('actions')<button type="button" class="btn btn-primary drive-tap" data-contact-new><i class="bx bx-user-plus"></i> Novo contato</button>@endsection
@section('content')
@php($popper = '{"strategy":"fixed"}')
<div class="card radius-10">
    <div class="card-body">
        <form method="get" action="{{ route('contacts.index') }}" class="row g-2 mb-3">
            <div class="col-12 col-md-6"><input type="search" class="form-control" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Buscar por nome, e-mail, empresa ou telefone" aria-label="Buscar contatos"></div>
            <div class="col-8 col-md-3">
                <select class="form-select" name="tag" aria-label="Etiqueta">
                    <option value="">Todas as etiquetas</option>
                    @foreach ($tags as $t)<option value="{{ $t->id }}" @selected(($filters['tag'] ?? '') === $t->id)>{{ $t->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-4 col-md-auto d-flex gap-2">
                <button class="btn btn-outline-primary drive-tap"><i class="bx bx-search"></i> Filtrar</button>
                @if (array_filter($filters))<a class="btn btn-outline-secondary drive-tap" href="{{ route('contacts.index') }}">Limpar</a>@endif
            </div>
        </form>

        @if ($contacts->isEmpty())
            <div class="text-center py-5 text-secondary">
                <i class="bx bx-id-card fs-1"></i>
                <p class="mt-2">{{ array_filter($filters) ? 'Nenhum contato encontrado com esses filtros.' : 'Nenhum contato ainda. Eles aparecem sozinhos quando você envia documentos, ou use Novo contato.' }}</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0 drive-docs">
                    <thead class="table-light"><tr><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Empresa</th><th>Etiquetas</th><th aria-label="Ações"></th></tr></thead>
                    <tbody>
                    @foreach ($contacts as $c)
                        <tr>
                            <td data-label="Nome" class="fw-semibold">{{ $c->name }}</td>
                            <td data-label="E-mail">{{ $c->email }}</td>
                            <td data-label="Telefone">{{ $c->phone ?? '—' }}</td>
                            <td data-label="Empresa">{{ $c->company ?? '—' }}</td>
                            <td data-label="Etiquetas">@forelse ($c->tags as $t)<span class="badge bg-{{ $t->color }} me-1">{{ $t->name }}</span>@empty — @endforelse</td>
                            <td data-label="Ações" class="text-end">
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-secondary drive-tap border-0" data-bs-toggle="dropdown" data-bs-popper-config='{{ $popper }}' aria-expanded="false" aria-label="Ações do contato {{ $c->name }}"><i class="bx bx-dots-vertical-rounded"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><button type="button" class="dropdown-item drive-tap justify-content-start" data-contact-edit
                                            data-id="{{ $c->id }}" data-name="{{ $c->name }}" data-email="{{ $c->email }}" data-phone="{{ $c->phone }}"
                                            data-document="{{ $c->document }}" data-company="{{ $c->company }}" data-notes="{{ $c->notes }}"
                                            data-tags='@json($c->tags->pluck('id')->values())'><i class="bx bx-edit me-2"></i>Editar</button></li>
                                        <li><form method="post" action="{{ route('contacts.destroy', $c) }}" data-confirm="Apagar o contato {{ $c->name }}? Os documentos já enviados não mudam.">@csrf @method('DELETE')<button class="dropdown-item drive-tap justify-content-start text-danger"><i class="bx bx-trash me-2"></i>Apagar</button></form></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $contacts->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>

<div class="modal fade" id="modal-contact" tabindex="-1" aria-labelledby="modal-contact-title" aria-hidden="true"
     data-url-store="{{ route('contacts.store') }}" data-url-update="{{ route('contacts.update', '__ID__') }}">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <form class="modal-content" method="post">
            @csrf
            <input type="hidden" name="_method" value="PUT" disabled>
            <div class="modal-header"><h5 class="modal-title" id="modal-contact-title">Novo contato</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-12"><label class="form-label" for="c-name">Nome</label><input class="form-control" id="c-name" name="name" maxlength="150" required></div>
                    <div class="col-12"><label class="form-label" for="c-email">E-mail</label><input type="email" class="form-control" id="c-email" name="email" maxlength="190" required></div>
                    <div class="col-md-6"><label class="form-label" for="c-phone">Telefone</label><input class="form-control" id="c-phone" name="phone" maxlength="30" placeholder="(11) 99999-8888"></div>
                    <div class="col-md-6"><label class="form-label" for="c-document">CPF ou CNPJ</label><input class="form-control" id="c-document" name="document" maxlength="18"></div>
                    <div class="col-12"><label class="form-label" for="c-company">Empresa</label><input class="form-control" id="c-company" name="company" maxlength="150"></div>
                    <div class="col-12"><label class="form-label" for="c-notes">Observação</label><textarea class="form-control" id="c-notes" name="notes" rows="2" maxlength="1000"></textarea></div>
                    @if ($tags->isNotEmpty())
                        <div class="col-12"><div class="form-label">Etiquetas</div>
                            @foreach ($tags as $t)<label class="d-inline-flex align-items-center gap-2 me-2 drive-tap"><input class="form-check-input mt-0" type="checkbox" name="tags[]" value="{{ $t->id }}"><span class="badge bg-{{ $t->color }}">{{ $t->name }}</span></label>@endforeach
                        </div>
                    @endif
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary drive-tap" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary drive-tap">Salvar</button></div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var modal = document.getElementById('modal-contact'), form = modal.querySelector('form');
    var fields = ['name', 'email', 'phone', 'document', 'company', 'notes'];
    function open(data, id) {
        var editing = !!id;
        form.action = editing ? modal.dataset.urlUpdate.replace('__ID__', encodeURIComponent(id)) : modal.dataset.urlStore;
        form.querySelector('input[name=_method]').disabled = !editing;
        fields.forEach(function (f) { form.elements[f].value = data[f] || ''; });
        var tags = [];
        try { tags = JSON.parse(data.tags || '[]'); } catch (e) { tags = []; }
        form.querySelectorAll('input[name="tags[]"]').forEach(function (cb) { cb.checked = tags.indexOf(cb.value) !== -1; });
        modal.querySelector('.modal-title').textContent = editing ? 'Editar contato' : 'Novo contato';
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-contact-new]')) { e.preventDefault(); open({}, null); }
        var edit = e.target.closest('[data-contact-edit]');
        if (edit) { e.preventDefault(); open(edit.dataset, edit.dataset.id); }
    });
    document.addEventListener('submit', function (e) {
        var msg = e.target.dataset.confirm;
        if (msg && !window.confirm(msg)) e.preventDefault();
    });
    modal.addEventListener('shown.bs.modal', function () { form.elements.name.focus(); });
})();
</script>
@endpush
